<?php

namespace App\Services;

use App\Classes\ResponseHandler;
use App\Helpers\PaginationHelper;
use App\Models\Form1ApplicationNo;
use Exception;
use Illuminate\Http\Request;
use App\Models\SiteInspection;
use App\Models\InspectionDocument;
use Illuminate\Support\Facades\DB;
use App\Models\SiteInspection_subordinates;
use Illuminate\Support\Facades\Storage;

class SiteInspectionService extends BaseFormService
{
    private const SITE_INSPECTION_STATUSES = [6, 7, 8, 9];

    protected $applicationNo;
    protected $applicationId;
    protected $siteInspection;
    protected $siteInspectionId;
    protected $userId;
    protected $now;


    public function index(Request $request)
    {
        $query = $this->getSiteInspectionBaseQuery($request);

        $paginator  =  $this->paginate($query, $request);

        $result =  $this->format($paginator);

        return ResponseHandler::success($result, "Data fetched");
    }

    public function show(Request $request, $uuid)
    {
        $query = $this->getSiteInspectionBaseQuery($request);

        $application = $this->getApplication($uuid);

        if (!$application) {
            return ResponseHandler::notFound("No Application found!");
        }

        $this->applicationNo = $application->application_no;

        $this->applicationId = $application->id;

        $this->siteInspection = $this->getSiteInspection();

        $this->siteInspectionId = $this->siteInspection?->id;

        $query->with([
            'applicant:id,application_no,pan,pan_verify,salutation,applicant_first_name,applicant_middle_name,applicant_last_name,applicant_other_than_owner,applicant_mobile_no,applicant_email_id,nma_form1_application_no_id,source_platform,present_plot_survey_no,present_ref_state_id,present_ref_district_id,present_taluk,present_locality,ref_applicant_type_id,same_as_present_address,permanent_ref_state_id,permanent_ref_district_id,permanent_plot_survey_no,permanent_taluk,permanent_locality,ref_applicant_type_detail_id,authorized_person_name',

        ]);

        $result = $query->where('uuid', $uuid)->first();

        $response = $this->normalize($result);

        $response['site_inspection'] = $this->getSiteInspection();

        $response['uploads'] = $this->getInspectionDocument();

        $response['declaration'] = $this->getDeclaration();

        return ResponseHandler::success($response, "Data saved/updated !");
    }


    public function update(Request $request, $uuid)
    {

        $inputs =  $request->all();

        $application = $this->getApplication($uuid);


        if (!$application) {
            return ResponseHandler::notFound("No Application found!");
        }

        $this->applicationNo = $application->application_no;

        $this->applicationId = $application->id;

        $this->siteInspection = $this->getSiteInspection();

        $this->siteInspectionId = $this->siteInspection?->id;

        $this->userId = userId();

        $this->now = now();

        $saveOrder = [
            'inspection',
            'upload',
            'declaration',
        ];

        $data = [];


        foreach ($saveOrder as $section) {

            if (empty($inputs[$section])) {
                continue;
            }

            switch ($section) {

                case 'inspection':
                    $data[$section] = $this->saveSiteInspection($inputs[$section]);
                    break;

                case 'upload':
                    $data[$section] = $this->saveUploads($inputs[$section]);
                    break;

                case 'declaration':
                    $data[$section] = $this->saveDeclaration($inputs[$section]);
                    break;
            }
        }

        return ResponseHandler::success($data, "Data saved/updated !");
    }

    public function saveSiteInspection($payload = [])
    {

        $insertArr = [
            "nma_cpm_history_id" => $payload['nma_cpm_history_id'],
            "monument_height" => $payload['monument_height'],
            "proposed_construction" => $payload['proposed_construction'],
            "distance" => $payload['distance'],
            "north_near_monument" => $payload['north_near_monument'],
            "north_near_proposed" => $payload['north_near_proposed'],
            "south_near_monument" => $payload['south_near_monument'],
            "south_near_proposed" => $payload['south_near_proposed'],
            "west_near_monument" => $payload['west_near_monument'],
            "west_near_proposed" => $payload['west_near_proposed'],
            "east_near_monument" => $payload['east_near_monument'],
            "east_near_proposed" => $payload['east_near_proposed'],
            "ref_site_id" => $payload['ref_site_id'],
            "remarks" => $payload['remarks']
        ];

        $insertArr['application_no'] = $this->applicationNo;

        $insertArr['nma_form1_application_no_id'] = $this->applicationId;

        $insertArr['ref_non_single_window_status_id'] = 6;

        if ($this->siteInspection) {

            $insertArr['updated_by'] = $this->userId;

            $insertArr['updated_on'] = $this->now;

            $this->siteInspection->forceFill($insertArr);

            $this->siteInspection->save();
        } else {

            $insertArr['created_by'] = $this->userId;

            $insertArr['created_on'] = $this->now;

            $this->siteInspection = SiteInspection::create($insertArr);
        }

        $this->siteInspectionId = $this->siteInspection->id;

        return  $this->getSiteInspection();
    }

    public function saveUploads(array $payload = [])
    {
        try {

            $documents = $this->commonService->getDocuments(DOCUMENT_SITE_INSPECTION);

            $insertArr = [];

            $folders =  $this->getSiteInspectionDocPath($this->siteInspection);

            foreach ($documents as $docs) {

                $files = $payload[$docs['field_name']] ?? [];

                foreach ($files as $file) {

                    if (empty($file)) {
                        continue;
                    }

                    $filePath = $folders . '/' . $file;

                    if (!Storage::disk('public')->exists($filePath)) {
                        abort(422, "File '{$file}' does not exist.");
                    }

                    $insertArr[] = [
                        'nma_site_inspection_id' => $this->siteInspectionId,
                        'ref_document_type_id'   => $docs['id'],
                        'document_path'          => $file,
                        'created_by'             => $this->userId,
                        'created_on'             => $this->now,
                    ];
                }
            }

            InspectionDocument::where(
                'nma_site_inspection_id',
                $this->siteInspectionId
            )->delete();

            if ($insertArr) {
                InspectionDocument::insert($insertArr);
            }

            return $this->getInspectionDocument();
        } catch (Exception $ex) {
            throw $ex;
        }
    }

    public function saveDeclaration(array $payloads = [])
    {
        DB::transaction(function () use ($payloads) {

            $insertData = [];

            foreach ($payloads['officials'] as $record) {

                $payload = [
                    'nma_site_inspection_id' => $this->siteInspectionId,

                    'name' => $record['name'] ?? null,

                    'designation' => $record['designation'] ?? null,

                    'department' => $record['department'] ?? null,

                    'is_active' => 1,

                    'application_no' => $this->applicationNo,

                    'nma_form1_application_no_id' => $this->applicationId,
                ];

                if (!empty($record['id'])) {

                    $subOrdinates = SiteInspection_subordinates::where('id',  $record['id'])->where(
                        'nma_site_inspection_id',
                        $this->siteInspectionId
                    )->first();

                    if ($subOrdinates) {

                        $payload['updated_by'] = $this->userId;

                        $payload['updated_on'] = $this->now;

                        $subOrdinates->forceFill($payload);

                        $subOrdinates->save();
                    }
                } else {

                    $payload['created_by'] = $this->userId;

                    $payload['created_on'] = $this->now;

                    $insertData[] = $payload;
                }
            }

            if (!empty($insertData)) {

                SiteInspection_subordinates::insert($insertData);
            }
        });

        return $this->getDeclaration();
    }

    public function getSiteInspection()
    {
        $siteInspection =  SiteInspection::where(
            'nma_form1_application_no_id',
            $this->applicationId
        )->first();

        return $this->normalize($siteInspection);
    }

    public function getInspectionDocument()
    {
        $response = [];

        $savedDocs = InspectionDocument::where(
            'nma_site_inspection_id',
            $this->siteInspectionId
        )->get()->groupBy('ref_document_type_id');

        if (empty($savedDocs)) {
            return [];
        }

        $documents = $this->commonService->getDocuments(DOCUMENT_SITE_INSPECTION);

        foreach ($documents as $doc) {

            $field = $doc['field_name'];

            $docId = $doc['id'];

            $files = [];

            if (isset($savedDocs[$docId])) {

                foreach ($savedDocs[$docId] as $saved) {

                    $files[] = [
                        'id' => $saved->id,
                        'ref_document_type_id' => $saved->ref_document_type_id,

                        'file_name' => $saved->document_path,
                        'url' => $saved->document_path, // url('uploads/' . $saved->document_path),
                    ];
                }
            }

            $response[$field] = $files;
        }

        return $response;
    }

    public function getDeclaration()
    {

        $subOrdinates = SiteInspection_subordinates::where(
            'nma_form1_application_no_id',
            $this->applicationId
        )->get();

        return $subOrdinates->map(function ($item) {
            return [ 
                'id' => $item->id,
                'name' => $item->name,
                'designation' => $item->designation,
                'department' => $item->department,
                // 'nma_site_inspection_id' => $item->nma_site_inspection_id,
                // 'application_no' => $item->application_no,
                // 'nma_form1_application_no_id' => $item->nma_form1_application_no_id,
            ];
        });
    }


    // private function baseQuery()
    // {
    //     $districtIds = getAccessibleDistrictIds();



    //     return Form1ApplicationNo::with([
    //         'property:id,application_no,ref_nature_work_proposed_id,ref_state_id,ref_district_id,plot_survey_no,locality',
    //         'property.district:id,district_name',
    //         'property.state:id,state_name,state_short_name',
    //         'property.nature_work_proposed:id,nature_of_work_name',
    //         'monoment:id,application_no,locality,nma_cpm_history_id',
    //         'monoment.nma_cpm_history:id,monument_name',
    //         'status:id,status,type',

    //     ])->whereHas('property', function ($query) use ($districtIds) {
    //         $query->whereIn('ref_district_id', $districtIds);
    //     })->where('is_deleted', 0)->latest();
    // }





    private static function formatData($item): array
    {
        $location = null;

        $applicant_name  = null;

        if ($item->applicant) {

            $applicant  = $item->applicant->first();

            $applicant_name = $applicant->applicant_first_name . ' ' . $applicant->applicant_middle_name . ' ' . $applicant->applicant_last_name;
        }

        $assigned_to  = '-';

        if ($item->assigned_to) {
            $assigned_to =  implode(' ', array_filter([
                $item->assigned_to->first_name ?? null,
                $item->assigned_to->middle_name ?? null,
                $item->assigned_to->last_name ?? null,

            ]));
        }

        if ($item->property) {
            $location = implode(', ', array_filter([
                $item->property->plot_survey_no ?? null,
                $item->property->district->district_name ?? null,
                $item->property->state->state_name ?? null,
                $item->property->taluk ?? null,
                $item->property->locality ?? null,
            ]));

            $nature_of_work = $item->property?->nature_work_proposed?->nature_of_work_name ?? null;
        }


        $status = $item->status?->display_status;

        $data = [
            'id' => $item->id,
            'uuid' => $item->uuid,
            'applicant_name' => $applicant_name,
            'application_no' => $item->application_no,
            "department" => $item?->ulb_user?->department ?? 'NON-SINGLE',
            'monument_name'     => $item->monoment?->nma_cpm_history?->monument_name,
            'locality' => $item->monoment?->locality,
            'status' =>  $status ? $status : $item->status?->status . ' ( ' . $item->status?->type . ' )',
            'location' => $location,
            'nature_of_work' => $nature_of_work,
            'assigned_to' => $assigned_to
        ];

        return $data;
    }

    private function format($paginator)
    {
        $paginator->getCollection()->transform(
            fn($item) => self::formatData($item)
        );

        return PaginationHelper::format($paginator);
    }

    public function upload(Request $request, $uuid)
    {
        try {

            $application = $this->getApplication($uuid);

            if (!$application) {
                return ResponseHandler::notFound("No Application found!");
            }

            if (!$request->hasFile('file') && empty($request->allFiles())) {
                return ResponseHandler::error("No file uploaded.", 422);
            }

            $this->applicationNo = $application->application_no;

            $this->applicationId = $application->id;

            $siteInspection = $this->getSiteInspection();

            $folders =  $this->getSiteInspectionDocPath($siteInspection);

            $result = FileManager::upload($request, $folders);

            return ResponseHandler::success($result, 'File uploaded');
        } catch (Exception $ex) {

            throw $ex;
        }
    }
}

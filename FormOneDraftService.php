<?php

namespace App\Services;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use App\Classes\ResponseHandler;
use App\Models\Form1AdditionalDraftDetails;
use App\Models\Form1ApplicantDraftDetails;
use App\Models\Form1BuildingDraftDetails;
use App\Models\Form1BuildingFloorDraftDetails;
use App\Models\Form1Draft;
use App\Models\Form1MonumentDraftDetails;
use App\Models\Form1MultipleUploadDraftDetails;
use App\Models\Form1OwnerDraftDetails;
use App\Models\Form1PropertyDraftDetails;
use App\Models\Form1UploadDraftDetails;
use App\Helpers\ValidationHelper;
use Illuminate\Support\Facades\Storage;

class FormOneDraftService  extends BaseFormService
{
    private $draftno = "";
    private $userId = null;
    private $now = null;
    private $uuid = null;
    protected $referenceColumn = 'draft_no';
    protected $referenceNo;

    public function __construct(
        CommonService $commonService,
        public FormOneService $formOneService
    ) {
        parent::__construct($commonService);
    }

    public function index(Request $request)
    {
        try {

            $this->userId = userId();

            $tab = $request->tab ?? 'draft';

            if ($tab === 'draft') {

                return ResponseHandler::success(
                    $this->getDraftApplications($request),
                    'Draft data fetched!'
                );
            }

            if ($tab === 'application') {

                return ResponseHandler::success(
                    $this->getSubmittedApplications($request),
                    'Application data fetched!'
                );
            }
        } catch (Exception $ex) {

            throw $ex;
        }
    }


    public function store(Request $request)
    {
        try {



            DB::beginTransaction();

            $inputs = $request->all();

            if (!empty($inputs['uuid'])) {
                $draft = $this->getFormOneDraft($inputs['uuid']);
            } else {
                $draft = $this->saveFormOneDraft(1);
            }

            if (!$draft) {
                return ResponseHandler::error('Draft not found or could not be created.', 404);
            }

            $this->draftno = $draft->draft_no;

            $this->referenceNo =  $this->draftno;

            $this->userId = userId();

            $this->now = now();

            $this->uuid =  $draft->uuid;

            $data = $this->saveDraft($request->all());

            $data['uuid'] =  $draft->uuid;

            DB::commit();

            return ResponseHandler::created($data, 'Data saved successfully!');
        } catch (Exception $ex) {
            DB::rollBack();
            throw $ex;
        }
    }

    public function update(Request $request, $uuid)
    {
        try {

            $draft = $this->getFormOneDraft($uuid);

            if (!$draft) {
                return ResponseHandler::error("Invalid Draft No.", 422);
            }

            $this->userId = userId();

            $this->now = now();

            $this->draftno =  $this->referenceNo = $draft->draft_no;

            $data = $this->saveDraft($request->all());

            return ResponseHandler::success($data, 'Data updated successfully!');
        } catch (Exception $ex) {

            throw $ex;
        }
    }
    public function upload(Request $request, $uuid)
    {
        try {

            $draft = $this->getFormOneDraft($uuid);

            if (!$draft) {
                return ResponseHandler::error("Invalid Draft No.", 422);
            }

            if (!$request->hasFile('file') && empty($request->allFiles())) {
                return ResponseHandler::error("No file uploaded.", 422);
            }

            $folders =  $this->getDraftDocPath($draft);

            $result = FileManager::upload($request, $folders);

            return ResponseHandler::success($result, 'File uploaded');
        } catch (Exception $ex) {

            throw $ex;
        }
    }


    public function final(Request $request, $uuid)
    {
        try {

            $draft = $this->getFormOneDraft($uuid);

            if (!$draft) {
                return ResponseHandler::error("Invalid Draft No.", 422);
            }

            $this->userId = userId();

            $this->now = now();

            $this->draftno =  $this->referenceNo = $draft->draft_no;

            $draftData = $this->getDraftData();

            foreach ($draftData as $key => $value) {
                if (empty($value)) {
                    return ResponseHandler::error(
                        ucfirst(str_replace('_', ' ', $key)) . ' is not filled yet.',
                        422
                    );
                }
            }

            $data =  $this->formOneService->save($draftData, $draft);

            $this->delete();

            return ResponseHandler::success($data, 'Form submitted successfully!');
        } catch (Exception $ex) {

            throw $ex;
        }
    }

    public function delete()
    {
        DB::transaction(function () {

            Form1BuildingFloorDraftDetails::where('draft_no', $this->draftno)->delete();
            Form1UploadDraftDetails::where('draft_no', $this->draftno)->delete();

            Form1ApplicantDraftDetails::where('draft_no', $this->draftno)->delete();
            Form1OwnerDraftDetails::where('draft_no', $this->draftno)->delete();
            Form1PropertyDraftDetails::where('draft_no', $this->draftno)->delete();
            Form1BuildingDraftDetails::where('draft_no', $this->draftno)->delete();
            Form1MonumentDraftDetails::where('draft_no', $this->draftno)->delete();
            Form1AdditionalDraftDetails::where('draft_no', $this->draftno)->delete();


            Form1Draft::where('draft_no', $this->draftno)->delete();
        });
    }


    public function show(Request $request, $uuid)
    {
        try {

            $draft = $this->getFormOneDraft($uuid);

            if (!$draft) {
                return ResponseHandler::error("Invalid Draft No.", 422);
            }

            $this->draftno =  $this->referenceNo = $draft->draft_no;

            $data = $this->getDraftData();

            $data['uuid'] = $draft->uuid;

            return ResponseHandler::success($data, 'Data fetched!');
        } catch (Exception $ex) {

            throw $ex;
        }
    }


    private function getDraftData()
    {
        return [
            'applicant' => $this->getApplicant(),
            'owner' => $this->getOwner(),
            'property' => $this->getProperty(),
            'monument'  => $this->getMonument(),
            'additional_info' => $this->getAdditionalInformation(),
            'documents' => $this->getDocument(),
        ];
    }


    public function getApplicant()
    {
        try {

            $data = $this->getCollection(Form1ApplicantDraftDetails::class, $this->referenceColumn);

            return $this->hideFields($data, self::HIDDEN_FIELDS)->toArray();
        } catch (Exception $ex) {
            throw $ex;
        }
    }
    public function getOwner()
    {

        try {

            $data = $this->getCollection(Form1OwnerDraftDetails::class, $this->referenceColumn);

            return $this->hideFields($data, self::HIDDEN_FIELDS)->toArray();
        } catch (Exception $ex) {
            throw $ex;
        }
    }
    public function getProperty()
    {

        try {

            $property = $this->getSingle(Form1PropertyDraftDetails::class, $this->referenceColumn);

            if (!$property) {
                return [];
            }

            $property = $this->hideFields($property, self::HIDDEN_FIELDS);

            if ($property->structure_type == 1) {

                $property['buildings'] = $this->getBuilding();
            } else {

                $property['buildings'] = [];
            }

            return $property->toArray();
        } catch (Exception $ex) {
            throw $ex;
        }
    }
    public function getMonument()
    {

        try {

            $data = $this->getSingle(Form1MonumentDraftDetails::class, $this->referenceColumn);


            return $data ? $this->hideFields($data, self::HIDDEN_FIELDS)->toArray() : [];
        } catch (Exception $ex) {
            throw $ex;
        }
    }
    public function getAdditionalInformation()
    {

        try {

            $data = $this->getSingle(Form1AdditionalDraftDetails::class, $this->referenceColumn);

            return $data ? $this->hideFields($data, self::HIDDEN_FIELDS)->toArray() : [];
        } catch (Exception $ex) {
            throw new Exception($ex->getMessage());
        }
    }
    public function getDocument()
    {
        try {

            $documents = $this->commonService->getDocuments();

            if (empty($documents)) {
                return [];
            }

            $savedDocs = Form1UploadDraftDetails::where('draft_no', $this->draftno)
                ->where('is_deleted', 0)
                ->get()
                ->groupBy('ref_document_type_id');

            $response = [];

            if ($savedDocs->isEmpty()) {
                return [];
            }

            $draft = $this->getFormOneDraft($this->draftno);

            $folders =  $this->getDraftDocPath($draft);

            $response['terms_conditions_accepted'] = 1;

            $response['path'] = $folders;

            foreach ($documents as $doc) {

                $field = $doc['field_name'];

                $docId = $doc['id'];

                $files = [];

                if (isset($savedDocs[$docId])) {

                    foreach ($savedDocs[$docId] as $saved) {

                        $files[] = [
                            'id' => $saved->id,
                            'ref_document_type_id' => $saved->ref_document_type_id,
                            'gpa_spa_applicant_id' => $saved->gpa_spa_applicant_id,
                            'file_name' => $saved->document_path,
                            'url' => $saved->document_path, // url('uploads/' . $saved->document_path),
                        ];
                    }
                }

                $response[$field] = $files;
            }



            return $response;
        } catch (Exception $ex) {

            throw $ex;
        }
    }

    public function getBuilding()
    {

        try {

            $buildingResponses = Form1BuildingDraftDetails::with('floors')
                ->where('draft_no', $this->draftno)
                ->get();

            $data = $buildingResponses->map(function ($building) {

                $buildingData = $this->hideFields($building, array_merge(self::HIDDEN_FIELDS, ["is_active", "is_migrated", "is_submitted", "source_platform"]));

                $buildingData['floors'] = $this->hideFields(
                    $building->floors,
                    array_merge(
                        self::HIDDEN_FIELDS,
                        [
                            "is_active",
                            "is_migrated",
                            "is_submitted",
                            "source_platform",
                        ]
                    )
                );

                return $buildingData->toArray();
            });

            return $data->toArray();
        } catch (Exception $ex) {
            throw $ex;
        }
    }



    private function saveDraft($inputs)
    {
        $data = [];

        if (isset($inputs['uuid'])) {
            unset($inputs['uuid'], $inputs['isDraft']);
        }

        $saveOrder = [
            'applicant',
            'owner',
            'property',
            'monument',
            'additional_info',
            'documents',
        ];



        $data = [
            "self_declaration" => getSelfDeclaration()
        ];

        foreach ($saveOrder as $section) {

            if (empty($inputs[$section])) {
                continue;
            }

            switch ($section) {

                case 'applicant':

                    $applicantData = $this->saveDraftApplicant($inputs);

                    if (!empty($applicantData['applicant'])) {
                        $data['applicant'] = $applicantData['applicant'];
                    }

                    if (!empty($applicantData['owner'])) {
                        $data['owner'] = $applicantData['owner'];
                    }

                    break;


                case 'owner':

                    $ownerData = $this->saveDraftOwner($inputs[$section]);

                    if (!empty($ownerData['applicant'])) {
                        $data['applicant'] = $ownerData['applicant'];
                    }

                    if (!empty($ownerData['owner'])) {
                        $data['owner'] = $ownerData['owner'];
                    }

                    break;
                case 'property':
                    $data[$section] = $this->saveDraftProperty($inputs[$section]);
                    break;

                case 'monument':
                    $data[$section] = $this->saveDraftMonument($inputs[$section]);
                    break;

                case 'additional_info':
                    $data[$section] = $this->saveDraftAdditionalInformation($inputs[$section]);
                    break;

                case 'documents':
                    $data[$section] = $this->saveDraftDocuments($inputs[$section]);
                    break;
            }
        }

        return $data;
    }





    public function saveDraftApplicant($payloads, $syncOwner = true)
    {
        try {


            DB::transaction(function () use ($payloads) {

                $insertData = [];

                $ref_applicant_type_id = $payloads['ref_applicant_type_id'];

                $records = $payloads['applicant'];

                foreach ($records as $record) {

                    $payload = [
                        'ref_applicant_type_id' => $ref_applicant_type_id,
                        'draft_no' => $this->draftno,

                        'pan' => $record['pan'] ?? null,
                        'applicant_other_than_owner' => $record['applicant_other_than_owner'] ?? null,

                        'present_plot_survey_no' => $record['present_plot_survey_no'] ?? null,
                        'present_ref_district_id' => $record['present_ref_district_id'] ?? null,
                        'present_ref_state_id' => $record['present_ref_state_id'] ?? null,
                        'present_taluk' => $record['present_taluk'] ?? null,
                        'present_locality' => $record['present_locality'] ?? null,

                        'same_as_present_address' => $record['same_as_present_address'] ?? 0,

                        'date_of_birth' => $record['date_of_birth'] ?? null,
                        'father_name' => $record['father_name'] ?? null,

                        'ref_applicant_type_detail_id' => $record['ref_applicant_type_detail_id'] ?? null, // ENTITY ID

                    ];

                    if ($ref_applicant_type_id === 2) {

                        $payload['applicant_first_name'] = $record['applicant_first_name'] ?? null;

                        $payload['authorized_person_name'] = $record['authorized_person_name'] ?? null;

                        $payload['applicant_mobile_no'] = $record['applicant_mobile_no'] ?? null;

                        $payload['applicant_email_id'] = $record['applicant_email_id'] ?? null;
                    } else {

                        $payload['applicant_mobile_no'] = $record['applicant_mobile_no'] ?? null;
                        $payload['applicant_email_id'] = $record['applicant_email_id'] ?? null;
                        $payload['salutation'] = $record['salutation'] ?? null;
                        $payload['applicant_first_name'] = $record['applicant_first_name'] ?? null;
                        $payload['applicant_middle_name'] = $record['applicant_middle_name'] ?? null;
                        $payload['applicant_last_name'] = $record['applicant_last_name'] ?? null;
                    }

                    if ($record['same_as_present_address'] == 1) {

                        $payload['permanent_plot_survey_no'] = $record['present_plot_survey_no'] ?? null;
                        $payload['permanent_ref_district_id'] = $record['present_ref_district_id'] ?? null;
                        $payload['permanent_ref_state_id'] = $record['present_ref_state_id'] ?? null;
                        $payload['permanent_taluk'] = $record['present_taluk'] ?? null;
                        $payload['permanent_locality'] = $record['present_locality'] ?? null;
                    } else {

                        $payload['permanent_plot_survey_no'] = $record['permanent_plot_survey_no'] ?? null;
                        $payload['permanent_ref_district_id'] = $record['permanent_ref_district_id'] ?? null;
                        $payload['permanent_ref_state_id'] = $record['permanent_ref_state_id'] ?? null;
                        $payload['permanent_taluk'] = $record['permanent_taluk'] ?? null;
                        $payload['permanent_locality'] = $record['permanent_locality'] ?? null;
                    }

                    if (!empty($record['id'])) {

                        $applicant = Form1ApplicantDraftDetails::where('id', $record['id'])
                            ->where('draft_no', $this->draftno)
                            ->first();

                        if ($applicant) {

                            $payload['updated_by'] = $this->userId;

                            $payload['updated_on'] = $this->now;

                            $applicant->forceFill($payload);

                            $applicant->save();
                        }
                    } else {

                        $payload['created_by'] = $this->userId;

                        $payload['created_on'] = $this->now;

                        $insertData[] = $payload;
                    }
                }




                if (!empty($insertData)) {

                    Form1ApplicantDraftDetails::insert($insertData);

                    $draft = $this->saveFormOneDraft(1);
                }
            });


            $applicants = self::getApplicant();

            if ($syncOwner) {
                $syncData = $this->syncApplicantToOwner($applicants);
                $owners = $syncData['owner'] ?? [];
            } else {
                $owners = self::getOwner();
            }

            return [
                'applicant' => $applicants,
                'owner'     => $owners,
            ];
        } catch (Exception $ex) {

            throw $ex;
        }
    }
    public function saveDraftOwner($records, $syncApplicant = true)
    {
        try {


            DB::transaction(function () use ($records) {

                $insertData = [];

                foreach ($records as $record) {

                    $payload = [
                        'draft_no'                => $this->draftno,
                        'pan'                     => $record['pan'] ?? null,
                        'owner_mobile_no'         => $record['owner_mobile_no'] ?? null,
                        'owner_email_id'          => $record['owner_email_id'] ?? null,
                        'salutation'              => $record['salutation'] ?? null,
                        'owner_first_name'        => $record['owner_first_name'] ?? null,
                        'owner_middle_name'       => $record['owner_middle_name'] ?? null,
                        'owner_last_name'         => $record['owner_last_name'] ?? null,

                        'present_plot_survey_no' => $record['present_plot_survey_no'] ?? null,
                        'present_ref_district_id' => $record['present_ref_district_id'] ?? null,
                        'present_ref_state_id' => $record['present_ref_state_id'] ?? null,
                        'present_taluk' => $record['present_taluk'] ?? null,
                        'present_locality' => $record['present_locality'] ?? null,
                        'same_as_present_address' => $record['same_as_present_address'] ?? null,
                        'source_applicant_id' => $record['source_applicant_id'] ?? null,
                    ];

                    if (!empty($record['same_as_present_address'])) {

                        $payload['permanent_plot_survey_no'] = $record['present_plot_survey_no'] ?? null;
                        $payload['permanent_ref_district_id'] = $record['present_ref_district_id'] ?? null;
                        $payload['permanent_ref_state_id'] = $record['present_ref_state_id'] ?? null;
                        $payload['permanent_taluk'] = $record['present_taluk'] ?? null;
                        $payload['permanent_locality'] = $record['present_locality'] ?? null;
                    } else {

                        $payload['permanent_plot_survey_no'] = $record['permanent_plot_survey_no'] ?? null;
                        $payload['permanent_ref_district_id'] = $record['permanent_ref_district_id'] ?? null;
                        $payload['permanent_ref_state_id'] = $record['permanent_ref_state_id'] ?? null;
                        $payload['permanent_taluk'] = $record['permanent_taluk'] ?? null;
                        $payload['permanent_locality'] = $record['permanent_locality'] ?? null;
                    }

                    if (!empty($record['source_applicant_id'])) {

                        $owner = Form1OwnerDraftDetails::where(
                            'source_applicant_id',
                            $record['source_applicant_id']
                        )
                            ->where('draft_no', $this->draftno)
                            ->first();

                        if ($owner) {

                            $payload['updated_by'] = $this->userId;
                            $payload['updated_on'] = $this->now;

                            $owner->forceFill($payload);
                            $owner->save();

                            continue;
                        }
                    }

                    if (!empty($record['id'])) {

                        $payload['updated_by'] = $this->userId;
                        $payload['updated_on'] = $this->now;

                        $owner =  Form1OwnerDraftDetails::where('id', $record['id'])
                            ->where('draft_no', $this->draftno)
                            ->first();

                        if ($owner) {

                            $owner->forceFill($payload);
                            $owner->save();

                            continue;
                        }
                    } else {

                        $payload['created_by'] = $this->userId;
                        $payload['created_on'] = $this->now;

                        $insertData[] = $payload;
                    }
                }

                if (!empty($insertData)) {
                    Form1OwnerDraftDetails::insert($insertData);
                    $this->saveFormOneDraft(2);
                }
            });

            $owners = self::getOwner();

            if ($syncApplicant) {
                $applicants = $this->syncOwnerToApplicant($owners);

                $applicants = $applicants['applicant'] ?? [];
            } else {
                $applicants = self::getApplicant();
            }

            return [
                'applicant' => $applicants,
                'owner' => $owners,
            ];

            return $response;
        } catch (Exception $ex) {

            throw $ex;
        }
    }


    public function saveDraftProperty($record)
    {
        try {



            $payload = [
                'draft_no' => $this->draftno,
                'ref_property_owned_id' => $record['ref_property_owned_id'] ?? null,
                'plot_survey_no' => $record['plot_survey_no'] ?? null,
                'ref_state_id' => $record['ref_state_id'] ?? null,
                'ref_district_id' => $record['ref_district_id'] ?? null,
                'taluk' => $record['taluk'] ?? null,
                'locality' => $record['locality'] ?? null,
                'ref_nature_work_proposed_id' => $record['ref_nature_work_proposed_id'] ?? null,
                'no_of_storeys' => $record['no_of_storeys'] ?? null,
                'basement_proposed' => $record['basement_proposed'] ?? null,
                'exceluding_height' => $record['exceluding_height'] ?? null,
                'including_height' => $record['including_height'] ?? null,
                'ref_proposed_work_id' => $record['ref_proposed_work_id'] ?? null,

                'approx_date_commencement' => $record['approx_date_commencement'] ?? null,
                'approx_duration_completion' => $record['approx_duration_completion'] ?? null,

                'structure_type' =>  $record['structure_type'] ?? null,
                'created_by' => $this->userId,
                'created_on' => $this->now
            ];

            if ($record['ref_property_owned_id'] == 7) {
                $payload['other_property_owned'] = $record['other_property_owned'] ?? null;
            }

            if ($record['ref_proposed_work_id'] == 6) {
                $payload['other_proposed_work'] = $record['other_proposed_work'] ?? null;
            }

            if ($record['ref_nature_work_proposed_id'] == 3) { // Reconstruction

                $payload = array_merge($payload, [

                    'existing_no_of_storeys' => $record['existing_no_of_storeys'] ?? null,

                    'existing_floor_area' => $record['existing_floor_area'] ?? null,

                    'existing_exceluding_height' => $record['existing_exceluding_height'] ?? null,

                    'existing_including_height' =>   $record['existing_including_height'] ?? null,

                    'existing_basement_proposed' => $record['existing_basement_proposed'] ?? null,

                ]);
            }



            if ($record['ref_nature_work_proposed_id'] == 4) { // Others
                $payload = array_merge($payload, [
                    'nature_of_work_other' => $record['nature_of_work_other'] ?? null,
                ]);
            }

            // if ($record['structure_type'] == 2) { // Structure Type
            //     $payload = array_merge($payload, [

            //         'structure_type_other' => $record['structure_type_other'] ?? null,
            //         'structure_type_other_description' => $record['structure_type_other_description'] ?? null,
            //     ]);
            // } else {

            //     $payload = array_merge($payload, [
            //         'structure_type_other' =>  null,
            //         'structure_type_other_description' =>  null,
            //     ]);

            //     if (!empty($record['buildings'])) {

            //         $this->saveDraftBuilding($record['buildings']);
            //     }
            // }

            if ($record['structure_type'] == 2) {

                $payload = array_merge($payload, [
                    'structure_type_other' => $record['structure_type_other'] ?? null,
                    'structure_type_other_description' => $record['structure_type_other_description'] ?? null,
                ]);
            } else {

                $payload = array_merge($payload, [
                    'structure_type_other' => null,
                    'structure_type_other_description' => null,
                ]);
            }


            if (!empty($record['id'])) {

                $payload['updated_by'] = $this->userId;
                $payload['updated_on'] = $this->now;

                $property =  Form1PropertyDraftDetails::where('id', $record['id'])
                    ->where('draft_no', $this->draftno)
                    ->first();

                if ($property) {
                    $property->forceFill($payload);
                    $property->save();
                }
            } else {

                $payload['created_by'] = $this->userId;
                $payload['created_on'] = $this->now;
                $property = Form1PropertyDraftDetails::create($payload);
            }

            if (!empty($record['buildings'])) {
                $this->saveDraftBuilding($record['buildings']);
            }


            if (Form1PropertyDraftDetails::where('draft_no', $this->draftno)->exists()) {
                Form1MonumentDraftDetails::where('draft_no', $this->draftno)->delete();
            }

            $draft = $this->saveFormOneDraft(3);

            return self::getProperty();
        } catch (Exception $ex) {

            throw $ex;
        }
    }
    public function saveDraftMonument($record)
    {
        try {

            $payload = [
                'draft_no' => $this->draftno,
                'nma_cpm_history_id'            => $record['nma_cpm_history_id'],
                'locality'                   => $record['locality'],
                'ref_state_id'               => $record['ref_state_id'],
                'ref_district_id'            => $record['ref_district_id'],
                'taluk'                      => $record['taluk'],
                'distance_from_main_monument' => $record['distance_from_main_monument'],
                'distance_from_boundary'     => $record['distance_from_boundary'],

                'created_by' => $this->userId,
                'created_on' => $this->now
            ];


            if (!empty($record['id'])) {

                $payload['updated_by'] = $this->userId;
                $payload['updated_on'] = $this->now;

                $monument =  Form1MonumentDraftDetails::where('id', $record['id'])
                    ->where('draft_no', $this->draftno)
                    ->first();

                if ($monument) {
                    $monument->forceFill($payload);
                    $monument->save();
                }
            } else {

                $payload['created_by'] = $this->userId;
                $payload['created_on'] = $this->now;
                $monument = Form1MonumentDraftDetails::create($payload);
            }

            $draft = $this->saveFormOneDraft(4);

            return self::getMonument();
        } catch (Exception $ex) {

            throw new Exception($ex->getMessage());
        }
    }
    public function saveDraftAdditionalInformation($record)
    {
        try {


            $payload = [
                'draft_no' => $this->draftno,
                'max_height_near_monument'                 => $record['max_height_near_monument'],
                'max_height_near_site'                     => $record['max_height_near_site'],
                'ref_located_monument_id'                  => $record['ref_located_monument_id'],
                'is_master_plan_exists'                    =>  $record['is_master_plan_exists'],
                'modern_construction_status'               =>  $record['modern_construction_status'],
                'is_open_source_nearby'                    =>  $record['is_open_source_nearby'],
                'is_road_exists_between_monument_and_site' =>  $record['is_road_exists_between_monument_and_site'],
                'remarks'                                  =>  $record['remarks'],
            ];

            if (!empty($record['id'])) {

                $payload['updated_by'] = $this->userId;
                $payload['updated_on'] = $this->now;

                $additionalInfo =  Form1AdditionalDraftDetails::where('id', $record['id'])
                    ->where('draft_no', $this->draftno)
                    ->first();

                if ($additionalInfo) {

                    $additionalInfo->forceFill($payload);
                    $additionalInfo->save();
                }
            } else {

                $payload['created_by'] = $this->userId;
                $payload['created_on'] = $this->now;
                $additionalInfo = Form1AdditionalDraftDetails::create($payload);
            }

            $draft = $this->saveFormOneDraft(5);

            return self::getAdditionalInformation();
        } catch (Exception $ex) {
            throw $ex;
        }
    }
    public function saveDraftDocuments(array $record)
    {
        try {
            $documents = $this->commonService->getDocuments();

            if (empty($documents)) {
                return [];
            }

            $payloads = [];

            $applicantIds = array_column(self::getApplicant(), 'id');

            $gpaSpaDocuments = $record['gpa_spa_registered_by_sub_registrar_office_of_concerned_district'] ?? [];

            if (!empty($gpaSpaDocuments)) {

                if (empty($record['gpa_spa_applicant_id'])) {
                    abort(422, 'Applicant ID is required when GPA/SPA document is uploaded.');
                }

                if (!in_array($record['gpa_spa_applicant_id'], $applicantIds)) {
                    abort(422, 'Invalid GPA / SPA Applicant ID.');
                }
            }


            $draft = $this->getFormOneDraft($this->draftno);

            $folders =  $this->getDraftDocPath($draft);


            foreach ($documents as $doc) {

                $fieldName = $doc['field_name'];

                if (empty($record[$fieldName])) {
                    continue;
                }

                $files = is_array($record[$fieldName])  ? $record[$fieldName] : [$record[$fieldName]];

                foreach ($files as $file) {

                    $filePath = $folders . '/' . $file;

                    if (!Storage::disk('public')->exists($filePath)) {
                        abort(422, "File '{$file}' does not exist.");
                    }

                    $payloads[] = [
                        'draft_no'                  => $this->draftno,
                        'ref_document_type_id'      => $doc['id'],
                        'document_path'             => $file,
                        'terms_conditions_accepted' => $record['terms_conditions_accepted'] ?? 1,
                        'gpa_spa_applicant_id'      => $doc['id'] == 6
                            ? ($record['gpa_spa_applicant_id'] ?? null)
                            : null,
                        'is_active'                 => 1,
                        'is_deleted'                => 0,
                        'created_by'                => $this->userId,
                        'created_on'                => $this->now,
                    ];
                }
            }

            Form1UploadDraftDetails::where(
                'draft_no',
                $this->draftno,
            )->delete();

            if (!empty($payloads)) {
                Form1UploadDraftDetails::insert($payloads);
            }

            $this->saveFormOneDraft(6);

            return self::getDocument();
        } catch (Exception $ex) {
            throw $ex;
        }
    }


    public function saveDraftBuilding($buildings)
    {
        try {

            DB::transaction(function () use ($buildings) {

                $insertData = [];

                foreach ($buildings as $building) {

                    $buildingData = [
                        'draft_no' => $this->draftno,
                        'building_name' => $building['building_name'] ?? null,
                        'total_floors' => $building['total_floors'] ?? null,
                        'total_area' => $building['total_area'] ?? null,
                        'total_height' => $building['total_height'] ?? null,
                    ];

                    if (!empty($building['id'])) {

                        $buildingData['updated_by'] = $this->userId;
                        $buildingData['updated_on'] = $this->now;

                        $buildingModel =  Form1BuildingDraftDetails::where('id', $building['id'])
                            ->where('draft_no', $this->draftno)
                            ->first();

                        if ($buildingModel) {

                            $buildingModel->forceFill($buildingData);
                            $buildingModel->save();
                        }
                    } else {

                        $buildingData['created_by'] = $this->userId;
                        $buildingData['created_on'] = $this->now;
                        $buildingModel =   Form1BuildingDraftDetails::create($buildingData);
                    }

                    if ($buildingModel && !empty($building['floors'])) {
                        $this->saveDraftBuildingFloor(
                            $building['floors'],
                            $buildingModel->id
                        );
                    }

                    // if (!empty($building['floors'])) {

                    //     $buildingId = $building['id'] ?? $buildingModel->id;

                    //     $this->saveDraftBuildingFloor(
                    //         $building['floors'],
                    //         $buildingId
                    //     );
                    // }
                }
            });
        } catch (Exception $ex) {
            throw new Exception($ex->getMessage());
        }
    }
    public function saveDraftBuildingFloor($floors, $buildingId)
    {
        try {

            $insertData = [];

            $incomingIds = [];

            foreach ($floors as $floor) {

                $floorData = [
                    'draft_no' => $this->draftno,
                    'nma_form1_buildings_draft_details_id' => $buildingId,
                    'floor_area' => $floor['floor_area'] ?? null,
                    'height' => $floor['height'] ?? null,
                    'floor_name' => $floor['floor_name'] ?? null,
                ];

                if (!empty($floor['id'])) {

                    $incomingIds[] = $floor['id'];

                    $floorData['updated_by'] = $this->userId;

                    $floorData['updated_on'] = $this->now;

                    $model =  Form1BuildingFloorDraftDetails::where('id', $floor['id'])
                        ->where('draft_no', $this->draftno)
                        ->first();

                    if ($model) {
                        $model->forceFill($floorData);
                        $model->save();
                    }
                } else {

                    $floorData['created_by'] = $this->userId;
                    $floorData['created_on'] = $this->now;

                    $insertData[] = $floorData;
                }
            }

            if (!empty($insertData)) {
                Form1BuildingFloorDraftDetails::insert($insertData);
            }
            if (!empty($incomingIds)) {

                Form1BuildingFloorDraftDetails::where('draft_no', $this->draftno)
                    ->where('nma_form1_buildings_draft_details_id', $buildingId)
                    ->whereNotIn('id', $incomingIds)
                    ->delete();
            }
        } catch (Exception $ex) {
            throw new Exception($ex->getMessage());
        }
    }

    private function syncApplicantToOwner($applicants)
    {
        if (empty($applicants)) {
            return [];
        }

        $payloads = [];

        foreach ($applicants as $record) {

            if (($record['applicant_other_than_owner'] == 1) && ($record['ref_applicant_type_detail_id'] == 1)) {

                $payloads[] = [
                    'draft_no'                 => $this->draftno,
                    'pan'                      => $record['pan'] ?? null,

                    'owner_mobile_no'          => $record['applicant_mobile_no'] ?? null,
                    'owner_email_id'           => $record['applicant_email_id'] ?? null,

                    'salutation'               => $record['salutation'] ?? null,
                    'owner_first_name'         => $record['applicant_first_name'] ?? null,
                    'owner_middle_name'        => $record['applicant_middle_name'] ?? null,
                    'owner_last_name'          => $record['applicant_last_name'] ?? null,


                    'present_plot_survey_no'   => $record['present_plot_survey_no'] ?? null,
                    'present_ref_district_id'  => $record['present_ref_district_id'] ?? null,
                    'present_ref_state_id'     => $record['present_ref_state_id'] ?? null,
                    'present_taluk'            => $record['present_taluk'] ?? null,
                    'present_locality'         => $record['present_locality'] ?? null,

                    'same_as_present_address'  => $record['same_as_present_address'] ?? null,
                    'permanent_plot_survey_no' => $record['permanent_plot_survey_no'] ?? null,
                    'permanent_ref_district_id' =>  $record['permanent_ref_district_id'] ?? null,
                    'permanent_ref_state_id' =>  $record['permanent_ref_state_id'] ?? null,
                    'permanent_taluk' =>       $record['permanent_taluk'] ?? null,
                    'permanent_locality' => $record['permanent_locality'] ?? null,

                    'source_applicant_id'      => $record['id'],
                ];
            }
        }

        if (empty($payloads)) {
            return [];
        }

        return $this->saveDraftOwner($payloads, false);
    }


    private function syncOwnerToApplicant($owners)
    {
        if (empty($owners)) {
            return [];
        }

        $payloads = [];


        foreach ($owners as $record) {

            if (empty($record['source_applicant_id'])) {
                continue;
            }

            $ref_applicant_type_id = $record['ref_applicant_type_id'] ?? 1;

            $payloads[] = [
                'ref_applicant_type_id' => $ref_applicant_type_id,
                'draft_no' => $this->draftno,

                'pan' => $record['pan'] ?? null,

                'applicant_other_than_owner' => 1,

                'same_as_present_address' => $record['same_as_present_address'] ?? 0,

                'ref_applicant_type_detail_id' => 1,

                'applicant_mobile_no' => $record['owner_mobile_no'] ?? null,
                'applicant_email_id' => $record['owner_email_id'] ?? null,
                'salutation' => $record['salutation'] ?? null,
                'applicant_first_name' => $record['owner_first_name'] ?? null,
                'applicant_middle_name' => $record['owner_middle_name'] ?? null,
                'applicant_last_name' => $record['owner_last_name'] ?? null,

                'present_plot_survey_no' => $record['present_plot_survey_no'] ?? null,
                'present_ref_district_id' => $record['present_ref_district_id'] ?? null,
                'present_ref_state_id' => $record['present_ref_state_id'] ?? null,
                'present_taluk' => $record['present_taluk'] ?? null,
                'present_locality' => $record['present_locality'] ?? null,

                'permanent_plot_survey_no' => $record['permanent_plot_survey_no'] ?? null,
                'permanent_ref_district_id' =>  $record['permanent_ref_district_id'] ?? null,
                'permanent_ref_state_id' =>  $record['permanent_ref_state_id'] ?? null,
                'permanent_taluk' =>       $record['permanent_taluk'] ?? null,
                'permanent_locality' => $record['permanent_locality'] ?? null,


                'id'      => $record['source_applicant_id'],
            ];
        }

        if (empty($payloads)) {
            return [];
        }

        $final_payload = [
            'ref_applicant_type_id' => $payloads[0]['ref_applicant_type_id'],
            'applicant'             => $payloads,
        ];

        return $this->saveDraftApplicant($final_payload, false);
    }


    private function saveFormOneDraft($stepStatus)
    {
        if ($this->uuid) {

            $draft = Form1Draft::where('uuid', $this->uuid)->first();

            if ($draft) {

                $draft->forceFill([
                    'step_status' => $stepStatus,
                    'source_platform' => 'Mobile',
                    'updated_by' => userId(),
                    'updated_on' => now(),
                ]);

                $draft->save();
            }

            return $draft->fresh();
        }

        return Form1Draft::create([
            'created_by'  => userId(),
            'step_status' => $stepStatus,
            'source_platform' => 'Mobile'
        ]);
    }

    public function getFormOneDraft($uuid)
    {
        $draft = Form1Draft::where([
            'uuid' => $uuid
        ])->orWhere('draft_no', $uuid)->first();

        return $draft;
    }
}

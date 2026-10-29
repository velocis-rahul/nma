<?php

namespace App\Services;

use App\Classes\ResponseHandler;
use App\Http\Requests\FormOneRequest;
use App\Models\{
    AllState,
    Form1AdditionalDetails,
    Form1ApplicantDetails,
    Form1ApplicationNo,
    Form1BuildingDetails,
    Form1BuildingFloorDetails,
    Form1FieldAction,
    Form1MonumentDetails,
    Form1OwnerDetails,
    Form1PropertyDetails,
    Form1UploadDetails,
    UlbArchitect,
};

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;


class FormOneService extends BaseFormService
{
    private $userId = null;
    private $now = null;
    public $partnerId = null;
    public $applicationId = 1;
    public $applicationNo = null;
    public $referenceColumn = 'application_no';
    public $referenceNo;
    public $application;
    public $sourcePlatform = "Mobile";

    public $applicantsMap = [];

    public CommonService $commonService;

    public function __construct(CommonService $commonService)
    {
        $this->commonService = $commonService;
    }
    public function index(Request $request)
    {
        $applications  =  $this->getSubmittedApplications($request);

        return ResponseHandler::success(
            $applications,
            'Application data fetched!'
        );
    }

    public function store(Request $request)
    {
        try {

            $this->now = now();

            $this->userId = userId();

            $data = $this->save($request->all());

            $data['self_declaration'] = getSelfDeclaration();

            return ResponseHandler::success($data, 'Data saved successfully!');
        } catch (Exception $ex) {

            throw $ex;
        }
    }

    public function update(Request $request, $uuid)
    {
        try {

            $this->userId = userId();

            $this->now = now();

            $application = $this->getApplicationOrFail($uuid);

            $data = $this->save($request->all());

            $data['self_declaration'] = getSelfDeclaration();

            return ResponseHandler::updated($data, 'Data updated successfully!');
        } catch (Exception $ex) {

            return ResponseHandler::error($ex->getMessage(), 422);
        }
    }

    public function show(Request $request, $uuid)
    {
        try {

            $application = $this->getApplicationOrFail($uuid);

            $this->applicationNo = $application->application_no;

            $this->referenceNo   = $application->application_no;

            $data = $this->getData();

            return ResponseHandler::success($data, 'Data fetched!');
        } catch (Exception $ex) {

            throw $ex;
        }
    }

    public function permission(Request $request)
    {
        try {

            $data = $this->getApplicationPermission($request);

            return ResponseHandler::success($data, 'Data fetched!');
        } catch (Exception $ex) {

            throw $ex;
        }
    }

    public function collection($uuid)
    {

        $this->getApplicationOrFail($uuid);

        return $this->getData();
    }

    protected function getApplicationOrFail($uuid)
    {
        $application = $this->getApplication($uuid);

        if (!$application) {
            abort(404, "Invalid uuid number!");
        }

        $this->application = $application;

        $this->applicationId = $application->id;
        $this->applicationNo = $application->application_no;
        $this->referenceNo   = $application->application_no;

        return $application;
    }

    public function save(array $inputs, $draft = null)
    {
        try {

            DB::beginTransaction();

            $application = $this->createApplication($inputs ?? []);

            if (!is_null($draft)) {

                $draftRelativePath = $this->getDraftDocPath($draft);
                $applicationRelativePath = $this->getApplicationDocPath($application);

                if (empty($draftRelativePath)) {
                    throw new Exception('Draft document path not found.');
                }

                if (empty($applicationRelativePath)) {
                    throw new Exception('Application document path not found.');
                }

                $draftPath = storage_path('app/public/' . ltrim($draftRelativePath, '/'));

                $applicationPath = storage_path('app/public/' . ltrim($applicationRelativePath, '/'));

                if ($draftPath === $applicationPath) {
                    throw new Exception(
                        'Draft folder and application folder cannot be the same.'
                    );
                }

                if (!is_dir($draftPath)) {
                    throw new Exception(
                        'Draft folder not found: ' . $draftPath
                    );
                }

                if (file_exists($applicationPath)) {
                    throw new Exception(
                        'Destination folder already exists: ' . $applicationPath
                    );
                }

                if (!is_dir(dirname($applicationPath))) {

                    if (!mkdir(dirname($applicationPath), 0777, true) && !is_dir(dirname($applicationPath))) {
                        throw new Exception(
                            'Unable to create destination directory: '
                                . dirname($applicationPath)
                        );
                    }
                }

                if (!is_writable(dirname($applicationPath))) {
                    throw new Exception(
                        'Destination directory is not writable: '
                            . dirname($applicationPath)
                    );
                }

                $result = rename($draftPath, $applicationPath);

                if (!$result) {
                    throw new Exception('Folder rename failed from ' . $draftPath . ' to ' . $applicationPath);
                }

                if (!is_dir($applicationPath)) {
                    throw new Exception(
                        'Application folder was not created after rename.'
                    );
                }
            }

            $this->saveApplicant($inputs['applicant'] ?? []);
            $this->saveOwner($inputs['owner'] ?? []);
            $this->saveProperty($inputs['property'] ?? []);
            $this->saveMonument($inputs['monument'] ?? []);
            $this->saveAdditionalInfo($inputs['additional_info'] ?? []);
            $this->saveDocuments($inputs['documents'] ?? []);

            DB::commit();

            return $this->buildApplicationResponse($application);
        } catch (Exception $ex) {
            DB::rollBack();

            throw $ex;
        }
    }

    private function buildApplicationResponse($application = null)
    {
        return [
            "uuid"             => $this->application?->uuid ?? $application?->uuid,
            "id"             => $this->application?->id ?? $application?->id,
            "application_no"  => $this->applicationNo,
            "created_on"      => $this->getCreatedOn($application),
            'applicant'       => $this->getApplicant(),
            'owner'           => $this->getOwner(),
            'property'        => $this->getProperty(),
            'monument'        => $this->getMonument(),
            'additional_info' => $this->getAdditionalInformation(),
            'architect' => $this->getArchitect(),
            'documents'         => $this->getDocument(),
            'application_history' => getApplicationHistory($this->applicationNo),
            'send_back' =>  getApplicationFieldActions($this->applicationNo)
        ];
    }


    private function getData()
    {
        return $this->buildApplicationResponse();
    }


    private function getCreatedOn($application = null)
    {

        $app = $application ?? $this->application ?? null;

        return $app && $app->created_on ? formatDate($app->created_on) : null;
    }

    public function getApplicant()
    {
        $data = $this->getCollection(Form1ApplicantDetails::class, $this->referenceColumn);

        if (!$data) {
            return null;
        }

        return $this->hideFields($data, self::HIDDEN_FIELDS)->toArray();
    }

    public function getOwner()
    {
        try {

            $data = $this->getCollection(Form1OwnerDetails::class, $this->referenceColumn);

            if (!$data) {
                return null;
            }

            return $this->hideFields($data, self::HIDDEN_FIELDS)->toArray();
        } catch (Exception $ex) {
            throw $ex;
        }
    }
    public function getProperty()
    {
        $property = $this->getSingle(Form1PropertyDetails::class, $this->referenceColumn);

        if (!$property) {
            return [];
        }

        $property = $this->hideFields($property, self::HIDDEN_FIELDS);

        $property['building'] = $this->getBuilding();

        return $property->toArray();
    }

    public function getMonument()
    {
        $data = $this->getSingle(Form1MonumentDetails::class, $this->referenceColumn);

        if (!$data) {
            return null;
        }

        return $this->hideFields($data, self::HIDDEN_FIELDS)->toArray();
    }
    public function getAdditionalInformation()
    {
        $data = $this->getSingle(Form1AdditionalDetails::class, $this->referenceColumn);
        if (!$data) {
            return null;
        }

        return $this->hideFields($data, self::HIDDEN_FIELDS)->toArray();
    }
    public function getArchitect()
    {
        $data = $this->getSingle(UlbArchitect::class, $this->referenceColumn);
        if (!$data) {
            return null;
        }

        return $this->hideFields($data, self::HIDDEN_FIELDS)->toArray();
    }

    public function getDocument()
    {
        try {
            $documents = $this->commonService->getDocuments();

            $savedDocs = Form1UploadDetails::where('application_no', $this->applicationNo)
                ->where('is_deleted', 0)
                ->get()
                ->groupBy('ref_document_type_id');

            $response = [];

            if ($savedDocs->isEmpty()) {
                return [];
            }

            $response['terms_conditions_accepted'] = 1;

            $application = $this->getApplication($this->applicationNo);

            $folders =  $this->getApplicationDocPath($application);

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
                            'url' => $saved->document_path,
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
        $buildings = Form1BuildingDetails::with('floors')
            ->where($this->referenceColumn, $this->referenceNo)
            ->get();

        return $buildings->map(function ($building) {

            $buildingData = $this->hideFields(
                $building,
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
            )->toArray();

            return $buildingData;
        })->toArray();
    }

    public function createApplication($inputs)
    {

        $property = $inputs['property'];

        $cacheKey = 'state_code_' . $property['ref_state_id'];

        $stateCode = getCache($cacheKey);

        if (!$stateCode) {

            $state = AllState::find($property['ref_state_id']);

            $stateCode = $state?->state_short_name ?? 'NA';

            setCache($cacheKey, $stateCode, 30 * 24 * 60);
        }

        $year = now()->format('Y');

        $lastApplication = Form1ApplicationNo::whereYear('created_on', $year)
            ->whereRaw("RIGHT(application_no, 5) ~ '^[0-9]{5}$'")
            ->orderByRaw("CAST(RIGHT(application_no, 5) AS INTEGER) DESC")
            ->first();

        if ($lastApplication) {
            $nextId = ((int) substr($lastApplication->application_no, -5)) + 1;
        } else {
            $nextId = 1;
        }

        $application_no = sprintf(
            'NOC-%s-%s-%05d',
            $stateCode,
            $year,
            $nextId
        );

        $this->sourcePlatform =  $inputs['sourcePlatform'] ?? 'Mobile';

        $application = Form1ApplicationNo::create([
            'application_no'                  => $application_no,
            'created_by'                      => userId(),
            'created_on'                      => now(),
            'ref_non_single_window_status_id' => ($inputs['sourcePlatform'] ?? null) === 'Ulb' ? 35 : 2,
            'source_platform' =>  $this->sourcePlatform,
            'user_ulbs_authentication_id' => $inputs['partnerId']  ?? null
        ]);

        $this->applicationId = $application->id;
        $this->applicationNo = $application->application_no;
        $this->referenceNo   = $application->application_no;

        $this->application = $application;

        return $application;
    }

    private function saveApplicant(array $applicants)
    {

        $insertData = [];

        foreach ($applicants as $record) {

            $payload = [
                'ref_applicant_type_id' => $record['ref_applicant_type_id'],
                'application_no' => $this->applicationNo,
                'nma_form1_application_no_id' => $this->applicationId,

                'pan' => $record['pan'] ?? null,
                'applicant_other_than_owner' => $record['applicant_other_than_owner'] ?? null,

                'present_plot_survey_no' => $record['present_plot_survey_no'] ?? null,
                'present_ref_district_id' => $record['present_ref_district_id'] ?? null,
                'present_ref_state_id' => $record['present_ref_state_id'] ?? null,
                'present_taluk' => $record['present_taluk'] ?? null,
                'present_locality' => $record['present_locality'] ?? null,

                'same_as_present_address' => $record['same_as_present_address'] ?? 0,

                // 'ref_applicant_type_detail_id' => $record['ref_applicant_type_detail_id'] ?? null,
                'authorized_person_name' => $record['authorized_person_name'] ?? null,

                'date_of_birth' => $record['date_of_birth'] ?? null,
                'father_name' => $record['father_name'] ?? null,

                'source_platform' =>  $this->sourcePlatform,

            ];

            // $payload    += $record['ref_applicant_type_id'] == 2 ? $record['ref_applicant_type_detail_id'] ?? null : null;
            // $payload    += $record['ref_applicant_type_id'] == 2 ? $this->getOtherApplicantData($record) : $this->getIndividualApplicantData($record);

            $isOtherApplicant = $record['ref_applicant_type_id'] == 2;

            $payload['ref_applicant_type_detail_id'] = $isOtherApplicant  ? ($record['ref_applicant_type_detail_id'] ?? null) : null;

            $applicantData = $isOtherApplicant  ? $this->getOtherApplicantData($record) : $this->getIndividualApplicantData($record);

            $payload += $applicantData;

            $payload += $this->getAddressData($record);

            if (!empty($record['id'])) {

                $applicant = Form1ApplicantDetails::create($payload);

                // $this->applicantsMap[$record['id']] = $applicant->id;
            } else {

                $insertData[] = $payload;
            }
        }

        if (!empty($insertData)) {

            Form1ApplicantDetails::insert($insertData);
        }

        return self::getApplicant();
    }

    private function getOtherApplicantData(array $record): array
    {

        return [

            'applicant_first_name' => $record['company_name'] ?? $record['applicant_first_name'] ?? null,

            'ref_applicant_type_detail_id' => $record['ref_applicant_type_detail_id'] ?? null,

            'authorized_person_name' => $record['authorized_person_name'] ?? null,

            'applicant_mobile_no' => $record['applicant_mobile_no'] ?? $record['applicant_mobile_no']  ?? null,

            'applicant_email_id' => $record['company_email_id']  ?? $record['applicant_email_id'] ?? null,
        ];
    }

    private function getIndividualApplicantData(array $record): array
    {
        return [
            'salutation'            => $record['salutation'] ?? null,
            'applicant_first_name'  => $record['applicant_first_name'] ?? null,
            'applicant_middle_name' => $record['applicant_middle_name'] ?? null,
            'applicant_last_name'   => $record['applicant_last_name'] ?? null,
            'applicant_mobile_no'   => $record['applicant_mobile_no'] ?? null,
            'applicant_email_id'    => $record['applicant_email_id'] ?? null,
        ];
    }

    private function getAddressData(array $record): array
    {
        if (!empty($record['same_as_present_address'])) {
            return [
                'permanent_plot_survey_no'  => $record['present_plot_survey_no'] ?? null,
                'permanent_ref_district_id' => $record['present_ref_district_id'] ?? null,
                'permanent_ref_state_id'    => $record['present_ref_state_id'] ?? null,
                'permanent_taluk'           => $record['present_taluk'] ?? null,
                'permanent_locality'        => $record['present_locality'] ?? null,
            ];
        }

        return [
            'permanent_plot_survey_no'  => $record['permanent_plot_survey_no'] ?? null,
            'permanent_ref_district_id' => $record['permanent_ref_district_id'] ?? null,
            'permanent_ref_state_id'    => $record['permanent_ref_state_id'] ?? null,
            'permanent_taluk'           => $record['permanent_taluk'] ?? null,
            'permanent_locality'        => $record['permanent_locality'] ?? null,
        ];
    }


    private function saveOwner(array $owners)
    {
        $payload = [];

        $insertData = [];

        foreach ($owners as $owner) {

            $payload = [
                'application_no' => $this->applicationNo,
                'nma_form1_application_no_id' => $this->applicationId,

                'pan' => $owner['pan'] ?? null,
                'salutation' => $owner['salutation'] ?? null,
                'owner_first_name' => $owner['owner_first_name'] ?? null,
                'owner_middle_name' => $owner['owner_middle_name'] ?? null,
                'owner_last_name' => $owner['owner_last_name'] ?? null,
                'owner_mobile_no' => $owner['owner_mobile_no'] ?? null,
                'owner_email_id' => $owner['owner_email_id'] ?? null,
                'same_as_present_address' => $owner['same_as_present_address'] ?? null,

                'present_plot_survey_no' => $owner['present_plot_survey_no'] ?? null,
                'present_ref_district_id' => $owner['present_ref_district_id'] ?? null,
                'present_ref_state_id' => $owner['present_ref_state_id'] ?? null,
                'present_taluk' => $owner['present_taluk'] ?? null,
                'present_locality' => $owner['present_locality'] ?? null,

                'created_by' => $this->userId,
                'created_on' => $this->now,

                'source_platform' =>  $this->sourcePlatform,
            ];

            if (!empty($record['same_as_present_address'])) {
                $payload['permanent_plot_survey_no'] = $owner['present_plot_survey_no'] ?? null;
                $payload['permanent_ref_district_id'] = $owner['present_ref_district_id'] ?? null;
                $payload['permanent_ref_state_id'] = $owner['present_ref_state_id'] ?? null;
                $payload['permanent_taluk'] = $owner['present_taluk'] ?? null;
                $payload['permanent_locality'] = $owner['present_locality'] ?? null;
            } else {
                $payload['permanent_plot_survey_no'] = $owner['permanent_plot_survey_no'] ?? null;
                $payload['permanent_ref_district_id'] = $owner['permanent_ref_district_id'] ?? null;
                $payload['permanent_ref_state_id'] = $owner['permanent_ref_state_id'] ?? null;
                $payload['permanent_taluk'] = $owner['permanent_taluk'] ?? null;
                $payload['permanent_locality'] = $owner['permanent_locality'] ?? null;
            }

            $insertData[] =  $payload;
        }

        if (!empty($insertData)) {
            Form1OwnerDetails::insert($insertData);
        }

        return self::getOwner();
    }
    private function saveProperty(array $property)
    {

        $payloads = [
            'application_no' => $this->applicationNo,
            'nma_form1_application_no_id' => $this->applicationId,

            'ref_property_owned_id' => $property['ref_property_owned_id'] ?? null,
            'plot_survey_no' => $property['plot_survey_no'] ?? null,
            'ref_state_id' => $property['ref_state_id'] ?? null,
            'ref_district_id' => $property['ref_district_id'] ?? null,
            'taluk' => $property['taluk'] ?? null,
            'locality' => $property['locality'] ?? null,
            'ref_nature_work_proposed_id' => $property['ref_nature_work_proposed_id'] ?? null,
            'no_of_storeys' => $property['no_of_storeys'] ?? null,
            'basement_proposed' => $property['basement_proposed'] ?? null,
            'exceluding_height' => $property['exceluding_height'] ?? null,
            'including_height' => $property['including_height'] ?? null,
            'ref_proposed_work_id' => $property['ref_proposed_work_id'] ?? null,

            'approx_date_commencement' => $property['approx_date_commencement'] ?? null,
            'approx_duration_completion' => $property['approx_duration_completion'] ?? null,

            'created_by' => $this->userId,
            'created_on' => $this->now,
            'source_platform' =>  $this->sourcePlatform,
        ];


        if ($property['ref_property_owned_id'] == 7) {
            $payloads['other_property_owned'] = $property['other_property_owned'] ?? null;
        }

        if ($property['ref_proposed_work_id'] == 6) {
            $payloads['other_proposed_work'] = $property['other_proposed_work'] ?? null;
        }

        if ($property['ref_nature_work_proposed_id'] == 4) { // Others
            $payloads = array_merge($payloads, [
                'nature_of_work_other' => $property['nature_of_work_other'] ?? null,
            ]);
        }

        if ($property['ref_nature_work_proposed_id'] == 3) {

            $payloads = array_merge($payloads, [

                'existing_no_of_storeys' => $property['existing_no_of_storeys'] ?? null,

                'existing_floor_area' => $property['existing_floor_area'] ?? null,

                'existing_exceluding_height' => $property['existing_exceluding_height'] ?? null,

                'existing_including_height' =>   $property['existing_including_height'] ?? null,

                'existing_basement_proposed' => $property['existing_basement_proposed'] ?? null,

            ]);
        }

        $payloads['structure_type'] = $property['structure_type'] ?? null;

        if (($property['structure_type'] ?? null) == 2) {
            $payloads['structure_type_other'] = $property['structure_type_other'] ?? null;
            $payloads['structure_type_other_description'] = $property['structure_type_other_description'] ?? null;
        }

        $propertyRecord = Form1PropertyDetails::create($payloads);

        if (($property['structure_type'] ?? null) != 2 && !empty($property['buildings'])) {
            $this->saveBuildings($property['buildings'], $propertyRecord->id);
        }

        return self::getProperty();
    }

    private function saveBuildings(array $buildings, int $propertyId)
    {

        foreach ($buildings as $building) {

            $buildingRecord = Form1BuildingDetails::create([
                'application_no' => $this->applicationNo,
                'nma_form1_application_no_id' => $this->applicationId,
                // 'nma_form1_property_details_id' => $propertyId,

                'building_name' => $building['building_name'] ?? null,
                'building_use' => $building['building_use'] ?? null,
                'total_floors' => $building['total_floors'] ?? null,
                'total_height' => $building['total_height'] ?? null,
                'total_area' => $building['total_area'] ?? null,
                'created_by' => $this->userId,
                'created_on' => $this->now,

                'source_platform' =>  $this->sourcePlatform,
            ]);

            $this->saveFloors(
                $building['floors'] ?? [],
                $buildingRecord->id
            );
        }
    }

    private function saveFloors(array $floors, int $buildingId)
    {
        $data = [];

        foreach ($floors as $floor) {

            $data[] = [
                'application_no' => $this->applicationNo,
                'nma_form1_application_no_id' => $this->applicationId,
                'nma_form1_buildings_details_id' => $buildingId,

                'height' => $floor['height'] ?? null,
                'floor_area' => $floor['floor_area'] ?? null,
                'floor_name' => $floor['floor_name'] ?? null,

                'created_by' => $this->userId,
                'created_on' => $this->now,
                'source_platform' =>  $this->sourcePlatform,
            ];
        }

        if (!empty($data)) {
            Form1BuildingFloorDetails::insert($data);
        }
    }

    private function saveMonument(array $record)
    {

        $payload = [
            'application_no' => $this->applicationNo,
            'nma_form1_application_no_id' => $this->applicationId,

            'nma_cpm_history_id' => $record['nma_cpm_history_id'] ?? null,
            'locality' => $record['locality'] ?? null,
            'ref_state_id' => $record['ref_state_id'] ?? null,
            'ref_district_id' => $record['ref_district_id'] ?? null,
            'taluk' => $record['taluk'] ?? null,
            'distance_from_main_monument' => $record['distance_from_main_monument'] ?? null,
            'distance_from_boundary' => $record['distance_from_boundary'] ?? null,

            'created_by' => $this->userId,
            'created_on' => $this->now,
            'source_platform' =>  $this->sourcePlatform,
        ];

        Form1MonumentDetails::create($payload);

        return self::getMonument();
    }

    private function saveAdditionalInfo(array $record)
    {

        $payload = [
            'application_no' => $this->applicationNo,
            'nma_form1_application_no_id' => $this->applicationId,

            'max_height_near_monument' => $record['max_height_near_monument'] ?? null,
            'max_height_near_site' => $record['max_height_near_site'] ?? null,
            'ref_located_monument_id' => $record['ref_located_monument_id'] ?? null,
            'is_master_plan_exists' => $record['is_master_plan_exists'] ?? null,
            'modern_construction_status' => $record['modern_construction_status'] ?? null,
            'is_open_source_nearby' => $record['is_open_source_nearby'] ?? null,
            'is_road_exists_between_monument_and_site' => $record['is_road_exists_between_monument_and_site'] ?? null,
            'remarks' => $record['remarks'] ?? null,

            'created_by' => $this->userId,
            'created_on' => $this->now,
            'source_platform' => $this->sourcePlatform,
        ];
        Form1AdditionalDetails::create($payload);

        return self::getAdditionalInformation();
    }

    public function saveDocuments($records)
    {
        try {




            $payloads = [];

            foreach ($records as $documentType => $files) {

                if (empty($files) || !is_array($files)) {
                    continue;
                }

                foreach ($files as $file) {

                    $payloads[] = [
                        'application_no'            => $this->applicationNo,
                        'ref_document_type_id'      => $file['ref_document_type_id'],
                        'document_path'             => $file['url'],
                        'terms_conditions_accepted' => $file['terms_conditions_accepted'] ?? 1,
                        'gpa_spa_applicant_id'      => $this->getGpaSpaApplicantId($file),
                        'is_active'                 => 1,
                        'is_deleted'                => 0,
                        'created_by'                => $this->userId,
                        'created_on'                => $this->now,
                    ];
                }
            }

            if (!empty($payloads)) {
                Form1UploadDetails::insert($payloads);
            }

            return self::getDocument();
        } catch (Exception $ex) {
            throw $ex;
        }
    }


    private function getGpaSpaApplicantId(array $file): ?int
    {
        if (($file['ref_document_type_id'] ?? null) != 6) {
            return null;
        }

        if (empty($file['url'])) {
            return null;
        }

        $applicantId = $file['gpa_spa_applicant_id'] ?? null;

        if (empty($applicantId)) {
            throw new Exception('Applicant ID is required for GPA/SPA document.');
        }

        $application = $this->getApplicationOrFail($this->applicationNo);

        $applicants = $this->getApplicant();

        $this->applicantsMap = collect($applicants)
            ->pluck('id', 'id')
            ->toArray();

        if (!isset($this->applicantsMap[$applicantId])) {
            throw new Exception(
                "Applicant mapping not found for Applicant ID {$applicantId}."
            );
        }


        return $this->applicantsMap[$applicantId];

        // return $applicantId;
    }


    public function updateSendBack(Request $request,  $uuid)
    {

        $inputs = $request->all();

        $application = $this->getApplication($uuid);

        $commonService = app(CommonService::class);

        $formOneRequest = new FormOneRequest($commonService);

        $sendBack =  getApplicationFieldActions($application->application_no);

        $rules = $formOneRequest->getRules();

        $dynamicRules = [];

        foreach ($sendBack as $section => $records) {

            if (empty($rules[$section])) {
                continue;
            }

            $currentRules = $rules[$section];

            foreach ($records as $actions) {

                foreach ($actions as $action) {

                    $fieldName = $section . '.' . $action['field_name'];

                    $dynamicRules[$fieldName] = $currentRules[$fieldName];
                }
            }
        }

        $formOneRequest->validateDynamic(
            $inputs,
            $dynamicRules,
            $formOneRequest->messages()
        );



        print_r($inputs);

        // if ($validator->fails()) {
        //     return response()->json([
        //         'status' => false,
        //         'message' => 'Validation failed.',
        //         'errors' => $validator->errors(),
        //     ], 422);
        // }





        // return self::getAdditionalInformation();
    }

    // public function test(Request $request,  $uuid)
    // {
    //     $application = $this->getApplicationOrFail($uuid);

    //     $this->applicationNo = $application->application_no;

    //     $this->referenceNo   = $application->application_no;

    //     $data = $this->getData();

    //     $sendBack =  getApplicationFieldActions($application->application_no);

    //     print_r($sendBack);
    // }

    public function test(Request $request, $uuid)
    {
        $application = $this->getApplicationOrFail($uuid);

        $this->applicationNo = $application->application_no;

        $this->referenceNo = $application->application_no;

        $data = $this->getData();

        $this->now = now();

        $this->userId = userId();

        $sendBack = getApplicationFieldActions($application->application_no);

        $changed = $this->getChangedFields($request, $data, $sendBack);

        Form1FieldAction::insert($changed);

        // print_r($changed);
    }
    private function getChangedFields(Request $request,  array $data,  array $sendBack): array
    {

        $changed = [];

        $formOneTables = getFormOneTables(true);

        foreach ($request->only(array_keys($formOneTables)) as $tab => $requestData) {

            if (!isset($data[$tab])) {
                continue;
            }

            $currentData = $data[$tab];

            if ($this->isListOfRecords($requestData)) {

                foreach ($requestData as $requestKey => $requestRecord) {

                    if (!is_array($requestRecord)) {
                        continue;
                    }

                    $recordId = $requestRecord['id'] ?? $requestKey;

                    $currentRecord = $this->findRecord($currentData, $recordId);

                    if (!$currentRecord) {
                        continue;
                    }

                    $changed = array_merge(
                        $changed,
                        $this->compareRecord(
                            $tab,
                            $recordId,
                            $requestRecord,
                            $currentRecord,
                            $sendBack,
                            $formOneTables
                        )
                    );
                }

                continue;
            }

            if (!is_array($requestData) || !is_array($currentData)) {
                continue;
            }

            $recordId = $requestData['id'] ?? $currentData['id'] ?? null;

            $changed = array_merge(
                $changed,
                $this->compareRecord(
                    $tab,
                    $recordId,
                    $requestData,
                    $currentData,
                    $sendBack,
                    $formOneTables
                )
            );
        }


        return $changed;
    }



    private function compareRecord($tab,  $recordId, $requestRecord,  $currentRecord,  $sendBack, $formOneTables): array
    {

        $changed = [];

        foreach ($requestRecord as $fieldName => $newValue) {

            if ($fieldName === 'id') {
                continue;
            }

            if (!array_key_exists($fieldName, $currentRecord)) {
                continue;
            }

            $mainOldValue = $currentRecord[$fieldName];

            $oldValue = $this->getPreviousValue(
                $sendBack,
                $tab,
                $recordId ?? '',
                $fieldName,
                $mainOldValue
            );

            if ($this->valuesAreSame($oldValue, $newValue)) {
                continue;
            }

            $changed[] = [
                'application_no' => $this->applicationNo,
                'step_no' => 4,
                'action_type' => 'CA',
                'table_name' => $formOneTables[$tab] ?? $tab,
                'record_id'  => $recordId,
                'field_name' => $fieldName,
                'old_value'  => $oldValue ?? "test",
                'new_value'  => $newValue,
                'remark'  => "remark",
                'ref_non_single_window_status_id' => 3,
                "action_by" => $this->userId,
                "action_date" => $this->now,

            ];
        }

        return $changed;
    }

    private function isListOfRecords(mixed $data): bool
    {
        if (!is_array($data) || $data === []) {
            return false;
        }

        foreach ($data as $item) {
            if (!is_array($item)) {
                return false;
            }
        }

        return array_is_list($data);
    }

    private function findRecord(array $records, int|string $recordId): ?array
    {
        foreach ($records as $key => $record) {
            if (!is_array($record)) {
                continue;
            }

            if (isset($record['id']) && (string) $record['id'] === (string) $recordId) {
                return $record;
            }

            if ((string) $key === (string) $recordId) {
                return $record;
            }
        }
        return null;
    }


    private function getPreviousValue(array $sendBack, string $tableName, int|string $recordId, string $fieldName, mixed $defaultValue): mixed
    {

        if (!isset($sendBack[$tableName])) {
            return $defaultValue;
        }
        foreach ($sendBack[$tableName] as $groupKey => $actions) {
            if (!is_array($actions)) {
                continue;
            }
            $isMatchingRecord = (string) $groupKey === (string) $recordId;
            if (!$isMatchingRecord) {
                foreach ($actions as $action) {
                    if (!is_array($action)) {
                        continue;
                    }
                    $actionRecordId = $action['record_id'] ?? $action['table_record_id'] ?? $action['reference_id'] ?? null;
                    if ($actionRecordId !== null && (string) $actionRecordId === (string) $recordId) {
                        $isMatchingRecord = true;
                        break;
                    }
                }
            }
            if (!$isMatchingRecord) {
                continue;
            }
            foreach (array_reverse($actions) as $action) {
                if (!is_array($action)) {
                    continue;
                }
                if (($action['field_name'] ?? null) !== $fieldName) {
                    continue;
                }
                return $action['new_value'] ?? null;
            }
        }
        return $defaultValue;
    }
    private function valuesAreSame(mixed $oldValue, mixed $newValue): bool
    {

        if (($oldValue === null || $oldValue === '') && ($newValue === null || $newValue === '')) {
            return true;
        }
        if (is_numeric($oldValue) && is_numeric($newValue)) {
            return (float) $oldValue === (float) $newValue;
        }
        return (string) $oldValue === (string) $newValue;
    }
}

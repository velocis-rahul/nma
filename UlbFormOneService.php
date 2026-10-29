<?php

namespace App\Services;

use Exception;
use App\Classes\UlbResponseHandler;
use App\Models\Form1UploadDetails;
use App\Models\UlbArchitect;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class UlbFormOneService extends UlbBaseService
{
    public $application;

    public function index(Request $request)
    {
        return UlbResponseHandler::success([], 'Data fetched!');
    }

    public function show(Request $request, $uuid)
    {
        try {

            $collection =  $this->formOneService->collection($uuid);

            $results =  $this->normalize($collection);

            return UlbResponseHandler::success($results, 'Data fetched!');
        } catch (Exception $ex) {

            throw $ex;
        }
    }



    public function store(Request $request)
    {
        try {

            $payloads = $request->all();

            $data = [];

            $mapper = app(RequestMapperService::class);

            $config = config('key_mapping.form_one');

            if (!empty($payloads['applicant']) && is_array($payloads['applicant'])) {

                foreach ($payloads['applicant'] as &$applicant) {

                    $applicant['applicantTypeId'] =
                        $payloads['applicantTypeId'] ?? null;
                }

                unset($applicant);
            }

            foreach (['applicant', 'owner'] as $section) {

                if (!empty($payloads[$section]) && is_array($payloads[$section])) {

                    foreach ($payloads[$section] as $index => $item) {

                        if (is_array($item)) {
                            $payloads[$section][$index] = $mapper->map(
                                $item,
                                $config[$section] ?? []
                            );
                        }
                    }
                }
            }


            foreach (['property', 'monument', 'additionalInfo'] as $section) {

                if (!empty($payloads[$section]) && is_array($payloads[$section])) {

                    $payloads[$section] = $mapper->map(
                        $payloads[$section],
                        $config[$section] ?? []
                    );
                }
            }

            if (isset($payloads['additionalInfo'])) {
                $payloads['additional_info'] = $payloads['additionalInfo'];

                unset($payloads['additionalInfo']);
            }



            $result =  $this->formOneService->save($payloads);


            if (isset($payloads['architect'])) {

                $architect = $mapper->map(
                    $payloads['architect'],
                    $config['architect'] ?? []
                );

                $architect['nma_form1_application_no_id'] = $result['id'] ?? null;
                $architect['application_no'] = $result['application_no'] ?? null;
                $architect['user_ulbs_authentication_id'] = $payloads['partnerId'] ?? null;
                $architect['smarac_token'] = generateSmaracToken();

                $architectResult = UlbArchitect::create($architect);


                $result['architect'] = $architectResult->toArray();

                unset($payloads['architect']);
            }

            $data =  $this->normalize($result);

            return UlbResponseHandler::created($data, 'Data saved successfully!');
        } catch (Exception $ex) {

            throw $ex;
        }
    }

    public function normalize(array $result): array
    {

        $folder = $this->getApplicationFolderPath($result['uuid']);

        return [
            'uuid'  => $result['uuid'] ?? null,
            'applicationNo'  => $result['application_no'] ?? null,
            'createdAt'      => $result['created_on'] ?? null,
            'applicant'      => $this->normalizeApplicant($result['applicant'] ?? []),
            'owner'          => $this->normalizeOwner($result['owner'] ?? []),
            'property'       => $this->normalizeProperty($result['property'] ?? []),
            'monument'       => $this->normalizeMonument($result['monument'] ?? []),
            'additionalInfo' => $this->normalizeAdditionalInfo($result['additional_info'] ?? []),
            'architect'      => $this->normalizeArchitect($result['architect'] ?? []),
            'documents'      => $this->normalizeDocuments($result['documents'] ?? [], $folder),
        ];
    }



    private function normalizeApplicant(array $applicants): array
    {
        $mapping = array_flip(config('key_mapping.form_one.applicant'));

        return collect($applicants)->map(function ($applicant) use ($mapping) {

            $data = $this->mapFields($applicant, $mapping, [
                // 'id',
                'is_active',
                'is_migrated',
                'nma_form1_application_no_id',
                'application_no',
                'pan_verify',
                'source_platform'
            ]);


            if (($applicant['ref_applicant_type_id'] ?? 1) == 2) {


                $data['otherName'] = $applicant['applicant_first_name'] ?? null;

                $data['otherMobileNo'] = $applicant['applicant_mobile_no'] ?? null;

                $data['otherEmailId'] = $applicant['applicant_email_id'] ?? null;

                unset(
                    $data['applicantFirstName'],
                    $data['applicantMiddleName'],
                    $data['applicantLastName'],
                    $data['applicantMobileNo'],
                    $data['applicantEmailId']
                );
            }

            return $data;
        })->toArray();
    }

    private function normalizeOwner(array $owners): array
    {
        $mapping = array_flip(config('key_mapping.form_one.owner'));

        return collect($owners)->map(function ($owner) use ($mapping) {

            return $this->mapFields($owner, $mapping, [
                // 'id',
                'is_active',
                'is_migrated',
                'nma_form1_application_no_id',
                'application_no',
                'pan_verify',
                'source_platform'
            ]);
        })->toArray();
    }

    private function normalizeProperty(array $property): array
    {
        $mapping = array_flip(config('key_mapping.form_one.property'));

        $normalized = $this->mapFields($property, $mapping, [
            // 'id',
            'is_active',
            'is_migrated',
            'nma_form1_application_no_id',
            'application_no',
            'source_platform'
        ]);

        $normalized['building'] = $this->normalizeBuildings(
            $property['building'] ?? []
        );

        return $normalized;
    }

    private function normalizeBuildings(array $buildings): array
    {
        return collect($buildings)->map(function ($building) {

            return [
                'buildingName' => $building['building_name'] ?? null,
                'totalFloors'  => $building['total_floors'] ?? null,
                'totalArea'  => $building['total_area'] ?? null,
                'totalHeight'  => $building['total_height'] ?? null,

                'floors' => $this->normalizeFloors(
                    $building['floors'] ?? []
                ),
            ];
        })->toArray();
    }

    private function normalizeFloors(array $floors): array
    {
        return collect($floors)->map(function ($floor) {

            return [
                'floorArea' => $floor['floor_area'] ?? null,
                'height'    => $floor['height'] ?? null,
                'floorName'    => $floor['floor_name'] ?? null,
            ];
        })->toArray();
    }
    private function normalizeMonument(array $monument): array
    {
        $mapping = array_flip(config('key_mapping.form_one.monument'));

        return $this->mapFields($monument, $mapping, [
            // 'id',
            'is_active',
            'is_migrated',
            'nma_form1_application_no_id',
            'application_no',
            'source_platform'
        ]);
    }
    private function normalizeAdditionalInfo(array $additionalInfo): array
    {
        $mapping = array_flip(config('key_mapping.form_one.additionalInfo'));

        return $this->mapFields($additionalInfo, $mapping, [
            // 'id',
            'is_active',
            'is_migrated',
            'nma_form1_application_no_id',
            'application_no',
            'source_platform'
        ]);
    }
    private function normalizeArchitect(array $architect): array
    {
        $mapping = array_flip(config('key_mapping.form_one.architect'));

        return $this->mapFields($architect, $mapping, [
            // 'id',
            'is_active',
            'is_migrated',
            'updated_on',
            'created_on',
            'user_ulbs_authentication_id',
            // 'nma_form1_application_no_id',
            // 'application_no',
            // 'source_platform'
        ]);
    }

    private function normalizeDocuments(array $documents, $folder = "/uploads"): array
    {
        $normalized = [];

        $gpa_spa_applicant_id = null;

        foreach ($documents as $key => $files) {

            if ($key == 'terms_conditions_accepted') {
                continue;
            }

            if ($key == 'path') {
                continue;
            }


            $camelKey = Str::camel($key);

            $normalized[$camelKey] = collect($files)
                ->map(function ($file) use ($folder, &$gpa_spa_applicant_id) {

                    if (($file['ref_document_type_id'] ?? null) == 6) {
                        $gpa_spa_applicant_id = $file['gpa_spa_applicant_id'] ?? null;
                    }

                    return [
                        'url' => !empty($file['url'])
                            ? url(Storage::url($folder . "/" . $file['url']))
                            : null,
                    ];
                })
                ->toArray();
        }

        if (!empty($normalized)) {
            $normalized = array_merge($normalized, [
                'termCondition' => 1,
                "gpaSpaApplicantId" => $gpa_spa_applicant_id
            ]);
        }

        return $normalized;
    }



    public function documents(Request $request, $uuid)
    {
        try {

            $folder = $this->getApplicationFolderPath($uuid);

            $applicationNo = $this->application->application_no;

            $this->formOneService->applicationNo = $this->applicationNo = $applicationNo;

            $payloads = app(RemoteFileDownloader::class)
                ->downloadMultiple(
                    $request->all(),
                    $folder
                );

            $docs = $this->formOneService->saveDocuments($payloads['files']);

            $data = [
                'application_no' => $applicationNo,
                'documents' => $this->normalizeDocuments($docs, $folder),
            ];

            $this->application->updated_on = now();

            $this->application->ref_non_single_window_status_id = 2;

            $this->application->save();

            return UlbResponseHandler::updated(
                $data,
                'Document upload successfully!'
            );
        } catch (\Throwable $e) {

            return UlbResponseHandler::error(
                $e->getMessage(),
                422
            );
        }
    }

    protected function getApplicationFolderPath($uuid)
    {
        $application = $this->formOneService->getApplication($uuid);

        if (!$application) {
            throw new Exception("Invalid application number!");
        }

        $this->application = $application;

        return $this->getDocPath($application);
    }


    private function getDocPath($application)
    {
        $createdAt = Carbon::parse($application->created_on);

        return 'form1_uploads/' . $createdAt->format('Y') . '/' . $createdAt->format('m') . '/' . $application->application_no;
    }
}

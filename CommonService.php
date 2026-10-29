<?php

namespace App\Services;

use App\Classes\ResponseHandler;
use App\Models\AllDistrict;
use App\Models\AllState;
use App\Models\CpmHistory;
use App\Models\Master\RefApplicant;
use App\Models\Master\RefDocument;
use App\Models\Propertyowned;
use App\Models\RefApplicantTypeDetail;
use App\Models\RefArea;
use App\Models\RefCase;
use App\Models\RefMonumentLocated;
use App\Models\RefNatureWorkProposed;
use App\Models\RefProposedWork;
use App\Models\RefSalutation;
use App\Models\RefSiteType;
use App\Models\User;
use Exception;

class CommonService
{
    public function index()
    {
        $data =  $this->collection();

        return ResponseHandler::success($data, "Common data fetched!");
    }

    public function collection()
    {


        $cacheKey = 'master_data';

        $master =  getCache($cacheKey);

        if (!$master) {

            $master =  [
                'districts' => $this->getDistrict(),
                'states' => $this->getStates(),
                'cpm_history' => $this->getCpmHistory(),
                'property_owner_ship' => $this->getPropertyOwnerShip(),
                'salutations' => $this->getSalutations(),
                'nature_of_work' => $this->getNatureOfWork(),
                'proposed_of_work' => $this->getProposedWorkTypes(),
                'monument_located_limit' => $this->getMonumentLocatedLimits(),
                'documents' => $this->getDocuments(),
                'inspector' => $this->getInspector(),
                'action' => $this->getAction(),
                'area' => $this->getArea(),
                'site_type' => $this->getSiteType(),
                'site_inspection' => $this->getSiteInspection(),
                'applicant_type' => $this->getApplicantType(),
                'building_detail_types' => $this->getBuildingDetailTypes(),
                'applicant_type_details' => $this->getApplicantTypeDetail(),
                'storeys' => $this->getStoreys(),
                'cases' => $this->getCases(),
                'self_declaration' => getSelfDeclaration(),
            ];

            setCache($cacheKey, $master, 30 * 24 * 60);
        }

        return $master;
    }


    public function getDistrict()
    {
        $districts = AllDistrict::select("id", "district_code", "district_name", "ref_state_id")->orderBy('district_name', 'asc')->get();

        return $districts;
    }
    public function getStates()
    {

        $state = AllState::select("id", "state_code", "state_name", "state_short_name")->orderBy('state_name', 'asc')->get();

        return $state;
    }
    public function getCpmHistory()
    {

        $state = CpmHistory::select("id", "monument_name", "ref_state_id", "ref_district_id", "history_description", "local_name", "locality")
            ->orderBy('monument_name', 'asc')
            ->where('is_deleted', 0)
            ->get();

        return $state;
    }

    public function getPropertyOwnerShip()
    {
        $ownershipTypes = Propertyowned::select("id", "property_owned_name")
            ->orderByRaw("CASE WHEN LOWER(property_owned_name) = 'others' THEN 1 ELSE 0 END")
            ->orderBy('property_owned_name', 'asc')
            ->where('is_active', 1)
            ->where('is_deleted', 0)
            ->orderBy('property_owned_name', 'asc')->get();

        return $ownershipTypes;
    }
    public function getSalutations()
    {

        $salutation = RefSalutation::select("id", "salutation")
            ->where('is_active', 1)
            ->where('is_deleted', 0)
            ->get();

        return $salutation;
    }
    public static function getNatureOfWork()
    {

        $natureOfWork =   RefNatureWorkProposed::select("id", "nature_of_work_name")
            ->orderByRaw("CASE WHEN LOWER(nature_of_work_name) = 'others' THEN 1 ELSE 0 END")
            ->where('is_active', 1)
            ->where('is_deleted', 0)
            ->orderBy('nature_of_work_name', 'asc')->get();


        return $natureOfWork;
    }

    public static function getProposedWorkTypes()
    {

        $proposed_work =   RefProposedWork::select("id", "proposed_work_name")
            ->orderByRaw("CASE WHEN LOWER(proposed_work_name) = 'others' THEN 1 ELSE 0 END")
            ->orderBy('proposed_work_name', 'asc')
            ->where('is_active', 1)
            ->where('is_deleted', 0)
            ->get();

        return $proposed_work;
    }

    public static function getMonumentLocatedLimits()
    {
        $monumentLocatedLimit =   RefMonumentLocated::select("id", "located_monument_name")
            ->orderBy('located_monument_name', 'asc')
            ->where('is_active', 1)
            ->where('is_deleted', 0)
            ->get();

        return $monumentLocatedLimit;
    }
    public function getDocuments($docType = DOCUMENT_FORM_ONE)
    {

        try {

            $documents = RefDocument::select('id', 'document_name', 'is_mandatory', 'is_multiple_upload', 'extension_type', 'document_size')->where('document_type', $docType)
                ->where('is_active', 1)
                ->orderBy('order_by')
                ->get();

            $documents  =  $documents->map(function ($document) {

                $document->field_name = $this->formatFieldName(
                    $document->document_name
                );

                return $document;
            })->toArray();


            return $documents;
        } catch (Exception $ex) {

            return [];
        }
    }

    public function getSiteInspection()
    {
        return $this->getDocuments(DOCUMENT_SITE_INSPECTION);
    }
    public function getApplicantType()
    {
        try {

            $appType = RefApplicant::select(
                'id',
                'applicant_type'
            )->get();

            return $appType->toArray();
        } catch (Exception $ex) {
            return [];
        }
    }
    public function getApplicantTypeDetail()
    {
        try {

            $appTypeDetail = RefApplicantTypeDetail::select(
                'id',
                'ref_applicant_type_id',
                'applicant_type_detail'
            )->get();

            return $appTypeDetail->toArray();
        } catch (Exception $ex) {
            return [];
        }
    }
    public function getInspector()
    {
        try {

            $roles = ['ca', 'sa', 'surveyor'];

            $user  = user();

            $circleId = $user?->ref_circle_id;

            $inspectors = User::select(
                'id',
                'first_name',
                'middle_name',
                'last_name',
                'email',
            )->whereHas('role', function ($query) use ($roles) {
                $query->whereIn('name', $roles);
            })->where('ref_circle_id', $circleId)
                ->where('ref_status_id', 1)
                ->get();

            return $inspectors->toArray();
        } catch (Exception $ex) {
            return [];
        }
    }

    public function getAction()
    {
        return [
            [
                'key' => 'assign',
                'name' => 'Assign',
            ],
            [
                'key' => 'send_back',
                'name' => 'Send Back',
            ],
        ];
    }

    private function formatFieldName(string $docName)
    {

        $name = trim(strtok($docName, '('));

        $name = strtolower($name);

        $name = preg_replace('/[^a-z0-9]+/', '_', $name);

        return trim($name, '_');
    }


    public function getRules($documentName = DOCUMENT_FORM_ONE)
    {

        $rules = [];

        $documents = $this->getDocuments($documentName);

        foreach ($documents as $document) {

            $field = $document['field_name'];

            if ($document['is_multiple_upload']) {

                $rules[$field] = $document['is_mandatory']
                    ? 'required|array|min:1'
                    : 'nullable|array';

                $rules[$field . '.*'] = 'required|file|mimes:pdf,jpg,jpeg,png';
            } else {

                $rules[$field] = $document['is_mandatory']
                    ? 'required|file|mimes:pdf,jpg,jpeg,png'
                    : 'nullable|file|mimes:pdf,jpg,jpeg,png';
            }
        }

        return $rules;
    }

    public function getSiteType()
    {
        $siteType = RefSiteType::select("id", "site_type")->get();

        return $siteType;
    }
    public function getArea()
    {
        $area = RefArea::select("id", "proposed_area")->get();

        return $area;
    }
    public function getBuildingDetailTypes()
    {

        return [
            [
                'id' => 1,
                'name' => 'Building',
            ],
            [
                'id' => 2,
                'name' => 'Others',
            ],
        ];
    }
    public function getStoreys()
    {

        $storeys = [];

        for ($i = 1; $i <= 30; $i++) {
            $storeys[] = [
                'id' => $i,
                'name' => $i,
            ];
        }

        return $storeys;
    }
    public function getCases()
    {
        $result = RefCase::select('id', 'cases')->where('is_deleted', 0)->where('is_active', 1)->get();
        return $result;
    }
}

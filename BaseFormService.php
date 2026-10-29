<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Helpers\PaginationHelper;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use App\Models\{Form1ApplicationNo, Form1Draft};

abstract class BaseFormService
{
    public function __construct(public CommonService $commonService) {}
    public $application;
    protected const HIDDEN_FIELDS = [
        'created_by',
        // 'created_on',
        'updated_by',
        'updated_on',
        'deleted_by',
        'deleted_on',
        'is_deleted',
    ];

    protected const ROLE_STATUS_MAP = [
        'surveyor' => 6, // Pending At Surveyor
        'sa'       => 7, // Pending At SA
        'ca'       => 8, // Pending At CA
    ];

    protected function hideFields($data, array $fields = [])
    {
        if ($data) {
            $data->makeHidden($fields);
        }

        return $data;
    }

    protected function encodeField(array $fields): array
    {
        return array_map(
            fn($value) => json_encode($value),
            $fields
        );
    }

    protected function decodeField($data, array $fields)
    {
        foreach ($fields as $field) {
            $data[$field] = !empty($data[$field])
                ? json_decode($data[$field], true)
                : null;
        }

        return $data;
    }

    public function getCollection(string $model, string $column)
    {

        return $model::where($column, $this->referenceNo)->get();
    }

    public function getSingle(string $model, string $column)
    {
        $data = $model::where($column, $this->referenceNo)->first();

        return $data;
    }

    public function getApplication($uuid)
    {
        $this->application = Form1ApplicationNo::where([
            'uuid' => trim($uuid)
        ])->orWhere('application_no', $uuid)->first();

        return $this->application;
    }


    private static function formatData($item, bool $isDraft = true): array
    {
        $location = null;

        if ($item->property) {

            $location = implode(', ', array_filter([
                $item->property->plot_survey_no ?? null,
                $item->property->district->district_name ?? null,
                $item->property->state->state_name ?? null,
                $item->property->taluk ?? null,
                $item->property->locality ?? null,
            ]));
        }


        $data = [
            'id' => $item->id,
            'uuid' => $item->uuid,
            'monument_name' => $item->monoment?->nma_cpm_history?->monument_name,
            'created_on' => formatDate($item->created_on),
            'location' => $location,
            'status' => ($item->status->status ?? 'Draft') . ', ' .
                formatDateTime($item->updated_on ?? $item->created_on),
            'remarks' => '-',
            'valid_till' => '-',
        ];

        if ($isDraft) {
            $data += [
                'draft_no' => $item->draft_no,
                'nma_form1_applicant_draft_details_id' => $item->nma_form1_applicant_draft_details_id,
                'uuid' => $item->uuid,
            ];
        } else {
            $data += [
                'draft_no' => $item->draft_no,
                'nma_form1_applicant_draft_details_id' => $item->nma_form1_applicant_draft_details_id,
                'application_no' => $item->application_no,
            ];
        }

        return $data;
    }

    private function applyCommonFilters($query, Request $request): void
    {
        $query->where('is_deleted', 0);


        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        // if ($request->filled('ref_non_single_window_status_id')) {
        //     $query->where('ref_non_single_window_status_id',  $request->ref_non_single_window_status_id);
        // }

        if ($request->filled('ref_non_single_window_status_id')) {

            $statusIds = (array) $request->ref_non_single_window_status_id;

            $query->whereIn('ref_non_single_window_status_id', $statusIds);
        }
    }

    private function transformPaginator($paginator, bool $isDraft)
    {
        $paginator->getCollection()->transform(
            fn($item) => self::formatData($item, $isDraft)
        );

        return PaginationHelper::format($paginator);
    }


    protected function getDraftApplications(Request $request)
    {
        $query = Form1Draft::with([
            'property:id,draft_no,ref_nature_work_proposed_id,ref_state_id,ref_district_id,plot_survey_no,locality',
            'property.district:id,district_name',
            'property.state:id,state_name,state_short_name',
            'monoment.nma_cpm_history:id,monument_name',
            'status',
        ])
            ->orderByDesc('id');

        $this->applyRoleFilter($query);

        $this->applyCommonFilters($query, $request);

        $paginator = $this->paginate($query, $request);

        return $this->transformPaginator($paginator, true);
    }

    protected function getSubmittedApplications(Request $request)
    {
        $query =  $this->getApplicationBaseQuery($request, [
            'property:id,application_no,ref_nature_work_proposed_id,ref_state_id,ref_district_id,plot_survey_no,locality',
            'property.district:id,district_name',
            'property.state:id,state_name,state_short_name',
            'monoment.nma_cpm_history:id,monument_name',
            'status',
        ]);

        $this->applyRoleFilter($query);

        $this->applyCommonFilters($query, $request);

        $paginator = $this->paginate($query, $request);

        return $this->transformPaginator($paginator, false);
    }

    protected function getApplicationPermission(Request $request)
    {
        $query =  $this->getApplicationBaseQuery($request, [
            'property:id,application_no,ref_nature_work_proposed_id,ref_state_id,ref_district_id,plot_survey_no,locality',
            'property.district:id,district_name',
            'property.state:id,state_name,state_short_name',
            'monoment.nma_cpm_history:id,monument_name',
            'status',
            'ulb_user:id,name,department',
        ]);

        $request->merge(['ref_non_single_window_status_id' => [37, 38, 39]]);

        $this->applyRoleFilter($query);

        $this->applyCommonFilters($query, $request);

        $paginator = $this->paginate($query, $request);

        return $this->transformPaginator($paginator, false);
    }

    protected function getSiteInspectionBaseQuery(Request $request, $relations = [])
    {
        $districtIds = getAccessibleDistrictIds();

        $query =  $this->getApplicationBaseQuery($request, [
            'property:id,application_no,ref_nature_work_proposed_id,ref_state_id,ref_district_id,plot_survey_no,locality,ref_property_owned_id,ref_proposed_work_id',
            'property.district:id,district_name',
            'property.state:id,state_name,state_short_name',
            'property.nature_work_proposed:id,nature_of_work_name',
            'monoment:id,application_no,locality,nma_cpm_history_id',
            'monoment.nma_cpm_history:id,monument_name',
            'status:id,status,type,display_status',
            'ulb_user:id,name,department',
            'assigned_to:id,first_name,last_name,middle_name'
        ]);

        $query->whereHas('property', function ($query) use ($districtIds) {
            $query->whereIn('ref_district_id', $districtIds);
        });

        return $query;
    }


    protected function getCaBaseQuery(Request $request)
    {
        $districtIds = getAccessibleDistrictIds();

        $query =  $this->getApplicationBaseQuery($request, [
            'property:id,application_no,ref_nature_work_proposed_id,ref_state_id,ref_district_id,plot_survey_no,locality',
            'property.district:id,district_name',
            'property.state:id,state_name,state_short_name',
            'property.nature_work_proposed:id,nature_of_work_name',
            'monoment:id,application_no,locality,nma_cpm_history_id',
            'monoment.nma_cpm_history:id,monument_name',
            'status:id,status,type,display_status',
        ]);

        $query->whereHas('property', function ($query) use ($districtIds) {
            $query->whereIn('ref_district_id', $districtIds);
        });

        return $query;
    }


    protected function getApplicationBaseQuery(Request $request, array $relations = [])
    {
        $query = Form1ApplicationNo::select(
            "id",
            "application_no",
            "created_on",
            "is_migrated",
            "uuid",
            "ref_non_single_window_status_id",
        )->where('is_deleted', 0);

        $query->with($relations);

        $this->applyRoleFilter($query);

        $this->applySearch($query, $request);

        return $query->latest();
    }

    private function applyRoleFilter($query): void
    {

        $userId =  userId();

        if (userAccess()->isSa()) {
            $query->whereIn('ref_non_single_window_status_id',  SITE_INSPECTION_STATUSES)
                ->where('assign_to', $userId);
        }

        if (userAccess()->isCa()) {
            $query->whereNotIn('ref_non_single_window_status_id', CA_STATUSES);
        }

        if (userAccess()->isApplicant()) {

            $query->where('created_by', $userId);
        }

        if (userAccess()->isDeo()) {

            $districtIds = getAccessibleDistrictIds();

            $query->whereHas('property', function ($query) use ($districtIds) {
                $query->whereIn('ref_district_id', $districtIds);
            });
        }
    }


    private function applySearch($query, Request $request): void
    {
        if (!$request->filled('search')) {
            return;
        }

        $search = trim($request->search);

        $query->where(function ($q) use ($search) {
            $q->where('application_no', 'like', "$search%")
                ->orWhereHas('monoment.nma_cpm_history', function ($q) use ($search) {
                    $q->where('monument_name', 'like', "{$search}%");
                })
                ->orWhereHas('monoment', function ($q) use ($search) {
                    $q->where('locality', 'like', "{$search}%");
                });
        });
    }

    protected function paginate($query, Request $request)
    {
        $paginator = $query->paginate($request->per_page ?? DEFAULT_PER_PAGE);

        return $paginator;
    }

    protected function getDraftDocPath($draft)
    {
        $createdAt = Carbon::parse($draft->created_on);

        return 'form1_uploads/' . $createdAt->format('Y') . '/' . $createdAt->format('m') . '/' . $draft->draft_no;
    }

    protected function getApplicationDocPath($application)
    {
        $createdAt = Carbon::parse($application->created_on);

        return 'form1_uploads/' . $createdAt->format('Y') . '/' . $createdAt->format('m') . '/' . $application->application_no;
    }

    protected function getSiteInspectionDocPath($siteInspection)
    {
        return 'uploads/site_inspection/' . $siteInspection->id;
    }

    protected function normalize($model)
    {
        if (!$model) {
            return null;
        }

        $this->hideRelationsFields($model);

        return $model;
    }

    protected function hideRelationsFields($model): void
    {
        $this->hideFields($model, self::HIDDEN_FIELDS);

        foreach ($model->getRelations() as $relation) {

            if ($relation instanceof Model) {
                $this->hideRelationsFields($relation);
            }

            if ($relation instanceof Collection) {
                $relation->each(function ($item) {
                    if ($item instanceof Model) {
                        $this->hideRelationsFields($item);
                    }
                });
            }
        }
    }
}

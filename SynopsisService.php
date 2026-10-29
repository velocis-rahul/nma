<?php

namespace App\Services;

use Exception;
use App\Classes\ResponseHandler;
use App\Helpers\PaginationHelper;
use App\Models\NmaSynopsis;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SynopsisService extends BaseFormService
{

    public function index(Request $request)
    {

        $query = $this->baseQuery();

        $query->with([
            'case:id,cases',
            'application.property:id,ref_state_id',
            'application:id,application_no,created_on,updated_on',
            'application.monoment',
        ]);


        // $this->applyRoleFilter($query);

        $this->applyFilters($query, $request);

        $paginator = $query->paginate($request->per_page ?? DEFAULT_PER_PAGE);

        $paginator->getCollection()->transform(function ($item) {
            return self::formatData($item);
        });

        $synopsis = PaginationHelper::format($paginator);

        return ResponseHandler::success(
            $synopsis,
            'Synopsis fetched!'
        );
    }

    public function show(Request $request, $synopsisId = null)
    {
        $query = $this->baseQuery();

        $result =  $result = $query->with([
            'application',
            'application.applicant',
            'application.property',
            'application.monoment',
            'application.site_inspection',
        ])
            ->where('id', $synopsisId)
            ->first();

        $result = $this->normalizeSynopsis($result);

        return ResponseHandler::success(
            $result,
            'Synopsis fetched!'
        );
    }


    private function baseQuery()
    {
        return NmaSynopsis::select([
            'id',
            'synopsis_batch_id',
            'ref_cases_id',
            'created_on',
            'ref_non_single_window_status_id',
            'nma_form1_application_no_id',
            'comment',
            'current_information',
            'additional_information',

            DB::raw("
                COALESCE(
                    (
                        SELECT MAX(action_date)
                        FROM nma_synopsis_forward_log
                        WHERE nma_synopsis_forward_log.nma_synopsis_id = nma_synopsis.id
                    ),
                    nma_synopsis.created_on
                ) AS latest_action_date
            "),
        ])->with([

            'status:id,status',
        ])
            ->latest('created_on');
    }

    private function applyRoleFilter($query): void
    {

        $userId =  userId();


        if (userAccess()->isHbl() || userAccess()->isNocIncharge() || userAccess()->isLegal()) {

            $query->whereHas('forward', function ($q) use ($userId) {
                $q->where('to_user', $userId)->where('ref_non_single_window_status_id', 34);
            });
        }

        if (userAccess()->isRgp()) {

            $query->where('ref_non_single_window_status_id', 40);
        }

        if (userAccess()->isDeo()) {

            $districtIds = getDistrictIdsByStateId();

            $query->where('ref_non_single_window_status_id', 24)
                ->where('created_by', $userId)
                ->whereHas('application.property', function ($q) use ($districtIds) {
                    $q->whereIn('ref_district_id', $districtIds);
                });
        }
    }

    private function applyFilters($query, Request $request): void
    {
        $query->where('is_deleted', 0);

        if ($request->filled('from_created_on')) {
            $query->whereDate('created_on', '>=', $request->from_created_on);
        }

        if ($request->filled('to_created_on')) {
            $query->whereDate('created_on', '<=', $request->to_created_on);
        }
    }

    private function  formatData($item): array
    {

        $application = $item->application;

        $monument    = $application?->monoment;

        $property    = $application?->property;

        return [
            'id'            => $item->id,

            'synopsis' => [
                'created_on' => formatDate($item->created_on),
                'application_no' =>  $item?->synopsis_batch_id
            ],

            'application' => [
                'created_on' => formatDate($application->created_on),
                'application_no' =>  $application?->application_no
            ],

            'status'        =>
            [
                'status_name' =>         $item->status?->status,
                'latest_action_date' => formatDate($item?->latest_action_date)
            ],
            'case'          => $item->case?->cases,
            'monument_name' => $monument?->nma_cpm_history?->monument_name,
            'state_name'   => $property?->state?->state_name,
        ];
    }

    private function normalizeSynopsis($result): ?array
    {
        if (!$result) {
            return null;
        }

        $application = $result->application;


        return [
            'id' => $result->id,

            'synopsis_batch_id' => $result->synopsis_batch_id,
            'ref_cases_id' => $result->ref_cases_id,
            'created_on' => formatDate($result->created_on),
            'ref_non_single_window_status_id' => $result->ref_non_single_window_status_id,
            'nma_form1_application_no_id' => $result->nma_form1_application_no_id,
            'latest_action_date' => formatDate($result->latest_action_date),

            'comment' => $result->comment,
            'current_information' => $result->current_information,
            'additional_information' => $result->additional_information,


            'application' => $application ? [
                'id' => $application->id,
                'application_no' => $application->application_no,
                'created_on' => formatDate($application->created_on),
                'updated_on' => formatDate($application->updated_on),

                'applicant' => $this->normalizeApplicant(
                    $application->applicant
                ),

                'property' => $this->normalizeProperty(
                    $application->property
                ),

                'monument' => $this->normalizeMonument(
                    $application->monoment
                ),

                'site_inspection' => $this->normalizeSiteInspection(
                    $application->site_inspection
                ),
            ] : null,
        ];
    }

    private function normalizeApplicant($applicants): array
    {
        return $applicants->map(function ($applicant) {
            return $applicant->only([
                'id',
                'present_plot_survey_no',
                'present_ref_state_id',
                'present_ref_district_id',
                'present_taluk',
                'present_locality',
            ]);
        })->values()->all();
    }

    private function normalizeProperty($property): ?array
    {
        if (!$property) {
            return null;
        }

        return $property->only([
            'id',
            'ref_property_owned_id',
            'plot_survey_no',
            'ref_state_id',
            'ref_district_id',
            'taluk',
            'locality',
            'ref_nature_work_proposed_id',
            'approx_date_commencement',
            'other_proposed_work',
            'exceluding_height',
            'including_height',
            'ref_proposed_work_id',
        ]);
    }

    private function normalizeMonument($monument): ?array
    {
        if (!$monument) {
            return null;
        }

        return $monument->only([
            'id',
            'nma_cpm_history_id',
            'locality',
            'ref_state_id',
            'ref_district_id',
            'taluk',
            'distance_from_main_monument',
            'distance_from_boundary',
        ]);
    }

    private function normalizeSiteInspection($inspection): ?array
    {
        if (!$inspection) {
            return null;
        }

        return [
            'id' => $inspection->id,
            'created_on' => formatDate($inspection->created_on),
            'updated_on' => formatDate($inspection->updated_on),
            'uuid' => $inspection->uuid,
            'ref_non_single_window_status_id' => $inspection->ref_non_single_window_status_id,
        ];
    }
}

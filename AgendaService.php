<?php

namespace App\Services;

use Exception;
use App\Classes\ResponseHandler;
use App\Helpers\PaginationHelper;
use App\Models\AgendaModel;
use Illuminate\Http\Request;

class AgendaService extends BaseFormService
{
    public function index(Request $request)
    {

        $query = $this->baseQuery();

        $query->with([
            'synopsis.application:id,application_no,uuid',
        ]);

        $this->applyFilters($query, $request);

        $paginator = $query->paginate($request->per_page ?? 100);

        $paginator->getCollection()->transform(function ($item) {

            return $this->formatData($item);
        });

        $agenda = PaginationHelper::format($paginator);

        return ResponseHandler::success(
            $agenda,
            'Agenda fetched!'
        );
    }

    private function baseQuery()
    {
        $query = AgendaModel::query()
            ->select([
                'id',
                'rgp_meeting_no',
                'meeting_datetime',
                'venue',
                'ref_non_single_window_status_id',
                'created_on',
            ])
            ->with('status:id,status')
            ->withCount('synopsis as total_cases')
            ->latest('created_on');

        return $query;
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

        $applications = $item->synopsis
            ->pluck('application')
            ->filter()
            ->values()
            ->all();

        return [
            'id' => $item->id,
            'rgp_meeting_no' => $item->rgp_meeting_no,
            'meeting_datetime' => formatDate($item->meeting_datetime),
            'venue' => $item->venue,
            'created_on'        => formatDate($item->created_on),
            'total_cases'       => $item->total_cases,
            'applications'       => $applications,
            'status'            => $item->status?->status,
        ];
    }


    public function show(Request $request, $agendaId = 0)
    {
        try {

            $query = $this->baseQuery();

            $result = $query->with([
                'agenda_synopsis',
                'agenda_synopsis.application',
                'agenda_synopsis.synopsis',
            ])->where("id", $agendaId)->first();


            if (!$result) {
                return ResponseHandler::error(
                    null,
                    'Agenda not found!'
                );
            }

            $caseCounts = $this->getCaseCounts(
                $result->agenda_synopsis
            );

            $response = [];

            foreach ($result->agenda_synopsis as $item) {

                $caseId = $item->synopsis?->ref_cases_id;

                $application = $item->application;

                $data = [
                    "synopsis" => [
                        "id" => $item->synopsis?->id,
                        "status_of_hbl" => $item->synopsis?->status_of_hbl,
                        "ref_cases_id" => $item->synopsis?->ref_cases_id,
                        "created_on" => formatDate($item->synopsis?->created_on),
                        "updated_on" => formatDate($item->synopsis?->updated_on),
                        "synopsis_batch_id" => $item->synopsis?->synopsis_batch_id,
                        "additional_information" => $item->synopsis?->additional_information,
                        "current_information" => $item->synopsis?->current_information,
                        "maximum_height_and_distance_site" => $item->synopsis?->maximum_height_and_distance_site,
                        "no_of_cases_rcmd_near_cpm_by_nma" => $item->synopsis?->no_of_cases_rcmd_near_cpm_by_nma,
                    ],

                    "application" => [
                        "id" => $application?->id,
                        "uuid" => $application?->uuid,
                        "application_no" => $application?->application_no,
                        "created_on" => formatDate($application?->created_on),
                        "updated_on" => formatDate($application?->updated_on),
                        "ref_non_single_window_status_id" =>
                        $application?->ref_non_single_window_status_id,
                    ],

                    'applicants' => $application?->applicant?->map(
                        function ($applicant) {
                            return [
                                'id' => $applicant->id,
                                'first_name' => $applicant->applicant_first_name,
                                'middle_name' => $applicant->applicant_middle_name,
                                'last_name' => $applicant->applicant_last_name,
                            ];
                        }
                    ),

                    'property' => [
                        'plot_survey_no' => $application?->property?->plot_survey_no,
                        'taluk' => $application?->property?->taluk,
                        'locality' => $application?->property?->locality,
                        'district' => $application?->property?->district?->district_name,
                        'ref_state_id' => $application?->property?->ref_state_id,
                        'ref_district_id' => $application?->property?->ref_district_id,
                        'state' => $application?->property?->state?->state_name,
                        'ref_property_owned_id' => $application?->property?->ref_property_owned_id,
                    ],

                    'additional_info' => [
                        'max_height_near_monument' =>
                        $application?->additional_info?->max_height_near_monument,
                        'max_height_near_site' =>
                        $application?->additional_info?->max_height_near_site,
                    ],

                    'monoment' => [
                        'locality' => $application?->monoment?->locality,
                        'district' => $application?->monoment?->district?->district_name,
                        'state' => $application?->monoment?->state?->state_name,
                        'distance_from_main_monument' =>
                        $application?->monoment?->distance_from_main_monument,
                    ],

                    'site_inspection' => [
                        'remarks' => $application?->site_inspection?->remarks,
                        'date_of_inspection' =>
                        formatDate($application?->site_inspection?->date_of_inspection),
                    ],
                ];


                if (isset($response[$caseId])) {

                    $response[$caseId][] = $data;
                } else {

                    $response[$caseId] = [$data];
                }
            }


            $response = [
                'case_counts' => $caseCounts,
                'data' => $response,
            ];

            return ResponseHandler::success(
                $response,
                'Data fetched!'
            );
        } catch (Exception $ex) {

            throw $ex;
        }
    }

    public function getCaseCounts($agendaSynopsis)
    {
        $cases = $this->commonService->getCases()->keyBy('id');

        $counts = $agendaSynopsis
            ->groupBy(fn($item) => $item->synopsis?->ref_cases_id)
            ->map(fn($items) => $items->count());

        return $cases
            ->filter(function ($case, $caseId) use ($counts) {
                return $counts->get($caseId, 0) > 0;
            })
            ->map(function ($case, $caseId) use ($counts) {


                return [
                    'id' => (int) $caseId,
                    'case' => $case->cases,
                    'count' => $counts->get($caseId, 0),
                ];
            })
            ->values();
    }
}

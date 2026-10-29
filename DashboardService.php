<?php

namespace App\Services;

use App\Classes\ResponseHandler;
use App\Models\Form1ApplicationNo;
use App\Models\SiteInspection;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardService extends BaseFormService
{

    public const STATUS_PENDING_SCRUTINY        = 2;
    public const STATUS_RETURNED_TO_APPLICANT   = 3;
    public const STATUS_INSPECTION_PENDING      = 8;
    public const STATUS_FORM_TWO_SUBMITTED         = 9;
    public const STATUS_REFUSED                 = 10;
    public const STATUS_SENT_BACK_BY_NMA        = 19;
    public function index(Request $request)
    {
        $query = $this->baseQuery();

        $results = [];

        if (userAccess()->isApplicant()) {

            $userId = userId();

            $query->where('created_by', $userId);

            $counts = $query->selectRaw("
                COUNT(*) as total,
                COUNT(CASE WHEN ref_non_single_window_status_id = 2 THEN 1 END) as pending,
                COUNT(CASE WHEN ref_non_single_window_status_id = 37 OR ref_non_single_window_status_id = 38 THEN 1 END) as approved,
                COUNT(CASE WHEN ref_non_single_window_status_id = 3 THEN 1 END) as returned,
                COUNT(CASE WHEN ref_non_single_window_status_id = 39 THEN 1 END) as refused
            ")->first();

            $request->merge([
                'per_page' => $request->input('per_page', 5),
            ]);


            $draftApplications = $this->getDraftApplications($request);

            $results = [
                'dashboard' => [
                    'total' => (int) $counts->total,
                    'pending' => [
                        'ref_non_single_window_status_id' => 2,
                        'count' => (int) $counts->pending,
                    ],

                    'approved' => [
                        'ref_non_single_window_status_id' => 12,
                        'count' => (int) $counts->approved,
                    ],

                    'returned' => [
                        'ref_non_single_window_status_id' => 3,
                        'count' => (int) $counts->returned,
                    ],
                    'refused' => [
                        'ref_non_single_window_status_id' => 17,
                        'count' => (int) $counts->refused,
                    ],
                ],
                'applications' => $draftApplications,
            ];
        }

        if (userAccess()->isCa()) {

            $districtIds = getAccessibleDistrictIds();

            $query->whereHas('property', function ($q) use ($districtIds) {
                $q->whereIn('ref_district_id', $districtIds);
            });

            $counts = $query->selectRaw('
                COUNT(*) as total_application_received,

                COUNT(
                    CASE
                        WHEN ref_non_single_window_status_id = ' . self::STATUS_PENDING_SCRUTINY . '
                        THEN 1
                    END
                ) as applications_pending_scrutiny,

                COUNT(
                    CASE
                        WHEN ref_non_single_window_status_id = ' . self::STATUS_RETURNED_TO_APPLICANT . '
                        THEN 1
                    END
                ) as applications_returned_to_applicant,

                COUNT(
                    CASE
                        WHEN ref_non_single_window_status_id = ' . self::STATUS_INSPECTION_PENDING . '
                        THEN 1
                    END
                ) as inspection_pending_assignment,

                COUNT(
                    CASE
                        WHEN ref_non_single_window_status_id = ' . self::STATUS_FORM_TWO_SUBMITTED . '
                        THEN 1
                    END
                ) as form_two_submitted,

                COUNT(
                    CASE
                        WHEN ref_non_single_window_status_id = ' . self::STATUS_REFUSED . '
                        THEN 1
                    END
                ) as refused_applications,

                COUNT(
                    CASE
                        WHEN ref_non_single_window_status_id = ' . self::STATUS_SENT_BACK_BY_NMA . '
                        THEN 1
                    END
                ) as sent_back_by_nma
            ')->first();

            $results = [
                'dashboard' => [
                    'total_application_received' => (int) $counts->total_application_received,

                    'applications_pending_scrutiny' => [
                        'ref_non_single_window_status_id' => self::STATUS_PENDING_SCRUTINY,
                        'count' => (int) $counts->applications_pending_scrutiny,
                    ],

                    'applications_returned_to_applicant' => [
                        'ref_non_single_window_status_id' => self::STATUS_RETURNED_TO_APPLICANT,
                        'count' => (int) $counts->applications_returned_to_applicant,
                    ],

                    'inspection_pending_assignment' => [
                        'ref_non_single_window_status_id' => self::STATUS_INSPECTION_PENDING,
                        'count' => (int) $counts->inspection_pending_assignment,
                    ],

                    'form_two_submitted' => [
                        'ref_non_single_window_status_id' => self::STATUS_FORM_TWO_SUBMITTED,
                        'count' => (int) $counts->form_two_submitted,
                    ],

                    'refused_applications' => [
                        'ref_non_single_window_status_id' => self::STATUS_REFUSED,
                        'count' => (int) $counts->refused_applications,
                    ],

                    'sent_back_by_nma' => [
                        'ref_non_single_window_status_id' => self::STATUS_SENT_BACK_BY_NMA,
                        'count' => (int) $counts->sent_back_by_nma,
                    ],
                ],

                'applications' => $this->getSubmittedApplications($request)
            ];
        }
        if (userAccess()->isDeo()) {

            $districtIds = getAccessibleDistrictIds();

            $query->whereHas('property', function ($q) use ($districtIds) {
                $q->whereIn('ref_district_id', $districtIds);
            });

            $counts =  $query->selectRaw('
                COUNT(*) as total_application_received,

                COUNT(
                    CASE
                        WHEN ref_non_single_window_status_id = ' . self::STATUS_INSPECTION_PENDING . '
                        THEN 1
                    END
                ) as inspection_pending_at_ca

                
            ')->first();

            $pending_inspections =  SiteInspection::whereHas(
                'applications',
                function ($query) use ($districtIds) {
                    $query->where('is_deleted', 0)
                        ->whereHas('property', function ($query) use ($districtIds) {
                            $query->whereIn('ref_district_id', $districtIds);
                        });
                }
            )
                ->where('ref_non_single_window_status_id', '!=', self::STATUS_FORM_TWO_SUBMITTED)
                ->count();

            $users = User::count();


            $results = [
                "dashboard" => [
                    'total_users'          => $users,

                    'total_applications'   => (int) $counts->total_application_received,

                    'pending_ca'           => [
                        'ref_non_single_window_status_id' => self::STATUS_PENDING_SCRUTINY,
                        'count' =>  (int) $counts->inspection_pending_at_ca,
                    ],

                    'pending_inspections'  =>  [
                        'ref_non_single_window_status_id' => self::STATUS_FORM_TWO_SUBMITTED,
                        'count' =>  (int) $pending_inspections,
                    ],

                ],
                "applications" =>  $this->getSubmittedApplications($request)
            ];
        }

        return ResponseHandler::success($results, "Dashboard data fetched");
    }

    private function baseQuery()
    {
        return Form1ApplicationNo::query()
            ->where('is_deleted', 0);
    }
}

<?php

namespace App\Services;

use Exception;
use App\Classes\ResponseHandler;
use App\Helpers\PaginationHelper;
use App\Models\mom;
use App\Models\AgendaModel;
use App\Models\MomAttendance;

use Illuminate\Http\Request;

class MOMService extends BaseFormService
{

    public function index(Request $request)
    {
        $query = $this->baseQuery();
        $this->roleFilters($query);

        $this->applyFilters($query, $request);

        $paginator = $query->paginate($request->per_page ?? 100);

        $paginator->getCollection()->transform(function ($item) {
            return $this->formatData($item);
        });

        return ResponseHandler::success(
            PaginationHelper::format($paginator),
            'MOM data fetched!'
        );
    }

    private function baseQuery()
    {

        return AgendaModel::query()
            ->select([
                'id',
                'rgp_meeting_no',
                'meeting_datetime',
                'venue',
                'ref_non_single_window_status_id',
                'created_on',
            ])
            ->with([
                'status:id,status',
                'mom:id,nma_agenda_id,ref_non_single_window_status_id',
                'mom.status:id,status',
            ]);
    }

    private function roleFilters($query): void
    {
        $userId = userId();

        if ($query->getModel() instanceof AgendaModel) {
           

            $query->whereHas('mom', function ($q) use ($userId) {             

                if (userAccess()->isDeo()) {
                    $q->where('created_by', $userId);
                } else {
                    $q->whereIn('ref_non_single_window_status_id', [31, 28]);
                }
            });
        } else {
           
            if (userAccess()->isDeo()) {
                $query->where('created_by', $userId);
            } else {
                $query->whereIn('ref_non_single_window_status_id', [31, 28]);
            }
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

    private function formatData($item): array
    {
        return [
            'id' => $item->id,
            'rgp_meeting_no' => $item->rgp_meeting_no,
            'meeting_datetime' => formatDate($item->meeting_datetime),
            'venue' => $item->venue,
            'created_on' => formatDate($item->created_on),
            'status' => $item->status?->status,
            'mom_id' => $item->mom?->id,
            'mom_status' => $item->mom?->status?->status,
        ];
    }


    public function show(Request $request, $momId)
    {
        try {


            $userId = userId();

            // $momId = 64;

            $query = Mom::with([
                'agenda.applicationMappings.application',
                'agenda.applicationMappings.synopsis',
                'mom_items.decision',
                'attendancess'
                // 'mom_items.application.applicant',
                // 'items.application.propertyDetails.state',
                // 'items.application.monumentDetails.cpmHistory',
                // 'items.application.synopsis.caseType'
            ]);
            $this->roleFilters($query);
            



            $mom = $query->where('id', $momId)->firstOrFail();

            $mom  = $this->normalize($mom);




            $historys = MomAttendance::with([
                'chairedByUser',
                'designationRole',
                'attendedByUser'
            ])
                ->where('nma_moms_id', $momId)
                ->get();

            $historys = $historys->map(function ($item) {
                return $this->normalize($item);
            });

            return ResponseHandler::success([
                'mom' => $mom,
                'attendance' => $historys,
            ], 'MOM details fetched!');
        } catch (Exception $ex) {
            throw new Exception($ex->getMessage());
        }
    }
}

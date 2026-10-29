<?php

namespace App\Services;

use Exception;
use App\Classes\ResponseHandler;
use App\Helpers\PaginationHelper;
use App\Models\AgendaModel;
use Illuminate\Http\Request;

class GrievanceService extends BaseFormService
{

    public function index(Request $request)
    {
        $query = $this->baseQuery();

        $this->applyFilters($query, $request);

        $paginator = $query->paginate($request->per_page ?? 100);

        $paginator->getCollection()->transform(function ($item) {
            return self::formatData($item);
        });


        $data =  PaginationHelper::format($paginator);

        return ResponseHandler::success($data, "Grievance data fetched!");
    }

    private function baseQuery()
    {
        return AgendaModel::select([
            'rgp_meeting_no',
            'meeting_datetime',
            'venue',
            'ref_non_single_window_status_id',
            'created_on'
        ])->with('status:id,status')
            ->latest('created_on');
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
        return [
            'id' => $item->id,
            'rgp_meeting_no' => $item->rgp_meeting_no,
            'venue' => $item->venue,
            'created_on'        => formatDate($item->created_on),
            'status'            => $item->status?->status,
        ];
    }

    public function store(Request $request)
    {
        try {


            return ResponseHandler::success([], 'Data saved successfully!');
        } catch (Exception $ex) {

            throw new Exception($ex->getMessage());
        }
    }


    public function show(Request $request, $uuid)
    {
        try {


            return ResponseHandler::success([], 'Data fetched!');
        } catch (Exception $ex) {

            throw new Exception($ex->getMessage());
        }
    }
}

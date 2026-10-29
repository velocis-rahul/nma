<?php

namespace App\Services;

use Exception;
use App\Classes\ResponseHandler;
use App\Helpers\PaginationHelper;
use App\Models\Form1ApplicationNo;
use Illuminate\Http\Request;


class CaService extends BaseFormService
{

    public function __construct(protected FormOneService $formOneService) {}

    public function index(Request $request)
    {
        $query = $this->getCaBaseQuery($request);

        $result =  $this->paginateAndFormat($query, $request);

        return ResponseHandler::success($result, "Data fetched");
    }

    private function paginateAndFormat($query, Request $request)
    {
        $paginator = $this->paginate($query, $request);

        $paginator->getCollection()->transform(
            fn($item) => self::formatData($item)
        );

        return PaginationHelper::format($paginator);
    }

    public function show(Request $request, $uuid)
    {
        $collection =  $this->formOneService->collection($uuid);

        return ResponseHandler::success($collection, "Data fetched");
    }

    public function handleApplicationAction(Request $request, $uuid)
    {
        $inputs = $request->all();

        $application = Form1ApplicationNo::where('uuid', $uuid)
            ->first();

        $status = 3;

        if ($inputs['action'] === 'assign') {

            $role = strtolower(userRoleByUserId($inputs['user_id']) ?? '');

            $status = self::ROLE_STATUS_MAP[$role] ?? null;

            if (! $status) {
                throw new Exception('Invalid role mapping.');
            }
        }

        $updateArr = [
            'remark'                           => $inputs['remark'],
            'assign_to'                        => $inputs['user_id'],
            'updated_by'                       => userId(),
            'updated_on'                       => now(),
        ];

        $application->forceFill(($updateArr));

        $application->save();

        $collection =  $this->formOneService->collection($uuid);

        return $collection;
    }


    // private function baseQuery()
    // {
    //     $districtIds = getAccessibleDistrictIds();

    //     return Form1ApplicationNo::with([
    //         'property:id,application_no,ref_nature_work_proposed_id,ref_state_id,ref_district_id,plot_survey_no,locality',
    //         'property.district:id,district_name',
    //         'property.state:id,state_name,state_short_name',
    //         'property.nature_work_proposed:id,nature_of_work_name',
    //         'monoment:id,application_no,locality,nma_cpm_history_id',
    //         'monoment.nma_cpm_history:id,monument_name',
    //         'status:id,status,type',
    //         'ulb_user:id,name,department',
    //         'assigned_to:id,first_name,last_name,middle_name',
    //         'applicant',
    //     ])->whereHas('property', function ($query) use ($districtIds) {
    //         $query->whereIn('ref_district_id', $districtIds);
    //     })->where('is_deleted', 0)->latest();
    // }

    // private function applyRoleFilter($query): void
    // {
    //     if (userAccess()->isCa()) {
    //         $query->whereNotIn('ref_non_single_window_status_id', CA_STATUSES);
    //     }

    //     if (userAccess()->isSa()) {
    //         $query->whereIn('ref_non_single_window_status_id',  SITE_INSPECTION_STATUSES);
    //     }
    // }



    private static function formatData($item): array
    {
        $location = null;

        $applicant_name  = null;

        if ($item->applicant) {

            $applicant  = $item->applicant->first();

            $applicant_name = $applicant->applicant_first_name . ' ' . $applicant->applicant_middle_name . ' ' . $applicant->applicant_last_name;
        }

        $assigned_to  = '-';

        if ($item->assigned_to) {
            $assigned_to =  implode(' ', array_filter([
                $item->assigned_to->first_name ?? null,
                $item->assigned_to->middle_name ?? null,
                $item->assigned_to->last_name ?? null,

            ]));
        }

        if ($item->property) {
            $location = implode(', ', array_filter([
                $item->property->plot_survey_no ?? null,
                $item->property->district->district_name ?? null,
                $item->property->state->state_name ?? null,
                $item->property->taluk ?? null,
                $item->property->locality ?? null,
            ]));

            $nature_of_work = $item->property?->nature_work_proposed?->nature_of_work_name ?? null;
        }

        $status = $item->status?->display_status;

        $data = [
            'id' => $item->id,
            'uuid' => $item->uuid,
            'applicant_name' => $applicant_name,
            'application_no' => $item->application_no,
            "department" => $item?->ulb_user?->department ?? 'NON-SINGLE',
            'monument_name'     => $item->monoment?->nma_cpm_history?->monument_name,
            'locality' => $item->monoment?->locality,
            'status' =>  $status ? $status : $item->status?->status . ' ( ' . $item->status?->type . ' )',
            'location' => $location,
            'nature_of_work' => $nature_of_work,
            'assigned_to' => $assigned_to
        ];

        return $data;
    }
}
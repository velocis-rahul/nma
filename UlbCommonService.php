<?php

namespace App\Services;

use App\Classes\UlbResponseHandler;
use App\Models\UlbLogs;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UlbCommonService extends UlbBaseService
{

    private const MASTERS = [
        'applicant_type',
        'districts',
        'states',
        'cpm_history',
        'property_owner_ship',
        'salutations',
        'documents',
        'nature_of_work',
        'proposed_of_work',
        'monument_located_limit',
        'area',
        'site_type',
        'applicant_type_details',
        'building_detail_types',
    ];
    public function index()
    {
        $data = $this->collection();

        return [
            'applicantType' => $data['applicant_type'],
            'districts' => $data['districts'],
            'states' => $data['states'],
            'cpmHistory' => $data['cpm_history'],
            'propertyOwnership' => $data['property_owner_ship'],
            'salutations' => $data['salutations'],
            'documents' => $data['documents'],
            'natureOfWork' => $data['nature_of_work'],
            'proposedOfWork' => $data['proposed_of_work'],
            'monumentLocatedLimit' => $data['monument_located_limit'],
            'area' => $data['area'],
            'siteType' => $data['site_type'],
            'applicantTypeDetails' => $data['applicant_type_details'],
            'buildingDetailTypes' => $data['building_detail_types'],
        ];
    }

    public function collection(?string $key = null, bool $normalize = true)
    {
        $commons = $this->commonService->collection();

        if (!$normalize) {
            return $key ? ($commons[$key] ?? []) : $commons;
        }

        $data = [];

        foreach (self::MASTERS as $master) {

            $data[$master] = $master === 'documents'
                ? $this->normalizeDocuments($commons[$master])
                : $this->normalizeMaster($commons[$master], $master);
        }

        return $key ? ($data[$key] ?? []) : $data;
    }

    private function normalizeMaster($items, string $configKey, $removeKeys = []): array
    {
        $mapping = array_flip(config("key_mapping.common.{$configKey}", []));

        return collect($items)
            ->map(function ($item) use ($mapping, $removeKeys) {

                $item = is_array($item) ? $item : $item->toArray();

                return $this->mapFields($item, $mapping, $removeKeys);
            })
            ->toArray();
    }
    private function normalizeDocuments($items): array
    {
        $docs = $this->normalizeMaster($items, 'documents');

        return collect($docs)
            ->map(function ($item) {
                $item['fieldName'] = Str::camel($item['fieldName']);
                return $item;
            })
            ->toArray();
    }


    public function logs(Request $request)
    {
        try {

            $query = UlbLogs::select("id", "endpoint", "method", "request", "response", "status_code", "user_agent", "created_on")->where(
                'user_ulbs_authentication_id',
                $request->partnerId
            );

            if ($request->filled('from_date')) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }

            if ($request->filled('to_date')) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }

            if (!$request->filled('from_date') && !$request->filled('to_date')) {
                $query->latest('id')->limit(50);
            } else {
                $query->latest('id');
            }

            $data =  $query->get();

            $response = $query->get()->map(function ($item) {

                $item = $item->toArray();

                $item['request'] = json_decode($item['request'], true) ?: $item['request'];
                $item['response'] = json_decode($item['response'], true) ?: $item['response'];

                return collect($item)->mapWithKeys(function ($value, $key) {
                    return [Str::camel($key) => $value];
                });
            });

            return UlbResponseHandler::success($response, 'Logs fetched successfully.');
        } catch (Exception $ex) {
            throw $ex;
        }
    }
}

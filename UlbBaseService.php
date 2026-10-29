<?php

namespace App\Services;

class UlbBaseService
{
    public $applicationNo;

    public function __construct(public FormOneService $formOneService, public CommonService $commonService) {}


    public function mapFields(array $data, array $mapping, array $removeKeys = []): array
    {
        $output = [];

        foreach ($data as $key => $value) {

            if (in_array($key, $removeKeys)) {
                continue;
            }

            $newKey = $mapping[$key] ?? $key;
            $output[$newKey] = $value;
        }

        return $output;
    }
}

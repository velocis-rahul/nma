<?php

namespace App\Services;

class RequestMapperService
{
    public function map(array $data, array $mapping): array
    {
        $result = [];

        foreach ($data as $key => $value) {

            if (is_array($value)) {

                $mappedArray = $this->map($value, $mapping);

                $newKey = $this->getMappedKey($key, $mapping);

                $result[$newKey] = $mappedArray;

                continue;
            }

            $newKey = $this->getMappedKey($key, $mapping);

            if (array_key_exists($key, $mapping)) {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }

    private function getMappedKey($key, $mapping)
    {
        return $mapping[$key] ?? $key;
    }
}

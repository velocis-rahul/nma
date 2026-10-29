<?php

namespace App\Services;

use App\Classes\ResponseHandler;
use App\Models\UlbAuthentication;
use Exception;
use Illuminate\Http\Request;


class PartnerService
{
    private const RESPONSE_COLUMNS = [
        'name',
        'department',
        'email',
        'mobile_no',
        'api_key',
        'api_secret',
    ];

    public function index(Request $request)
    {

        $partners = UlbAuthentication::latest('id')->get();

        $data = $partners->map(function ($partner) {
            return $this->transform($partner);
        });

        return ResponseHandler::success(
            $data,
            'Partners fetched successfully!'
        );
    }

    public function store(Request $request)
    {
        try {

            $credentials = createAccessKeys();

            $ulbAuth =  UlbAuthentication::create([
                'name'        => $request->name,
                'department'  => $request->department,
                'email'       => $request->email,
                'mobile_no'   => $request->mobile_no,
                'api_key'     => $credentials['api_key'],
                'api_secret'  => $credentials['api_secret'],
                'created_by'  => userId(),
            ]);

            $data =   $this->transform($ulbAuth);

            return ResponseHandler::created($data, 'Partner created successfully!');
        } catch (Exception $ex) {

            throw new Exception($ex->getMessage());
        }
    }

    private function transform(UlbAuthentication $partner): array
    {
        $data = [];

        foreach (self::RESPONSE_COLUMNS as $column) {
            $data[$column] = $partner->{$column};
        }

        return $data;
    }
}

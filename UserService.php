<?php

namespace App\Services;

use Exception;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Classes\ResponseHandler;
use App\Enums\OtpPurpose;
use App\Enums\OtpType;
use App\Jobs\SendSetPasswordMail;
use App\Models\OtpVerification;
use App\Models\RegistrationLog;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;



class UserService
{
    public function store(Request $request)
    {

        try {

            $inputs = $request->all();

            // if (!isOtpVerified(OtpPurpose::REGISTRATION,  OtpType::EMAIL, $inputs['email'])) {
            //     return ResponseHandler::validationError(
            //         "Email verification is required",
            //         "Please verify your email before registration"
            //     );
            // }

            // if (!isOtpVerified(OtpPurpose::REGISTRATION, OtpType::MOBILE,  $inputs['mobile_no'])) {
            //     return ResponseHandler::validationError(
            //         "Mobile verification is required",
            //         "Please verify your mobile number before registration"
            //     );
            // }

            $role = getRole(APPLICANT_ROLE);

            $pwdToken = Str::random(64);

            $now =  now();

            $user = User::create([
                'first_name'    => $inputs['first_name'],
                'middle_name'   => $inputs['middle_name'],
                'last_name'     => $inputs['last_name'],
                'email'         => $inputs['email'],
                'mobile_no' =>   $inputs['mobile_no'],

                'email_verified' => 1,
                'email_verified_at' => $now,

                'mobile_verified' => 1,
                'mobile_verified_at' => $now,

                'role_id'    => $role->id,
                'ref_status_id'    => 1,
                'password'      => Hash::make(123456),
                'password_set_token' => $pwdToken,

                'smarac_token' => generateSmaracToken(),
                'created_at' => $now
            ]);

            $permissions = [
                'form-one-draft.list',
                'form-one-draft.create',
                'form-one-draft.view',
                'form-one-draft.update',
                'form-one-draft.delete',
                'form-one-draft.workflow',
                'form-one-draft.upload',
            ];

            // createPermission($permissions);

            $user->assignRole($role);

            $role->syncPermissions($permissions);

            $user->syncPermissions($permissions);

            // SendSetPasswordMail::dispatch($user, $pwdToken);

            // Artisan::call('queue:work', [
            //     '--once' => true
            // ]);


            /* ================= SEND PASSWORD SET EMAIL ================= */

            $applicant_name = trim(
                ($user->first_name ?? '') . ' ' .
                    ($user->middle_name ?? '') . ' ' .
                    ($user->last_name ?? '')
            );

            try {

                Mail::send(
                    'emails.set-password',
                    [
                        'token' => $pwdToken,
                        'applicant_name' => $applicant_name,
                    ],
                    function ($mail) use ($user) {

                        $mail->to($user->email)
                            ->subject(
                                'National Monuments Authority (NMA) | NOAPS – Activate Your Account'
                            );
                    }
                );
            } catch (\Throwable $e) {

                return ResponseHandler::error(
                    [],
                    'User created but activation email could not be sent.'
                );
            }

            $this->saveLogs($request, $user);

            return ResponseHandler::created([], "User created!");
        } catch (Exception $ex) {
            throw new Exception($ex->getMessage());
        }
    }

    public function update(Request $request, $userId)
    {
        try {

            $inputs = $request->all();

            $user = user();

            $user->update([
                'first_name'   => $inputs['first_name'],
                'middle_name'  => $inputs['middle_name'] ?? null,
                'last_name'    => $inputs['last_name'],
                // 'email'        => $inputs['email'],
                'mobile_no'    => $inputs['mobile_no'],
                'updated_on'   => now(),
            ]);

            if (!empty($inputs['password'])) {

                // if (!Hash::check($inputs['current_password'], $user->password)) {
                //     return ResponseHandler::validationError([], 'Current password is incorrect.');
                // }

                $user->update([
                    'password' => Hash::make($inputs['password']),
                ]);
            }

            $this->saveLogs($request, $user);

            $data  = $this->formatData($user);

            return ResponseHandler::updated($data, "Profile updated successfully!");
        } catch (Exception $ex) {
            throw $ex;
        }
    }


    private function formatData($user)
    {
        $fullName = trim(implode(' ', array_filter([
            $user->first_name,
            $user->middle_name,
            $user->last_name
        ])));

        $userInfo = [

            'id' => $user->id,
            "name" => $fullName,
            "email" => $user->email,
            "mobile_no" => $user->mobile_no,

        ];

        return $userInfo;
    }



    private function saveLogs(Request $request, $user)
    {

        $userAgent = $request->userAgent();

        // $browser = match (true) {
        //     str_contains($userAgent, 'PostmanRuntime') => 'Postman',
        //     str_contains($userAgent, 'Edg') => 'Edge',
        //     str_contains($userAgent, 'Chrome') => 'Chrome',
        //     str_contains($userAgent, 'Firefox') => 'Firefox',
        //     str_contains($userAgent, 'Safari') => 'Safari',
        //     default => 'Unknown',
        // };


        $deviceType = match (true) {
            str_contains($userAgent, 'PostmanRuntime') => 'API Client',
            preg_match('/mobile|android|iphone|ipad/i', $userAgent) => true,
            default => false,
        } ? 'mobile' : 'desktop';

        // $os = match (true) {
        //     str_contains($userAgent, 'Android') => 'Android',
        //     str_contains($userAgent, 'iPhone'),
        //     str_contains($userAgent, 'iPad') => 'iOS',
        //     str_contains($userAgent, 'Windows') => 'Windows',
        //     str_contains($userAgent, 'Mac OS') => 'macOS',
        //     str_contains($userAgent, 'Linux') => 'Linux',
        //     str_contains($userAgent, 'PostmanRuntime') => 'API Client',
        //     default => 'Unknown',
        // };

        RegistrationLog::create([
            'users_id'         => $user->id,
            'users_identifier' => null,
            'device_type'   => $deviceType,
            'users_device_detail' => $userAgent,
            'email_delivery_status' =>  'Not-sent',
            'registration_channel' => 'Mobile',
            'registration_attempt_time'  => now(),
        ]);
    }
}

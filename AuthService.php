<?php

namespace App\Services;

use Exception;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Classes\ResponseHandler;
use App\Enums\OtpType;
use App\Enums\OtpPurpose;
use App\Jobs\SendSetPasswordMail;
use App\Models\OtpVerification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

class AuthService
{
    public function login(Request $request)
    {
        try {

            if (!Auth::attempt($request->only('email', 'password'))) {
                return ResponseHandler::unauthorized('Invalid credentials');
            }

            $user = Auth::user();

            $reponse  = $this->loginResponse($user);

            return ResponseHandler::success($reponse, 'User logged in!!');
        } catch (Exception $ex) {
            throw new Exception($ex->getMessage());
        }
    }

    public function sendOtp(Request $request)
    {
        try {
            $inputs = $request->all();

            $mobile = $inputs['mobile_no'] ?? null;
            $email  = $inputs['email'] ?? null;
            $purpose = $inputs['purpose'] ?? null;

            $user = User::where(function ($q) use ($mobile, $email) {
                if ($mobile) $q->orWhere('mobile_no', $mobile);
                if ($email)  $q->orWhere('email', $email);
            })->first();

            $otpPurpose = OtpPurpose::from($purpose);

            switch ($otpPurpose) {
                case OtpPurpose::LOGIN:
                    if (!$user) {
                        return ResponseHandler::validationError("User not found", "User not found");
                    }
                    break;

                case OtpPurpose::REGISTRATION:
                    if ($user) {
                        return ResponseHandler::validationError("User already exists", "User already exists");
                    }
                    break;

                default:
                    return ResponseHandler::validationError("Invalid purpose", "Invalid purpose");
            }

            $otp =  123456; //$this->generateOtp();

            $expiry = 5;

            $otpType = $mobile ? OtpType::MOBILE : OtpType::EMAIL;

            $query =  OtpVerification::where('users_id', $user?->id)
                ->where('purpose', $otpPurpose->value)
                ->where('is_verified', 0);

            if ($user) {

                $query->where('users_id', $user->id);
            } elseif ($mobile) {

                $query->where('mobile_no', $mobile);
            } elseif ($email) {

                $query->where('email', $email);
            }

            $query->delete();


            OtpVerification::create([
                'users_id'     => $user?->id,
                'otp_code'     => $otp,
                'otp_type'     => $otpType->value,
                'is_verified'  => 0,
                'attempt_count' => 0,
                'expires_at'   => now()->addMinutes($expiry),
                'purpose'      => $otpPurpose->value,
                'created_on'   => now(),
                'email'   => $email,
                'mobile_no'   => $mobile
            ]);

            $name = trim(
                ($user->first_name ?? '') . ' ' .
                    ($user->last_name ?? '')
            );
            $otpPurposeText = ucfirst($otpPurpose->value);
            if ($otpType === OtpType::EMAIL) {

                $recipientEmail = $user?->email ?? $email;

                Mail::send(
                    'emails.otp',
                    [
                        'applicant_name' => $name ?: 'Applicant',
                        'otp'            => $otp,
                        'type'           => $otpPurposeText,
                    ],
                    function ($msg) use ($recipientEmail, $otpPurposeText) {

                        $msg->to($recipientEmail)
                            ->subject(
                                'National Monuments Authority (NMA) – NOAPS ' . $otpPurposeText . ' OTP'
                            );
                    }
                );
            }

            return ResponseHandler::success([], "OTP sent successfully!");
        } catch (Exception $ex) {
            throw $ex;
        }
    }

    public function verifyOtp(Request $request)
    {
        try {
            $inputs = $request->all();

            $mobile  = $inputs['mobile_no'] ?? null;
            $email   = $inputs['email'] ?? null;
            $otp     = $inputs['otp'];
            $purpose = OtpPurpose::from($inputs['purpose']);

            $user = User::where(function ($q) use ($mobile, $email) {
                if ($mobile) $q->orWhere('mobile_no', $mobile);
                if ($email)  $q->orWhere('email', $email);
            })->first();

            $otpQuery = OtpVerification::where('otp_code', $otp)
                ->where('purpose', $purpose->value)
                ->where('is_verified', 0)
                ->where('expires_at', '>', now());

            if ($mobile) {
                $otpQuery->where('otp_type', OtpType::MOBILE->value);
            } else {
                $otpQuery->where('otp_type', OtpType::EMAIL->value);
            }

            $otpRecord = $otpQuery->orderBy('created_on', 'desc')
                ->first();

            if (!$otpRecord) {
                return ResponseHandler::validationError("Invalid or expired OTP", "Invalid or expired OTP");
            }

            switch ($purpose) {

                case OtpPurpose::LOGIN:
                    if (!$user) {
                        return ResponseHandler::validationError("User not found", "User not found");
                    }

                    $otpRecord->update([
                        'is_verified' => 1,
                    ]);

                    $user->save();

                    $response = $this->loginResponse($user);

                    return ResponseHandler::success($response, 'Login successful');

                case OtpPurpose::REGISTRATION:

                    if ($user) {
                        return ResponseHandler::validationError("User already exists", "User already exists");
                    }

                    $otpRecord->update([
                        'is_verified' => 1,
                    ]);

                    return ResponseHandler::success([
                        'mobile_no' => (string)  $mobile,
                        'email'     => $email,
                        'purpose'   => $purpose->value
                    ], 'OTP verified successfully.');

                default:
                    return ResponseHandler::validationError("Invalid purpose", "Invalid purpose");
            }
        } catch (Exception $ex) {
            throw $ex;
        }
    }

    public function generateOtp()
    {
        return rand(100000, 999999);
    }

    public function logout(Request $request)
    {
        try {

            $user = $request->user();

            if ($user && $user->currentAccessToken()) {
                $user->currentAccessToken()->delete();
            }

            return ResponseHandler::success('', "Logged Out!");
        } catch (Exception $e) {
            throw $e;
        }
    }
    public function forgotPassword(Request $request)
    {
        try {

            $expTime = now()->addMinutes(30);

            $inputs  = $request->all();

            $user = User::where('email', $inputs['email'])->first();

            $pwdToken = Str::random(64);

            $user->password_set_token = $pwdToken;

            $user->password_set_token_expired_on = $expTime;

            $user->save();

            $applicant_name = $this->getFullName($user);


            SendSetPasswordMail::dispatch($user, $pwdToken);

            Artisan::call('queue:work', [
                '--once' => true
            ]);

            return ResponseHandler::success('', "Password link sent");
        } catch (Exception $e) {
            throw $e;
        }
    }
    public function resetPassword(Request $request)
    {
        try {

            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return ResponseHandler::validationError("User not found", "User not found");
            }

            if ($user->password_set_token !== $request->token) {
                return ResponseHandler::validationError("Invalid token", "Invalid token");
            }

            if (now()->greaterThan($user->password_set_token_expired_on)) {
                return ResponseHandler::validationError("Token expired", "Token expired");
            }

            $user->password = Hash::make($request->password);

            $user->password_set_token = null;

            $user->password_set_token_expired_on = null;

            $user->save();

            return ResponseHandler::success([], "Password reset successfully");
        } catch (Exception $e) {
            throw $e;
        }
    }

    private function loginResponse($user)
    {
        $token = $user->createTokenWithLength('api-token', 232)->plainTextToken;

        $fullName = $this->getFullName($user);

        $roles =  $user->roles->map(function ($role) {
            return [
                'id' => $role->id,
                'name' => $role->name,
            ];
        })->values();

        $permissions = $user->getAllPermissions()
            ->map(fn($permission) => [
                'id' => $permission->id,
                'name' => $permission->name,
            ])
            ->values();


        $userInfo = [
            'token' => $token,
            'id' => $user->id,
            "name" => $fullName,
            "email" => $user->email,
            "mobile_no" => $user->mobile_no,
            "roles" => $roles,
            "permissions" => $permissions
        ];

        return $userInfo;
    }

    private function getFullName($user)
    {
        return trim(implode(' ', array_filter([
            $user->first_name,
            $user->middle_name,
            $user->last_name,
        ])));
    }
}

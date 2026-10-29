<?php

namespace App\Services;

use App\Classes\ResponseHandler;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class RateLimitService
{
    public static function register(): void
    {
        self::api();
        self::login();
        self::sendOtp();
        self::upload();
    }

    protected static function api(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $key = $request->user()?->id ?? $request->ip();

            return Limit::perMinute(100)
                ->by($key)
                ->response(
                    fn($request, $headers) => self::globalResponse($headers)
                );
        });
    }

    protected static function login(): void
    {
        RateLimiter::for('api-login', function (Request $request) {

            return Limit::perMinute(3)
                ->by($request->ip())
                ->response(
                    fn($request, $headers) => self::globalResponse($headers)
                );
        });
    }

    protected static function sendOtp(): void
    {
        RateLimiter::for('api-send-otp', function (Request $request) {

            $key = $request->ip();

            return [
                Limit::perMinute(3)
                    ->by($key)
                    ->response(
                        fn($request, $headers) => self::globalResponse($headers)
                    ),

                Limit::perHour(1000)
                    ->by($key)
                    ->response(
                        fn($request, $headers) => self::globalResponse($headers)
                    ),
            ];
        });
    }

    protected static function upload(): void
    {
        RateLimiter::for('api-upload', function (Request $request) {

            $key = $request->user()?->id ?? $request->ip();

            return [
                Limit::perMinute(10)
                    ->by($key)
                    ->response(
                        fn($request, $headers) => self::globalResponse($headers)
                    ),

                Limit::perDay(200)
                    ->by($key)
                    ->response(
                        fn($request, $headers) => self::globalResponse($headers)
                    ),
            ];
        });
    }

    protected static function globalResponse(array $headers)
    {
        $retryAfter = $headers['Retry-After'] ?? 60;

        return ResponseHandler::tooManyRequests($retryAfter);
    }
}

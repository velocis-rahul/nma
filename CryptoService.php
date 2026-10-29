<?php

namespace App\Services;

use Exception;

class CryptoService
{

    private const ENCRYPTOR_PWD = 'K8f3Xz91LmP0qRs7TnV4Yw2AbCdE6GhJ';

    public static function encryptor($request)
    {
        try {

            if (empty($request)) {
                throw new Exception('No data provided for encryption.');
            }

            $iv = random_bytes(16);

            $str = is_array($request) ? json_encode($request, JSON_UNESCAPED_UNICODE) : (string) $request;

            if ($str === false) {
                throw new Exception('JSON encoding failed.');
            }

            $encrypted = openssl_encrypt(
                $str,
                'AES-256-CBC',
                self::ENCRYPTOR_PWD,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($encrypted === false) {
                throw new Exception('Encryption failed.');
            }

            $ivBase64 = base64_encode($iv);

            $encryptedBase64 = base64_encode($encrypted);

            return $ivBase64 . ':' . $encryptedBase64;
        } catch (Exception $e) {
            throw new Exception('Encryption failed: ' . $e->getMessage(), 500);
        }
    }
    public static function decryptor($response)
    {
        if (empty($response)) {
            throw new Exception('No data provided for decryption.');
        }

        try {
            $parts = explode(':', $response);

            if (count($parts) !== 2) {
                throw new Exception('Invalid encrypted data format.');
            }

            $iv = base64_decode($parts[0]);

            $encrypted = base64_decode($parts[1]);

            $decrypted = openssl_decrypt(
                $encrypted,
                'AES-256-CBC',
                self::ENCRYPTOR_PWD,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($decrypted === false) {
                throw new Exception('Decryption failed.');
            }

            return $decrypted;
        } catch (Exception $e) {
            throw new Exception($e->getMessage(), 500);
        }
    }



    public static function sanitizeEncryptor($input)
    {
        try {

            if (empty($input) || !is_array($input)) {
                throw new Exception('Invalid input provided.');
            }

            foreach ($input as $key => $value) {
                if (is_string($value) && !empty($value)) {

                    $sanitized = str_replace(
                        ['+', '/'],
                        ['-', '_'],
                        $value
                    );

                    return $sanitized;
                }
            }

            throw new Exception('No valid string found in input.');
        } catch (Exception $e) {

            throw new Exception('Sanitize Encryptor failed: ' . $e->getMessage(), 422);
        }
    }


    public static function sanitize($encrypted)
    {
        try {
            if (empty($encrypted) || !is_string($encrypted)) {
                throw new Exception('Invalid encrypted input.');
            }
            $base64 = strtr($encrypted, '-_', '+/');

            $padding = strlen($base64) % 4;

            if ($padding) {
                $base64 .= str_repeat('=', 4 - $padding);
            }

            return self::decryptor($base64);
        } catch (Exception $e) {
            throw new Exception('Sanitize (decrypt) failed: ' . $e->getMessage(), 500);
        }
    }


    public static function desanitize($sanitized)
    {
        try {
            if (empty($sanitized) || !is_string($sanitized)) {
                throw new Exception('Invalid sanitized input.');
            }

            $base64 = strtr($sanitized, '-_', '+/');

            // $padding = strlen($base64) % 4;
            // if ($padding) {
            //     $base64 .= str_repeat('=', 4 - $padding);
            // }



            return self::decryptor($base64);
        } catch (Exception $e) {
            throw new Exception('Desanitize failed: ' . $e->getMessage(), 500);
        }
    }
}

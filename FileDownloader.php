<?php

namespace App\Services;

use RuntimeException;
use Throwable;

class FileDownloader
{
    private readonly string $directory;
    private readonly int $maxBytes;
    private readonly int $connectTimeout;
    private readonly int $timeout;
    private readonly int $lowSpeedLimit;
    private readonly int $lowSpeedTime;
    private readonly int $minFreeBytes;

    public function __construct()

    {
        $this->directory = storage_path('app/remote-files');
        $this->maxBytes = 12_000_000_000;
        $this->minFreeBytes = 15_000_000_000;
        $this->connectTimeout = 30;
        $this->timeout = 21600;
        $this->lowSpeedLimit = 1024;
        $this->lowSpeedTime = 120;
    }

    public function download(string $url, string $id): array
    {


        $this->validateUrl($url);

        $this->prepareDirectory($this->directory);
        $this->checkDiskSpace($this->directory);

        $temporary = "{$this->directory}/{$id}.part";

        $extension = $this->getFileExtension($url, $contentType ?? null);

        $final = "{$this->directory}/{$id}.{$extension}";

        $output = fopen($temporary, 'wb');

        if ($output === false) {
            throw new RuntimeException(
                'Unable to create temporary file.'
            );
        }

        $curl = false;
        $written = 0;
        $tooLarge = false;
        $writeFailed = false;


        try {
            $curl = $this->createCurlHandle($url,  $output, $this->maxBytes, $written,  $tooLarge, $writeFailed);

            $success = curl_exec($curl);

            $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

            $contentType = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);

            if ($tooLarge) {
                throw new RuntimeException(
                    'File exceeds the maximum allowed size.'
                );
            }

            if ($writeFailed) {
                throw new RuntimeException(
                    'Unable to write data to disk.'
                );
            }

            if ($success === false) {
                throw new RuntimeException(
                    'Download failed: ' . curl_error($curl)
                );
            }

            if ($httpCode < 200 || $httpCode >= 300) {
                throw new RuntimeException(
                    "Unexpected HTTP status: {$httpCode}"
                );
            }

            if ($written === 0) {
                throw new RuntimeException(
                    'The downloaded file is empty.'
                );
            }

            if (!fflush($output)) {
                throw new RuntimeException(
                    'Unable to flush file to disk.'
                );
            }

            fclose($output);
            $output = null;

            if (!rename($temporary, $final)) {
                throw new RuntimeException(
                    'Unable to finalize downloaded file.'
                );
            }

            return [
                'path' => $final,
                'name' => basename($final),
                'bytes' => $written,
                'content_type' => $contentType ?: 'application/octet-stream',
            ];
        } catch (Throwable $e) {
            if (is_resource($output)) {
                fclose($output);
                $output = null;
            }

            if (is_file($temporary)) {
                @unlink($temporary);
            }

            throw $e;
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }

            if ($curl !== false) {
                curl_close($curl);
            }
        }
    }

    private function getFileExtension(
        string $url,
        ?string $contentType = null
    ): string {
        // Try to detect the extension from the URL.
        $path = parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path ?? '', PATHINFO_EXTENSION));

        // Allow only safe extension characters.
        if (
            $extension !== '' &&
            preg_match('/^[a-z0-9]{1,10}$/', $extension)
        ) {
            return $extension;
        }

        // Fall back to the response Content-Type.
        $mimeMap = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/zip' => 'zip',
            'application/json' => 'json',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'audio/mpeg' => 'mp3',
        ];

        $mimeType = strtolower(
            trim(explode(';', $contentType ?? '')[0])
        );

        return $mimeMap[$mimeType] ?? 'bin';
    }


    private function createCurlHandle(
        string $url,
        $output,
        int $maxBytes,
        int &$written,
        bool &$tooLarge,
        bool &$writeFailed
    ) {
        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException(
                'Unable to initialize cURL.'
            );
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => false,

            // Do not automatically follow redirects.
            CURLOPT_FOLLOWLOCATION => false,

            // Connection timeout in seconds.
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,

            // Maximum total download time in seconds.
            CURLOPT_TIMEOUT => $this->timeout,

            // Stop if transfer speed remains too low.
            CURLOPT_LOW_SPEED_LIMIT => $this->lowSpeedLimit,
            CURLOPT_LOW_SPEED_TIME => $this->lowSpeedTime,

            // TEMPORARY: Disable SSL verification for testing.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,

            // Treat HTTP error status codes as failures.
            CURLOPT_FAILONERROR => true,

            // Stream each received chunk directly to disk.
            CURLOPT_WRITEFUNCTION => function (
                $curl,
                string $chunk
            ) use (
                $output,
                $maxBytes,
                &$written,
                &$tooLarge,
                &$writeFailed
            ): int {
                $length = strlen($chunk);

                // Enforce the maximum download size.
                if ($written + $length > $maxBytes) {
                    $tooLarge = true;

                    return 0;
                }

                $offset = 0;

                // Handle partial writes safely.
                while ($offset < $length) {
                    $count = fwrite(
                        $output,
                        substr($chunk, $offset)
                    );

                    if ($count === false || $count === 0) {
                        $writeFailed = true;

                        return 0;
                    }

                    $offset += $count;
                }

                $written += $length;

                return $length;
            },
        ]);

        return $curl;
    }


    private function prepareDirectory(string $directory): void
    {
        if (
            !is_dir($directory) &&
            !mkdir($directory, 0750, true) &&
            !is_dir($directory)
        ) {
            throw new RuntimeException(
                'Unable to create download directory.'
            );
        }
    }

    private function checkDiskSpace(string $directory): void
    {
        $freeBytes = disk_free_space($directory);

        if (
            $freeBytes === false ||
            $freeBytes < (int) config('remote-files.min_free_bytes')
        ) {
            throw new RuntimeException(
                'Insufficient free disk space.'
            );
        }
    }

    private function validateUrl(string $url): void
    {
        $parts = parse_url($url);

        if (
            !is_array($parts) ||
            strtolower($parts['scheme'] ?? '') !== 'https' ||
            empty($parts['host']) ||
            isset($parts['user']) ||
            isset($parts['pass']) ||
            (isset($parts['port']) && $parts['port'] !== 443)
        ) {
            throw new RuntimeException(
                'Only valid public HTTPS URLs on port 443 are supported.'
            );
        }
    }
}

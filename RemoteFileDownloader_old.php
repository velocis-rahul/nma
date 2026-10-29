<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class RemoteFileDownloader
{
    private const ALLOWED_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'pdf',
    ];

    public function __construct(private CommonService $commonService) {}

    private const MAX_FILE_SIZE = 20 * 1024 * 1024;

    public function downloadMultiple(array $payload, string $directory): array
    {

        $payload = $this->cleanXSS($payload);

        Storage::disk('public')->makeDirectory($directory);

        $result = [];

        $savedFiles = [];

        $documents = $this->commonService->getDocuments();

        $validation = $this->validatePayload($payload, $documents);

        if (!$validation['success']) {
            throw new Exception($validation['message']);
        }

        $gpaSpaApplicantId = $payload['gpaSpaApplicantId'] ?? null;

        unsetArr($payload, ['termCondition', 'partnerId', 'sourcePlatform', 'gpaSpaApplicantId']);

        try {

            foreach ($payload as $section => $items) {

                foreach ($items as $file) {

                    $response = $this->downloadAndStore(
                        $file['url'],
                        $directory,
                        $file['id'],
                        $section,
                        $gpaSpaApplicantId
                    );

                    if (!$response['success']) {
                        throw new Exception(
                            "Document '{$section}' failed: {$response['message']} | URL: {$file['url']}"
                        );
                    }

                    $savedFiles[] = $response['path'];

                    $result[Str::snake($section)][] = $response;
                }
            }

            return [
                'success' => true,
                'files' => $result,
            ];
        } catch (\Throwable $e) {

            foreach ($savedFiles as $path) {
                Storage::disk('public')->delete($path);
            }

            throw $e;
        }
    }

    private function validatePayload(array $payload, $documents = []): array
    {

        foreach ($documents as  $document) {

            $files = $payload[Str::camel($document['field_name'])] ?? [];

            if ($document['is_mandatory'] && empty($files)) {

                return [
                    'success' => false,
                    'message' => "{$document['document_name']} is required."
                ];
            }

            if (empty($files)) {
                continue;
            }

            if (!$document['is_multiple_upload'] && count($files) > 1) {

                return [
                    'success' => false,
                    'message' => "{$document['document_name']} allows only one file."
                ];
            }

            $allowedExtensions = collect(explode(',', $document['extension_type']))
                ->map(fn($ext) => ltrim(strtolower(trim($ext)), '.'))
                ->toArray();

            foreach ($files as $file) {

                if (empty($file['url'])) {

                    return [
                        'success' => false,
                        'message' => "{$document['document_name']} url is required."
                    ];
                }

                $extension = strtolower(
                    pathinfo(parse_url($file['url'], PHP_URL_PATH), PATHINFO_EXTENSION)
                );

                if ($extension === 'jpeg') {
                    $extension = 'jpg';
                }

                if (!in_array($extension, $allowedExtensions)) {

                    return [
                        'success' => false,
                        'message' => "{$document['document_name']} accepts only " . implode(', ', $allowedExtensions)
                    ];
                }
            }
        }

        return [
            'success' => true
        ];
    }

    public function downloadSingle(string $url, string $directory): array
    {

        Storage::disk('public')->makeDirectory($directory);

        return $this->downloadAndStore($url, $directory);
    }

    private function downloadAndStore(string $url, string $directory, $id = null, $section = null, $gpaSpaApplicantId = null): array
    {

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => 30,
                'ignore_errors' => true,
                'header' => implode("\r\n", [
                    'User-Agent: Mozilla/5.0',
                    'Accept: */*',
                ]),
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {

            return [
                'id'      => $id,
                'success' => false,
                'message' => 'Unable to download file',
                'url'     => $url,
            ];
        }

        $statusCode = 200;

        foreach ($http_response_header ?? [] as $header) {

            if (preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $header, $matches)) {

                $statusCode = (int) $matches[1];

                break;
            }
        }


        if ($statusCode !== 200) {

            return [
                'id'      => $id,
                'success' => false,
                'message' => "HTTP {$statusCode}",
                'url'     => $url,
            ];
        }


        $contentType = '';

        foreach ($http_response_header ?? [] as $header) {

            if (stripos($header, 'Content-Type:') === 0) {

                $contentType = strtolower(
                    trim(explode(';', explode(':', $header, 2)[1])[0])
                );

                break;
            }
        }

        $invalidContentTypes = [
            'text/html',
            'text/plain',
            'text/xml',
            'application/json',
        ];


        if (in_array($contentType, $invalidContentTypes)) {

            return [
                'id'      => $id,
                'success' => false,
                'message' => "URL returned {$contentType} instead of file.",
                'url'     => $url,
            ];
        }

        $start = strtolower(substr(trim($body), 0, 500));

        if (
            str_contains($start, '<html') ||
            str_contains($start, '<!doctype html') ||
            str_contains($start, '<body') ||
            str_contains($start, '<head')
        ) {

            return [
                'id'      => $id,
                'success' => false,
                'message' => 'URL returned HTML page instead of file.',
                'url'     => $url,
            ];
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        $mime = $finfo->buffer($body);

        $allowedMime = [
            'image/jpeg',
            'image/png',
            'image/webp',
            'application/pdf',
        ];

        if (!in_array($mime, $allowedMime)) {

            return [
                'id'      => $id,
                'success' => false,
                'message' => "Invalid file type ({$mime})",
                'url'     => $url,
            ];
        }

        if (strlen($body) === 0) {

            return [
                'id'      => $id,
                'success' => false,
                'message' => 'Empty File',
                'url'     => $url,
            ];
        }

        if (strlen($body) > self::MAX_FILE_SIZE) {

            return [
                'id'      => $id,
                'success' => false,
                'message' => 'Large File',
                'url'     => $url,
            ];
        }

        $extension = strtolower(
            pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)
        );

        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {

            return [
                'id'      => $id,
                'success' => false,
                'message' => 'Unsupported Extension (' . $extension . ')',
                'url'     => $url,
            ];
        }

        $filename = $section . '_' . time() . '_' . Str::random(10) . '.' . $extension;

        $path = $directory . '/' . $filename;

        Storage::disk('public')->put($path, $body);

        return  [
            'success' => true,
            'ref_document_type_id'      => $id,
            'gpa_spa_applicant_id'      => $gpaSpaApplicantId ?? null,
            'url'                  => $filename ?? null,
            'path'                      => $path ?? null,
            'terms_conditions_accepted' => 1,

        ];
    }

    private function cleanXSS($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->cleanXSS($value);
            }
            return $data;
        }

        if (is_string($data)) {
            $patterns = [
                '/<script\b/i',
                '/javascript:/i',
                '/on\w+=/i',
                '/<iframe\b/i',
                '/<object\b/i',
                '/<embed\b/i'
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $data)) {
                    throw new Exception('Malicious content detected in request.');
                }
            }

            return $data;
        }
        return $data;
    }
}

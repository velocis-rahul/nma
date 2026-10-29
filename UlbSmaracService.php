<?php

namespace App\Services;

use Exception;
use App\Classes\UlbResponseHandler;
use App\Models\Form1ApplicantDetails;
use App\Models\Form1ApplicationNo;
use App\Models\Form1UploadDetails;
use App\Models\UlbArchitect;
use App\Models\User;
use Carbon\Carbon;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class UlbSmaracService
{
    public $application;

    public function index(Request $request)
    {
        return UlbResponseHandler::success([], 'Data fetched!');
    }


    public function login(Request $request)
    {
        $response = [
            'status' => FALSE,
            'application_status' => -1,
        ];

        try {

            $validator = Validator::make($request->all(), [
                'appno'   => ['required', 'string', 'max:100'],
                'emailid' => ['required', 'email', 'max:255'],
                'mobno'   => ['required', 'string', 'max:20'],
                'userpwd' => ['required', 'string', 'max:255'],
            ]);

            if ($validator->fails()) {
                return $response;
            }

            $applicationNo = $request->input('appno');
            $emailId       = $request->input('emailid');
            $mobileNo      = $request->input('mobno');
            $userPassword  = $request->input('userpwd');

            $application = Form1ApplicationNo::where('application_no', $applicationNo)->first();

            if (!$application) {
                $response['application_status'] = -5;
                return $response;
            }

            if ($application->source_platform == 'Ulb') {

                $user = UlbArchitect::where('email', $emailId)
                    ->where('application_no', $applicationNo)
                    ->first();

                if (!$user) {
                    $response['application_status'] = -2;
                    return $response;
                }

                if ($user->smarac_token != $userPassword) {
                    $response['application_status'] = -4;
                    return $response;
                }

                if ($user->mobile != $mobileNo) {
                    $response['application_status'] = -6;
                    return $response;
                }
            } else {

                $user = User::where('email', $emailId)->first();

                if (!$user) {
                    $response['application_status'] = -2;
                    return $response;
                }

                if ($user->id == $user->created_by) {
                    $response['application_status'] = -4;
                    return $response;
                }

                if ($user->smarac_token != $userPassword) {
                    $response['application_status'] = -4;
                    return $response;
                }

                if ($user->mobile_no != $mobileNo) {
                    $response['application_status'] = -6;
                    return $response;
                }
            }

            return [
                'status' => TRUE,
                'application_status' => 0,
            ];
        } catch (Exception $ex) {

            $response['application_status'] = -7;

            return $response;
        }
    }



    public function applicationData(Request $request): array
    {
        $response = [
            'status' => false,
            'application_status' => -3,
        ];

        $appno = '';

        $encryptedFile = null;
        $images = [];

        $date = '';
        $time = '';
        $photo1 = '';
        $photo2 = '';
        $featuretype = '';
        $coordinates = '';

        $savedImagePaths = [];

        try {

            $validator = Validator::make($request->all(), [
                'uploaded_file' => ['required',  'array', 'min:1'],
                'uploaded_file.*' => ['required', 'file', 'max:51200'],
            ]);

            if ($validator->fails()) {
                return $response;
            }

            $files = $request->file('uploaded_file', []);

            foreach ($files as $file) {

                $extension = strtolower($file->getClientOriginalExtension());

                $mimeType = $file->getMimeType();

                $isDataFile = in_array($extension, ['xml', 'kml', 'txt'],   true);

                if ($isDataFile) {
                    if ($encryptedFile !== null) {
                        return $response;
                    }

                    $encryptedFile = $file;
                    continue;
                }

                if (str_starts_with($mimeType, 'image/') && $file->isValid()) {
                    $images[] = $file;
                }
            }

            if (!$encryptedFile) {
                return $response;
            }


            $encryptedContent = file_get_contents($encryptedFile->getRealPath());

            if ($encryptedContent === false ||   $encryptedContent === '') {
                return $response;
            }


            $contents = $encryptedContent;

            if (!$this->isValidXml($contents)) {

                $decrypted = myCrypt($encryptedContent);

                if (!is_string($decrypted) || $decrypted === '') {
                    return $response;
                }

                $contents = $decrypted;
            }

            $xml = $this->parseXml($contents);

            if (!$xml) {
                return $response;
            }


            print_r($xml);


            die;

            $placemarks = $xml->xpath(
                '//*[local-name()="Placemark"]'
            );

            if (empty($placemarks)) {
                return $response;
            }

            $placemark = $placemarks[0];

            // 7. Extract ExtendedData values
            $dataItems = $placemark->xpath(
                './/*[local-name()="ExtendedData"]'
                    . '//*[local-name()="Data"]'
            );

            foreach ($dataItems ?: [] as $data) {
                $attributes = $data->attributes();

                $key = isset($attributes['name'])
                    ? (string) $attributes['name']
                    : '';

                $values = $data->xpath(
                    './*[local-name()="value"]'
                );

                $value = !empty($values)
                    ? trim((string) $values[0])
                    : '';

                switch ($key) {
                    case 'appno':
                        $appno = $value;
                        break;

                    case 'date':
                        $date = $value;
                        break;

                    case 'time':
                        $time = $value;
                        break;

                    case 'photo1':
                        $photo1 = $value;
                        break;

                    case 'photo2':
                        $photo2 = $value;
                        break;

                    case 'featuretype':
                        $featuretype = strtolower($value);
                        break;
                }
            }

            // 8. Validate required KML data
            if ($appno === '') {
                return $response;
            }

            if (!in_array(
                $featuretype,
                ['point', 'polygon'],
                true
            )) {
                return $response;
            }

            // 9. Extract coordinates
            if ($featuretype === 'point') {
                $coordinateNodes = $placemark->xpath(
                    './/*[local-name()="Point"]'
                        . '/*[local-name()="coordinates"]'
                );
            } else {
                $coordinateNodes = $placemark->xpath(
                    './/*[local-name()="Polygon"]'
                        . '/*[local-name()="outerBoundaryIs"]'
                        . '/*[local-name()="LinearRing"]'
                        . '/*[local-name()="coordinates"]'
                );
            }

            $coordinates = !empty($coordinateNodes)
                ? trim((string) $coordinateNodes[0])
                : '';

            if ($coordinates === '') {
                return $response;
            }

            // 10. Prepare storage directories
            $kmlDirectory = public_path(
                'assets/site/main/KML_FILES'
            );

            $imageDirectory = public_path(
                'assets/site/main/KMLData'
            );

            foreach ([$kmlDirectory, $imageDirectory] as $directory) {
                if (
                    !is_dir($directory) &&
                    !mkdir($directory, 0755, true) &&
                    !is_dir($directory)
                ) {
                    throw new \RuntimeException(
                        'Unable to create upload directory.'
                    );
                }
            }

            // 11. Save images and collect database payloads
            $imageRecords = [];

            foreach ($images as $image) {
                $extension = strtolower(
                    $image->guessExtension() ?: 'bin'
                );

                $imageName = $this->safeFilePart($appno)
                    . '_Image_'
                    . now()->format('Y-m-d-H-i-s')
                    . '_' . Str::random(8)
                    . '.' . $extension;

                $image->move($imageDirectory, $imageName);

                $imagePath = '/assets/site/main/KMLData/'
                    . $imageName;

                $savedImagePaths[] = $imageDirectory
                    . DIRECTORY_SEPARATOR
                    . $imageName;

                $imageRecords[] = [
                    'img' => $imagePath,
                    'appid' => $appno,
                    'created' => time(),
                ];
            }

            // 12. Save encrypted and decrypted source files
            $timestamp = now()->format('Y-m-d-H-i-s');

            $safeAppNo = $this->safeFilePart($appno);

            $encryptedName = 'KML_' . $safeAppNo
                . '_Encripted_' . $timestamp . '.txt';

            $decryptedName = 'KML_' . $safeAppNo
                . '_Decripted_' . $timestamp . '.txt';

            $encryptedPath = $kmlDirectory
                . DIRECTORY_SEPARATOR
                . $encryptedName;

            $decryptedPath = $kmlDirectory
                . DIRECTORY_SEPARATOR
                . $decryptedName;

            if (
                file_put_contents(
                    $encryptedPath,
                    $encryptedContent,
                    LOCK_EX
                ) === false
            ) {
                throw new \RuntimeException(
                    'Unable to save encrypted source file.'
                );
            }

            if (
                file_put_contents(
                    $decryptedPath,
                    $contents,
                    LOCK_EX
                ) === false
            ) {
                throw new \RuntimeException(
                    'Unable to save decrypted XML file.'
                );
            }

            // 13. Save image records in database
            // IMPORTANT: Replace the table name below with
            // the actual table used by insertNMAISROImage().
            // DB::transaction(function () use ($imageRecords) {
            //     foreach ($imageRecords as $imageRecord) {
            //         DB::table('nma_isro_images')->insert(
            //             $imageRecord
            //         );
            //     }
            // });

            // 14. Proximity/business logic can use these values
            $proximityArray = [];

            // $appno
            // $date
            // $time
            // $photo1
            // $photo2
            // $featuretype
            // $coordinates
            // $proximityArray

            return [
                'status' => true,
                'application_status' => 0,
            ];
        } catch (Exception $ex) {
            // Log::error('SMARAC application data error', [
            //     'application_no' => $appno,
            //     'error' => $ex->getMessage(),
            // ]);

            // Remove uploaded images if processing failed
            foreach ($savedImagePaths as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }

            return [
                'status' => false,
                'application_status' => -7,
            ];
        }
    }

    private function isValidXml(string $content): bool
    {
        return $this->parseXml($content) !== null;
    }

    private function parseXml(string $content): ?SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string(
                $content,
                SimpleXMLElement::class,
                LIBXML_NONET
            );

            return $xml !== false ? $xml : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function safeFilePart(string $value): string
    {
        $safe = preg_replace(
            '/[^A-Za-z0-9_-]/',
            '_',
            $value
        );

        return $safe !== '' ? $safe : 'application';
    }
}

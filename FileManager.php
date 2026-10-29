<?php

namespace App\Services;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Str;


class FileManager
{

    public static function upload(Request $request, $folder = 'uploads')
    {
        try {

            $uploadedFiles = [];

            $useOriginalName = $request->boolean('originalName', true);

            $files = $request->allFiles();

            foreach ($files as $key => $fileData) {

                if (is_array($fileData)) {

                    foreach ($fileData as $file) {
                        $uploadedFiles[$key][] = self::storeFile(
                            $file,
                            $folder,
                            $useOriginalName
                        );
                    }
                } else {

                    $uploadedFiles[$key] = self::storeFile(
                        $fileData,
                        $folder,
                        $useOriginalName
                    );
                }
            }

            return $uploadedFiles;
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    private static function storeFile($file, $folder, $useOriginalName = true)
    {
        self::validateFileSecurity($file);

        $extension = strtolower(
            $file->getClientOriginalExtension()
        );

        if ($useOriginalName) {

            $originalName = pathinfo(
                $file->getClientOriginalName(),
                PATHINFO_FILENAME
            );

            $safeName = strtolower($originalName);

            $safeName = preg_replace('/[\s_]+/', '-',  $safeName);

            $safeName = preg_replace('/[^a-z0-9-]/', '', $safeName);

            $safeName = preg_replace('/-+/', '-',  $safeName);

            $safeName = trim($safeName, '-');

            if (empty($safeName)) {
                $safeName = 'file';
            }

            $filename = $safeName . '_' . time() . '_' . Str::random(10) . '.' . $extension;
        } else {

            $filename = time() . '_' . Str::random(10)  . '.' . $extension;
        }

        $path = $file->storeAs($folder, $filename, 'public');

        return [
            'filename' => $filename,
            'path'     => $path,
        ];


        // $extension = strtolower(
        //     $file->getClientOriginalExtension()
        // );

        // $safeName = Str::snake($file->getClientOriginalName());

        // $filename = $useOriginalName  ? $safeName  : time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();

        // $path = $file->storeAs($folder, $filename, 'public');

        // return [
        //     'filename' => $filename,
        //     'path'     => $path,
        // ];

        // $filename = $useOriginalName  ? $file->getClientOriginalName() : time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();

        // $destinationPath = public_path($folder);

        // if (!file_exists($destinationPath)) {
        //     mkdir($destinationPath, 0777, true);
        // }

        // $file->move($destinationPath, $filename);

        // return [
        //     'filename' => $filename,
        //     'path' => $folder . '/' . $filename
        // ];
    }

    protected static function validateFileSecurity($file)
    {

        $dangerousExtensions = ['php', 'php3', 'php4', 'php5', 'phtml', 'exe', 'js', 'sh', 'html', 'tsx'];

        if (in_array(strtolower($file->getClientOriginalExtension()), $dangerousExtensions)) {
            throw new Exception('Executable files are not allowed.');
        }

        $filename = $file->getClientOriginalName();

        $filename_first = strpos($filename, ".");

        $filename_second = strrpos($filename, ".");

        if ($filename_first != $filename_second) {
            // throw new Exception('');
            abort(422, 'File name is invalid.');
        }

        $tempname = $file->getPathname();

        /* end this code */

        $handle = fopen($tempname, "rb");

        $filesize = filesize($tempname);

        $contents = fread($handle, $filesize);

        if (strpos($contents, 'alert')) {

            abort(422, 'File contains suspicious script content.');
        }

        if (strpos($contents, '<script')) {
            abort(422, 'File contains suspicious script content.');
        }

        $mime_types = finfo_open(FILEINFO_MIME_TYPE);

        $mime = finfo_file($mime_types, $tempname);

        $mime_types_arr = array(
            'image/jpeg',
            'image/png',
            'image/jpg',
            'application/pdf',
            'application/msword',
            'image/gif',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'video/vnd.uvvu.mp4',
            'application/mp4',
            'video/mp4',
            'video/x-msvideo',
            'application/pdf',
            'application/octet-stream'
        );


        if (!in_array($mime, $mime_types_arr)) {
            abort(422, 'File type is not Valid!');
        }

        $file_extensions = array("jpeg", "jpg", "pdf", 'doc', 'gif', 'docx', 'mp4', 'uvu', 'mp4s', 'avi', 'pdf', 'png');

        $extensions = pathinfo($filename, PATHINFO_EXTENSION);

        if (!in_array(strtolower($extensions), $file_extensions)) {
            abort(422, 'File type is not Valid!');
        }
        return true;
    }
}

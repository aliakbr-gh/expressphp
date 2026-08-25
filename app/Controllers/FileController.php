<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ActivityLog;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;
use ExpressPHP\Storage\FileUploader;
use ExpressPHP\Storage\FileUploadException;

final class FileController
{
    public function __construct(
        private readonly FileUploader $uploads = new FileUploader(),
        private readonly ActivityLog  $activities = new ActivityLog(),
    ) {
    }

    public function upload(Request $request, Response $response): Response
    {
        $file = $request->file('file');
        if ($file === null) {
            return $response->error('File is required', 422);
        }

        try {
            // Uses the global size, extension, and MIME type defaults from config/app.php.
            $uploaded = $this->uploads->upload($file, 'files');

            // $uploaded = $this->uploads->upload(
            //     $file,
            //     'test-upload',
            //     [
            //         'max_size' => 2 * 1024 * 1024,
            //         'allowed_extensions' => ['jpg', 'jpeg', 'png', 'pdf'],
            //         'allowed_mime_types' => [
            //             'image/jpeg',
            //             'image/png',
            //             'application/pdf',
            //         ],
            //     ],
            // );
        } catch (FileUploadException $exception) {
            return $response->error($exception->getMessage(), 422);
        }

        $user = $request->user();
        $this->activities->record(
            $request,
            ($user['name'] ?? $user['username']) . ' uploaded file "' . $uploaded['original_name'] . '"',
        );

        return $response->success([
            'original_name' => $uploaded['original_name'],
            'stored_name' => $uploaded['stored_name'],
            'relative_path' => $uploaded['relative_path'],
            'extension' => $uploaded['extension'],
            'mime_type' => $uploaded['mime_type'],
            'size' => $uploaded['size'],
        ], 'File uploaded', 201);
    }
}

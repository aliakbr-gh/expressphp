<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Request;
use Core\Response;

final class FileController
{
    public function upload(Request $request, Response $response): Response
    {
        $request->validate([
            'file' => 'required|file|max:2048',
        ]);

        $file = $request->file('file');
        $path = $file->store('files');

        return $response->success([
            'path'          => $path,
            'original_name' => $file->name,
            'mime_type'     => $file->mimeType(),
            'size'          => $file->size,
        ], 'File uploaded successfully.', 201);
    }
}

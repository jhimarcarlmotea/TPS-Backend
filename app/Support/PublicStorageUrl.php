<?php

namespace App\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

class PublicStorageUrl
{
    public static function fromPath(string $path): string
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('public');

        return $disk->url($path);
    }
}

<?php

namespace App\Data;

use Illuminate\Http\UploadedFile;

final readonly class ValidatedUpload
{
    public function __construct(
        public UploadedFile $file,
        public string $originalFilename,
        public string $extension,
        public string $mime,
        public int $size,
        public string $checksum,
        public ?int $width = null,
        public ?int $height = null,
    ) {}
}

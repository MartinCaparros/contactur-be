<?php

namespace App\Data;

final readonly class FileDownload
{
    /**
     * @param  resource  $stream
     */
    public function __construct(
        public mixed $stream,
        public string $filename,
        public string $mime,
        public int $size,
    ) {}
}

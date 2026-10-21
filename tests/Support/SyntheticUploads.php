<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use RuntimeException;

final class SyntheticUploads
{
    /** @var array<int, string> */
    private static array $paths = [];

    public static function file(string $name, string $mime, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'contactur-upload-');
        if ($path === false || file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Could not create synthetic upload fixture.');
        }
        self::$paths[] = $path;

        return new UploadedFile($path, $name, $mime, UPLOAD_ERR_OK, true);
    }

    public static function cleanup(): void
    {
        foreach (self::$paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        self::$paths = [];
    }

    public static function pdf(string $suffix = ''): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n".$suffix;
    }

    public static function png(int $width = 1, int $height = 1): string
    {
        $pixels = str_repeat("\x00".str_repeat("\x00", $width * 3), $height);

        return "\x89PNG\r\n\x1A\n"
            .self::pngChunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .self::pngChunk('IDAT', gzcompress($pixels))
            .self::pngChunk('IEND', '');
    }

    public static function jpeg(int $width = 1, int $height = 1): string
    {
        $app0 = "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
        $startOfFrame = "\x08".pack('nn', $height, $width)
            ."\x03\x01\x11\x00\x02\x11\x00\x03\x11\x00";

        return "\xFF\xD8"
            ."\xFF\xE0".pack('n', strlen($app0) + 2).$app0
            ."\xFF\xC0".pack('n', strlen($startOfFrame) + 2).$startOfFrame
            ."\xFF\xD9";
    }

    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}

<?php

namespace App\Support;

use App\Data\ValidatedUpload;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class UploadInspector
{
    public const DOCUMENT = 'document';

    public const MEDIA = 'media';

    public function __construct(private readonly ImageMetadataStripper $stripper = new ImageMetadataStripper) {}

    /** @var array<string, array<int, string>> */
    private const EXTENSIONS = [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
    ];

    public function inspect(UploadedFile $file, string $kind, string $attribute = 'file'): ValidatedUpload
    {
        if (! $file->isValid()) {
            $this->fail($attribute, __('No se pudo recibir el archivo completo.'));
        }

        $path = $file->getRealPath();
        if (! is_string($path) || $path === '') {
            $this->fail($attribute, __('No se pudo inspeccionar el archivo.'));
        }

        $contents = @file_get_contents($path);
        if (! is_string($contents) || $contents === '') {
            $this->fail($attribute, __('El archivo está vacío o no se puede leer.'));
        }

        $size = strlen($contents);
        $maxBytes = max(1, (int) config('contactur.uploads.'.($kind === self::DOCUMENT ? 'documents' : 'media').'_max_bytes'));
        if ($size > $maxBytes) {
            $this->fail($attribute, __('El archivo supera el tamaño máximo permitido.'));
        }

        $originalName = $file->getClientOriginalName();
        $extension = mb_strtolower($file->getClientOriginalExtension());
        $this->assertSafeFilename($originalName, $extension, $attribute);

        $detectedMime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! is_string($detectedMime)) {
            $this->fail($attribute, __('No se pudo determinar el tipo real del archivo.'));
        }

        $allowedMimes = $kind === self::DOCUMENT
            ? ['application/pdf', 'image/jpeg', 'image/png']
            : ['image/jpeg', 'image/png'];
        if (! in_array($detectedMime, $allowedMimes, true)) {
            $this->fail($attribute, __('El tipo real del archivo no está permitido.'));
        }
        if (! in_array($extension, self::EXTENSIONS[$detectedMime], true)) {
            $this->fail($attribute, __('La extensión no coincide con el contenido del archivo.'));
        }

        $clientMime = mb_strtolower(trim($file->getClientMimeType()));
        if ($clientMime !== $detectedMime) {
            $this->fail($attribute, __('El MIME declarado no coincide con el contenido del archivo.'));
        }

        $this->assertNotExecutableOrPolyglot($contents, $attribute);
        [$width, $height] = $this->dimensions($path, $contents, $detectedMime, $attribute);

        // T22: avatar y galería se sirven a otros miembros; se guardan sin metadatos
        // (EXIF con GPS, XMP, IPTC, texto PNG). El tamaño y el checksum son los del
        // archivo limpio, que es el que se persiste. Si no se puede limpiar, se rechaza.
        if ($kind === self::MEDIA) {
            try {
                $clean = $this->stripper->strip($contents, $detectedMime);
            } catch (RuntimeException) {
                $this->fail($attribute, __('No se pudo procesar la imagen de forma segura.'));
            }
            if ($clean !== $contents) {
                if (@file_put_contents($path, $clean) !== strlen($clean)) {
                    $this->fail($attribute, __('No se pudo procesar la imagen de forma segura.'));
                }
                clearstatcache(true, $path);
                $contents = $clean;
                $size = strlen($contents);
                [$width, $height] = $this->dimensions($path, $contents, $detectedMime, $attribute);
            }
        }

        return new ValidatedUpload(
            file: $file,
            originalFilename: $originalName,
            extension: $extension,
            mime: $detectedMime,
            size: $size,
            checksum: hash('sha256', $contents),
            width: $width,
            height: $height,
        );
    }

    private function assertSafeFilename(string $filename, string $extension, string $attribute): void
    {
        if (
            $filename === ''
            || mb_strlen($filename) > 255
            || trim($filename) !== $filename
            || str_starts_with($filename, '.')
            || preg_match('/[\x00-\x1F\x7F\/\\\\]/u', $filename) === 1
            || preg_match('/\.(?:exe|com|bat|cmd|sh|php\d*|phtml|phar|js|jar|msi|scr)(?:\.|$)/iu', $filename) === 1
            || $extension === ''
        ) {
            $this->fail($attribute, __('El nombre del archivo no es seguro.'));
        }
    }

    private function assertNotExecutableOrPolyglot(string $contents, string $attribute): void
    {
        $lower = mb_strtolower($contents, '8bit');
        if (
            str_starts_with($contents, 'MZ')
            || str_starts_with($contents, "\x7FELF")
            || str_starts_with($contents, '#!')
            || str_contains($lower, '<?php')
            || str_contains($lower, '<svg')
            || str_contains($lower, '<script')
        ) {
            $this->fail($attribute, __('El archivo contiene contenido ejecutable o mixto no permitido.'));
        }
    }

    /** @return array{int|null, int|null} */
    private function dimensions(string $path, string $contents, string $mime, string $attribute): array
    {
        if ($mime === 'application/pdf') {
            if (! str_starts_with($contents, '%PDF-')) {
                $this->fail($attribute, __('El contenido PDF no es válido.'));
            }

            return [null, null];
        }

        $expectedSignature = $mime === 'image/png' ? "\x89PNG\r\n\x1A\n" : "\xFF\xD8\xFF";
        if (! str_starts_with($contents, $expectedSignature)) {
            $this->fail($attribute, __('La firma de la imagen no es válida.'));
        }

        $image = @getimagesize($path);
        $expectedType = $mime === 'image/png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
        if ($image === false || $image[0] < 1 || $image[1] < 1 || $image[2] !== $expectedType) {
            $this->fail($attribute, __('No se pudieron validar las dimensiones reales de la imagen.'));
        }

        $maxWidth = max(1, (int) config('contactur.uploads.media_max_width'));
        $maxHeight = max(1, (int) config('contactur.uploads.media_max_height'));
        if ($image[0] > $maxWidth || $image[1] > $maxHeight) {
            $this->fail($attribute, __('Las dimensiones de la imagen superan el máximo permitido.'));
        }

        return [(int) $image[0], (int) $image[1]];
    }

    private function fail(string $attribute, string $message): never
    {
        throw ValidationException::withMessages([$attribute => [$message]]);
    }
}

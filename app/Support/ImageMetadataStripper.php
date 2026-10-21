<?php

namespace App\Support;

use RuntimeException;

/**
 * Quita metadatos de imágenes públicas (avatar y galería) antes de guardarlas (T22).
 *
 * Esas imágenes se sirven a otros miembros por URL: un JPEG de teléfono suele traer
 * EXIF con coordenadas GPS, modelo del equipo y fecha, y un PNG puede llevar texto
 * libre. Nada de eso es necesario para mostrar la imagen.
 *
 * No re-codifica (la imagen de PHP no trae GD): recorre la estructura del archivo y
 * copia solo los bloques que hacen falta para decodificarla.
 * - JPEG: conserva SOI, APP0 (JFIF), APP2 con perfil ICC, APP14 (Adobe, necesario
 *   para CMYK) y todos los segmentos que no son APPn/COM; descarta APP1 (EXIF/XMP),
 *   APP13 (IPTC), el resto de APPn y COM. Desde SOS copia el resto tal cual.
 * - PNG: conserva los chunks críticos y los ancillares de color/transparencia;
 *   descarta tEXt, zTXt, iTXt, eXIf, tIME y cualquier otro desconocido.
 *
 * Ante una estructura inesperada lanza excepción: el inspector rechaza la subida en
 * lugar de guardar una imagen sin limpiar.
 */
final class ImageMetadataStripper
{
    /** Chunks PNG que se conservan: críticos y los que afectan cómo se ve la imagen. */
    private const PNG_KEEP = ['IHDR', 'PLTE', 'IDAT', 'IEND', 'tRNS', 'gAMA', 'cHRM', 'sRGB', 'iCCP', 'sBIT', 'bKGD', 'pHYs'];

    private const PNG_SIGNATURE = "\x89PNG\r\n\x1A\n";

    public function strip(string $contents, string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => $this->stripJpeg($contents),
            'image/png' => $this->stripPng($contents),
            default => throw new RuntimeException('Tipo de imagen no soportado para limpiar metadatos.'),
        };
    }

    private function stripJpeg(string $contents): string
    {
        $length = strlen($contents);
        if ($length < 4 || substr($contents, 0, 2) !== "\xFF\xD8") {
            throw new RuntimeException('JPEG sin marcador SOI.');
        }

        $output = "\xFF\xD8";
        $offset = 2;

        while ($offset < $length) {
            if ($contents[$offset] !== "\xFF") {
                throw new RuntimeException('JPEG con un segmento mal formado.');
            }
            // Bytes de relleno 0xFF entre segmentos.
            while ($offset < $length && $contents[$offset] === "\xFF") {
                $offset++;
            }
            if ($offset >= $length) {
                throw new RuntimeException('JPEG truncado.');
            }
            $marker = ord($contents[$offset]);
            $offset++;

            // EOI o marcadores sin longitud (RSTn, TEM): se copian solos.
            if ($marker === 0xD9) {
                return $output."\xFF\xD9";
            }
            if (($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
                $output .= "\xFF".chr($marker);

                continue;
            }

            if ($offset + 2 > $length) {
                throw new RuntimeException('JPEG truncado en la longitud de un segmento.');
            }
            $segmentLength = unpack('n', substr($contents, $offset, 2))[1];
            if ($segmentLength < 2 || $offset + $segmentLength > $length) {
                throw new RuntimeException('JPEG con una longitud de segmento inválida.');
            }
            $segment = substr($contents, $offset, $segmentLength);

            // SOS: a partir de acá vienen los datos comprimidos; se copia todo el resto.
            if ($marker === 0xDA) {
                return $output."\xFF\xDA".substr($contents, $offset);
            }

            if ($this->keepJpegSegment($marker, substr($segment, 2))) {
                $output .= "\xFF".chr($marker).$segment;
            }
            $offset += $segmentLength;
        }

        throw new RuntimeException('JPEG sin datos de imagen.');
    }

    private function keepJpegSegment(int $marker, string $payload): bool
    {
        if ($marker === 0xFE) {
            return false; // COM
        }
        if ($marker < 0xE0 || $marker > 0xEF) {
            return true; // DQT, DHT, SOFn, DRI, etc.
        }

        return match ($marker) {
            0xE0 => str_starts_with($payload, "JFIF\x00") || str_starts_with($payload, "JFXX\x00"),
            0xE2 => str_starts_with($payload, "ICC_PROFILE\x00"),
            0xEE => str_starts_with($payload, 'Adobe'),
            default => false, // APP1 (EXIF/XMP), APP13 (IPTC) y el resto de APPn
        };
    }

    private function stripPng(string $contents): string
    {
        if (! str_starts_with($contents, self::PNG_SIGNATURE)) {
            throw new RuntimeException('PNG sin firma.');
        }

        $length = strlen($contents);
        $offset = strlen(self::PNG_SIGNATURE);
        $output = self::PNG_SIGNATURE;

        while ($offset + 12 <= $length) {
            $dataLength = unpack('N', substr($contents, $offset, 4))[1];
            $type = substr($contents, $offset + 4, 4);
            $chunkLength = 12 + $dataLength;
            if (preg_match('/\A[A-Za-z]{4}\z/', $type) !== 1 || $offset + $chunkLength > $length) {
                throw new RuntimeException('PNG con un chunk inválido.');
            }

            if (in_array($type, self::PNG_KEEP, true)) {
                $output .= substr($contents, $offset, $chunkLength);
            }
            $offset += $chunkLength;

            if ($type === 'IEND') {
                return $output;
            }
        }

        throw new RuntimeException('PNG sin chunk IEND.');
    }
}

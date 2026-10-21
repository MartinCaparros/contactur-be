<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Cada texto en español pasado como literal a `__()`, `trans()` o `@lang()` en `app/`,
 * `bootstrap/` y `resources/views/` tiene su traducción en `lang/en.json`, con los mismos
 * `:placeholders`. Las claves de grupo (`validation.min`) quedan fuera: las resuelve el
 * framework.
 */
final class TranslationParityTest extends TestCase
{
    private const SCANNED = ['app', 'bootstrap', 'resources/views'];

    private const CALL = '/(?<![\w>$:])(?:__|trans|@lang)\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\$]|\\\\.)*)")\s*[,)]/u';

    private const GROUP_KEY = '/^[a-z_]+\.[a-z0-9_.-]+$/';

    public function test_english_catalog_is_valid_json_with_non_empty_values(): void
    {
        $translations = $this->translations();

        $this->assertNotSame([], $translations);
        foreach ($translations as $key => $value) {
            $this->assertIsString($value, 'La traducción de "'.$key.'" no es texto.');
            $this->assertNotSame('', trim($value), 'La traducción de "'.$key.'" está vacía.');
        }
    }

    public function test_every_literal_key_has_an_english_translation_with_the_same_placeholders(): void
    {
        $translations = $this->translations();
        $keys = $this->literalKeys();

        $this->assertNotSame([], $keys, 'No se encontró ningún texto traducible: revisar el patrón.');
        foreach ($keys as $key => $location) {
            $this->assertArrayHasKey($key, $translations, 'Falta en lang/en.json: "'.$key.'" ('.$location.').');
            $this->assertSame(
                $this->placeholders($key),
                $this->placeholders($translations[$key]),
                'Los :placeholders de "'.$key.'" no coinciden con su traducción ('.$location.').',
            );
        }
    }

    /** @return array<string, string> */
    private function translations(): array
    {
        $contents = file_get_contents($this->path('lang/en.json'));
        $this->assertIsString($contents);

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded, 'lang/en.json debe ser un objeto JSON.');

        /** @var array<string, string> $decoded */
        return $decoded;
    }

    /** @return array<string, string> clave => primer archivo:línea donde aparece */
    private function literalKeys(): array
    {
        $keys = [];
        foreach (self::SCANNED as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $this->path($directory),
                RecursiveDirectoryIterator::SKIP_DOTS,
            ));
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                preg_match_all(self::CALL, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                foreach ($matches as $match) {
                    $single = $match[1][1] >= 0 ? $match[1][0] : null;
                    $key = $single !== null
                        ? str_replace(["\\'", '\\\\'], ["'", '\\'], $single)
                        : stripcslashes($match[2][0]);
                    if ($key === '' || preg_match(self::GROUP_KEY, $key) === 1) {
                        continue;
                    }
                    $line = substr_count(substr($source, 0, $match[0][1]), "\n") + 1;
                    $keys[$key] ??= substr($file->getPathname(), strlen($this->path('')) + 1).':'.$line;
                }
            }
        }

        return $keys;
    }

    /** @return list<string> */
    private function placeholders(string $text): array
    {
        preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $text, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }

    private function path(string $relative): string
    {
        return rtrim(dirname(__DIR__, 2).'/'.$relative, '/');
    }
}

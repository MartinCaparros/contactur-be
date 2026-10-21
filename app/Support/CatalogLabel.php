<?php

namespace App\Support;

/**
 * Los catálogos (idiomas, servicios, especialidades, tipos de documento) se guardan en
 * español; se traducen al responder según el idioma de la request (`lang/en.json`).
 * Un nombre sin traducción sale tal cual.
 */
final class CatalogLabel
{
    public static function translate(string $spanish): string
    {
        $translated = __($spanish);

        return is_string($translated) && $translated !== '' ? $translated : $spanish;
    }
}

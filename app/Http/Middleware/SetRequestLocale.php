<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idioma de los mensajes de la API (errores, validación por campo). El BFF manda
 * `Accept-Language: es|en` según el idioma elegido en la interfaz. Solo hay dos idiomas:
 * cualquier variante de inglés responde en inglés y todo lo demás en español (el idioma
 * por defecto). Los `code` del sobre de error no cambian.
 */
final class SetRequestLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(self::fromHeader($request->headers->get('Accept-Language')));

        return $next($request);
    }

    public static function fromHeader(?string $header): string
    {
        $first = strtolower(trim(explode(',', (string) $header)[0]));

        return preg_match('/^en(?:[-_]|$|;)/', $first) === 1 ? 'en' : 'es';
    }
}

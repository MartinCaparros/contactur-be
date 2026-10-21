<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $candidate = $request->headers->get('X-Request-ID');
        $requestId = is_string($candidate)
            && preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $candidate) === 1
                ? $candidate
                : (string) Str::ulid();

        $request->attributes->set('request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}

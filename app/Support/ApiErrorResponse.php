<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ApiErrorResponse
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, array<int, string>>  $fields
     */
    public static function make(
        Request $request,
        int $status,
        string $code,
        string $message,
        array $fields = [],
        array $headers = [],
    ): JsonResponse {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        $requestId = $request->attributes->get('request_id');

        if (! is_string($requestId) || $requestId === '') {
            $requestId = (string) Str::ulid();
            $request->attributes->set('request_id', $requestId);
        }

        return response()->json([
            'error' => $error,
            'request_id' => $requestId,
        ], $status, $headers);
    }
}

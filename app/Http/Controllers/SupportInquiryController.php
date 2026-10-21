<?php

namespace App\Http\Controllers;

use App\Services\SupportInquiryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Público (sin JWT): rate limit `support.inquiries` por IP y honeypot `website`. */
final class SupportInquiryController extends ApiController
{
    public function __construct(private readonly SupportInquiryService $inquiries) {}

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request, [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'category' => ['sometimes', 'nullable', 'string', 'max:80'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            // Honeypot (maxLength 0). ConvertEmptyStringsToNull deja '' en null.
            'website' => ['sometimes', 'nullable', 'string', 'max:0'],
        ], ['name', 'email', 'category', 'message', 'website']);

        $inquiry = $this->inquiries->submit([
            'name' => (string) $data['name'],
            'email' => (string) $data['email'],
            'category' => isset($data['category']) ? (string) $data['category'] : null,
            'message' => (string) $data['message'],
        ], $request->ip());

        return response()->json([
            'data' => [
                'id' => $inquiry->public_id,
                'accepted' => true,
            ],
        ], 202);
    }
}

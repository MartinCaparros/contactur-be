<?php

namespace App\Http\Controllers;

use App\Enums\ProfileCategory;
use App\Http\Resources\CatalogsResource;
use App\Services\CatalogService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CatalogController extends ApiController
{
    public function __construct(private readonly CatalogService $catalogs) {}

    public function index(Request $request): CatalogsResource
    {
        $data = $this->validatePayload($request, [
            'q' => ['sometimes', 'string', 'min:1', 'max:100'],
            'category' => ['sometimes', 'string', Rule::enum(ProfileCategory::class)],
            'cursor' => ['sometimes', 'string', 'regex:/^[0-9]+$/', 'max:20'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ], ['q', 'category', 'cursor', 'per_page']);
        $result = $this->catalogs->list(
            isset($data['q']) ? (string) $data['q'] : null,
            isset($data['category']) ? (string) $data['category'] : null,
            isset($data['cursor']) ? (int) $data['cursor'] : null,
            isset($data['per_page']) ? (int) $data['per_page'] : 20,
        );

        return (new CatalogsResource($result['data']))->additional(['meta' => $result['meta']]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Resources\DashboardResource;
use App\Services\DashboardService;
use Illuminate\Http\Request;

final class DashboardController extends ApiController
{
    public function __construct(private readonly DashboardService $dashboard) {}

    /**
     * Sin query ni body en el contrato (y sin 422 declarado): solo el path, que
     * ya valida la ruta con el patrón ULID.
     */
    public function show(Request $request, string $profile): DashboardResource
    {
        return new DashboardResource($this->dashboard->forOwner($this->authenticatedUser($request), $profile));
    }
}

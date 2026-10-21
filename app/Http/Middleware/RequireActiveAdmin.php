<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiProblemException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireActiveAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            ! $user instanceof User
            || $user->system_role !== User::ROLE_ADMIN
            || $user->status !== User::STATUS_ACTIVE
        ) {
            throw new ApiProblemException(403, 'forbidden', __('No tenés permisos para realizar esta acción.'));
        }

        return $next($request);
    }
}

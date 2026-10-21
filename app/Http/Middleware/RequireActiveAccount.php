<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiProblemException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new ApiProblemException(401, 'unauthorized', __('No autorizado.'));
        }

        if ($user->status !== User::STATUS_ACTIVE) {
            throw new ApiProblemException(403, 'account_suspended', __('La cuenta está suspendida.'));
        }

        return $next($request);
    }
}

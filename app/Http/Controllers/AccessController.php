<?php

namespace App\Http\Controllers;

use App\Http\Resources\AccessResource;
use App\Services\AccessService;
use App\Services\ProfileService;
use Illuminate\Http\Request;

final class AccessController extends ApiController
{
    public function __construct(
        private readonly ProfileService $profiles,
        private readonly AccessService $access,
    ) {}

    public function show(Request $request, string $profile): AccessResource
    {
        $actor = $this->authenticatedUser($request);
        $ownedProfile = $this->profiles->owned($actor, $profile);

        return new AccessResource($this->access->evaluate($actor, $ownedProfile, null));
    }
}

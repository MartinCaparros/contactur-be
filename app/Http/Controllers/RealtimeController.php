<?php

namespace App\Http\Controllers;

use App\Services\RealtimeChannelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /broadcasting/auth (contrato authorizePrivateBroadcastChannel).
 *
 * Reemplaza la ruta /broadcasting/auth de Laravel: el contrato envuelve la
 * firma en `data` y usa el sobre de error del proyecto. Un canal con forma
 * desconocida es 422 en `channel_name`; uno con forma válida pero no
 * autorizado es 403.
 */
final class RealtimeController extends ApiController
{
    public function __construct(private readonly RealtimeChannelService $channels) {}

    public function authorizeChannel(Request $request): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $data = $this->validatePayload($request, [
            'socket_id' => ['required', 'string', 'min:3', 'max:100', 'regex:'.RealtimeChannelService::SOCKET_ID_PATTERN],
            'channel_name' => ['required', 'string', 'min:3', 'max:200', 'regex:'.RealtimeChannelService::CHANNEL_PATTERN],
        ], ['socket_id', 'channel_name']);

        $signature = $this->channels->authorize($actor, (string) $data['channel_name'], (string) $data['socket_id']);

        // La firma es por socket: no debe quedar en ninguna caché intermedia.
        return response()
            ->json(['data' => $signature])
            ->header('Cache-Control', 'no-store');
    }
}

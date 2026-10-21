<?php

namespace App\Services;

use App\Data\ProfileDashboard;
use App\Enums\DashboardMetricKey;
use App\Models\AvailabilityDay;
use App\Models\User;
use App\Repositories\AvailabilityRepository;
use App\Repositories\ContactRequestRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\ReferenceRepository;

/**
 * Dashboard del perfil actor (T21). KPIs reales y acotados a ESTE perfil; nunca
 * suma otros perfiles de la misma cuenta (R05/P27). No informa contrataciones,
 * servicios realizados ni rankings: un match aceptado solo libera el contacto.
 *
 * Semántica de cada métrica (todas enteras ≥ 0):
 * - pending/accepted/rejected/expired_contact_requests: solicitudes donde el
 *   perfil es emisor O destino, por estado EFECTIVO al instante de la evaluación
 *   de acceso (una pendiente con expires_at vencido cuenta como expired aunque el
 *   job no haya corrido). Las canceladas no se informan.
 * - unread_notifications: misma visibilidad que el centro de notificaciones
 *   (propias del perfil + de cuenta del titular) sin leer.
 * - visible_references: referencias recibidas visibles y no moderadas, es decir
 *   lo que muestra la ficha de directorio, sin su tope.
 * - available_future_days: días habilitados del calendario con fecha posterior a
 *   "hoy" de negocio (America/Argentina/Buenos_Aires). Solo para categorías con
 *   calendario: en tourism_company se OMITE (no se informa 0, porque esa
 *   categoría no publica disponibilidad). Cuenta lo cargado aunque el acceso
 *   esté vencido o suspendido; que el directorio lo muestre depende del acceso.
 *
 * Permiso: solo ownership (perfil ajeno o inexistente → 404). Un perfil vencido o
 * suspendido lee su propio dashboard, como notificaciones (D21); la cuenta
 * suspendida queda en `account.active` (403) igual que esas rutas.
 *
 * Consultas acotadas: perfil + evaluación de acceso + un COUNT … FILTER para
 * solicitudes + un COUNT por métrica restante, sin importar el volumen. Cada
 * COUNT es independiente (sin snapshot común): para un tablero es suficiente.
 */
final class DashboardService
{
    public function __construct(
        private readonly ProfileService $profileService,
        private readonly AccessService $access,
        private readonly ContactRequestRepository $contactRequests,
        private readonly NotificationRepository $notifications,
        private readonly ReferenceRepository $references,
        private readonly AvailabilityRepository $availability,
    ) {}

    public function forOwner(User $actor, string $profilePublicId): ProfileDashboard
    {
        $profile = $this->profileService->owned($actor, $profilePublicId);
        $evaluation = $this->access->evaluate($actor, $profile, null);
        // Un único instante para acceso y conteos: el estado efectivo coincide con evaluated_at.
        $now = $evaluation->evaluatedAt;
        $requests = $this->contactRequests->effectiveStatusCountsForParticipant($profile, $now);

        $metrics = [
            DashboardMetricKey::PendingContactRequests->value => $requests['pending'],
            DashboardMetricKey::AcceptedContactRequests->value => $requests['accepted'],
            DashboardMetricKey::RejectedContactRequests->value => $requests['rejected'],
            DashboardMetricKey::ExpiredContactRequests->value => $requests['expired'],
            DashboardMetricKey::UnreadNotifications->value => $this->notifications->unreadCountForProfile($profile),
            DashboardMetricKey::VisibleReferences->value => $this->references->publicCountForSubject($profile),
        ];

        if ($profile->category->hasAvailability()) {
            $today = $now->setTimezone(AvailabilityDay::BUSINESS_TIMEZONE)->toDateString();
            $metrics[DashboardMetricKey::AvailableFutureDays->value] = $this->availability->countAfter($profile, $today);
        }

        return new ProfileDashboard($profile->public_id, $metrics, $evaluation);
    }
}

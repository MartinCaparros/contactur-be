<?php

namespace App\Services;

use App\Contracts\Clock;
use App\Enums\NotificationType;
use App\Enums\ProfileCategory;
use App\Exceptions\ApiProblemException;
use App\Jobs\SendNotificationEmail;
use App\Mail\ProfileNotificationMail;
use App\Models\ContactRequest;
use App\Models\Profile;
use App\Models\ProfileNotification;
use App\Models\User;
use App\Repositories\ContactRequestRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\UserRepository;
use App\Support\CatalogLabel;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Traits\Localizable;
use LogicException;
use Throwable;

/**
 * Notificaciones in-app + outbox de correo (T19, R18, P26).
 *
 * - record() se llama DENTRO de la transacción de negocio: la fila se confirma o
 *   se descarta con ella. `dedupe_key` UNIQUE + ON CONFLICT DO NOTHING hace que un
 *   reintento (job, replay idempotente, doble ejecución) no duplique el evento.
 * - Solo si la fila se insertó en esta llamada se agenda, después del commit, el
 *   job de correo (si aplica) y la invalidación `notification.created`. Rollback →
 *   ni fila, ni job, ni broadcast.
 * - El correo se resuelve en deliverEmail() con la fila bloqueada: solo envía si
 *   sigue `pending` y la marca `sent` con update condicional; un job duplicado o
 *   un reintento espera el lock y encuentra `sent`.
 * - Si el push a la cola falla después del commit, la fila queda `pending` y el
 *   barrido (contactur:dispatch-pending-notification-emails) la reencola; el
 *   request de negocio no falla por eso porque su transacción ya confirmó.
 * - Notificaciones de cuenta (profile_id null): bienvenida, verificación y
 *   suspensión/reactivación de cuenta. Se listan en cada perfil propio y se leen
 *   una sola vez. La invalidación sale por el canal de cada perfil propio.
 * - Idioma: cada correo sale en el del destinatario (`User::preferredLocale()`), no en
 *   el de quien disparó el evento ni en el del worker o el scheduler. El contenido se
 *   arma y se renderiza dentro de ese idioma.
 */
final class NotificationService
{
    use Localizable;

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly UserRepository $users,
        private readonly ProfileRepository $profiles,
        private readonly ProfileService $profileService,
        private readonly ContactRequestRepository $contactRequests,
        private readonly RealtimeInvalidationService $realtime,
        private readonly Clock $clock,
    ) {}

    /** @return array{items: Collection<int, ProfileNotification>, next_cursor: string|null} */
    public function list(User $actor, string $profilePublicId, bool $unreadOnly, ?int $cursor, int $perPage): array
    {
        // Solo ownership: un perfil vencido o suspendido debe poder leer por qué.
        $profile = $this->profileService->owned($actor, $profilePublicId);

        return $this->notifications->listForProfile($profile, $unreadOnly, $cursor, $perPage);
    }

    public function markRead(User $actor, string $profilePublicId, string $notificationPublicId): ProfileNotification
    {
        $profile = $this->profileService->owned($actor, $profilePublicId);
        $notification = $this->notifications->findVisibleToProfile($profile, $notificationPublicId);

        if ($notification === null) {
            throw new ApiProblemException(404, 'not_found', 'El recurso solicitado no existe.');
        }
        if ($notification->read_at !== null) {
            return $notification;
        }

        return $this->notifications->markRead($notification, $this->clock->now());
    }

    /**
     * Registra el evento dentro de la transacción del llamador. Devuelve true si
     * insertó; false si la clave ya existía (reintento).
     *
     * @param  array<string, mixed>  $safeData  solo IDs públicos, etiquetas y versiones
     */
    public function record(User $user, ?Profile $profile, NotificationType $type, array $safeData, string $dedupeKey): bool
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('NotificationService::record debe ejecutarse dentro de la transacción de negocio.');
        }
        if ($profile !== null && $profile->owner_user_id !== $user->id) {
            throw new LogicException('La notificación de perfil debe ir al titular del perfil.');
        }

        $emailStatus = match (true) {
            ! $type->sendsEmail() => ProfileNotification::EMAIL_NOT_APPLICABLE,
            ! $this->emailAllowed($user, $type) => ProfileNotification::EMAIL_SKIPPED_PREFERENCE,
            default => ProfileNotification::EMAIL_PENDING,
        };
        $inserted = $this->notifications->insertIfAbsent(
            $user->id,
            $profile?->id,
            $type,
            $safeData,
            $dedupeKey,
            $emailStatus,
            $this->clock->now(),
        );

        if ($inserted === null) {
            return false;
        }

        $channels = $profile !== null ? [$profile] : $this->profiles->ownedSummaries($user)->all();
        foreach ($channels as $channelProfile) {
            $this->realtime->notificationCreated($channelProfile, $inserted['public_id']);
        }

        if ($emailStatus === ProfileNotification::EMAIL_PENDING) {
            $notificationId = $inserted['id'];
            DB::afterCommit(static function () use ($notificationId): void {
                try {
                    app(Dispatcher::class)->dispatch(new SendNotificationEmail($notificationId));
                } catch (Throwable $exception) {
                    // La fila ya confirmó en `pending`: el barrido la reencola.
                    report($exception);
                }
            });
        }

        return true;
    }

    public function welcome(User $user): void
    {
        $this->record($user, null, NotificationType::Welcome, [], 'welcome:'.$user->public_id);
    }

    /** Una por token consumido: registro inicial o cambio de email confirmado. */
    public function emailVerified(User $user, string $accountTokenId, bool $emailChange): void
    {
        $this->record($user, null, NotificationType::EmailVerified, [
            'source' => $emailChange ? 'email_change' : 'registration',
        ], 'email_verified:'.$accountTokenId);
    }

    public function contactRequestReceived(ContactRequest $request, Profile $sender, Profile $recipient): void
    {
        $this->recordContactRequestEvent(NotificationType::ContactRequestReceived, $request, $recipient, $sender, 'recipient', '');
    }

    /** Aceptación o rechazo: va al emisor. */
    public function contactRequestResponded(NotificationType $type, ContactRequest $request, Profile $sender, Profile $recipient): void
    {
        if (! in_array($type, [NotificationType::ContactRequestAccepted, NotificationType::ContactRequestRejected], true)) {
            throw new LogicException('Tipo de respuesta inválido.');
        }

        $this->recordContactRequestEvent($type, $request, $sender, $recipient, 'sender', '');
    }

    /** Vencimiento: una fila por participante, con clave por solicitud y rol (P23). */
    public function contactRequestExpired(ContactRequest $request, Profile $sender, Profile $recipient): void
    {
        $this->recordContactRequestEvent(NotificationType::ContactRequestExpired, $request, $sender, $recipient, 'sender', ':sender');
        $this->recordContactRequestEvent(NotificationType::ContactRequestExpired, $request, $recipient, $sender, 'recipient', ':recipient');
    }

    /**
     * Cambio administrativo con su auditoría como clave: una fila por mutación
     * auditada. Nunca incluye la identidad del admin ni el motivo interno.
     *
     * `$dedupeRole` separa varios destinatarios de una misma auditoría (T20: autor
     * y destinatario de una referencia moderada): la clave queda
     * `administrative_change:{audit}:{rol}`. Sin rol, la clave histórica sin sufijo.
     *
     * @param  array<string, mixed>  $extra
     */
    public function administrativeChange(
        User $owner,
        ?Profile $profile,
        string $change,
        string $auditLogPublicId,
        array $extra = [],
        ?string $dedupeRole = null,
    ): void {
        $safeData = ['change' => $change];
        if ($profile !== null) {
            $safeData['profile_id'] = $profile->public_id;
        }

        $this->record(
            $owner,
            $profile,
            NotificationType::AdministrativeChange,
            [...$safeData, ...$extra],
            'administrative_change:'.$auditLogPublicId.($dedupeRole !== null ? ':'.$dedupeRole : ''),
        );
    }

    /** Preferencia vigente: entrada ausente = habilitado; los esenciales ignoran el opt-out. */
    public function emailAllowed(User $user, NotificationType $type): bool
    {
        if ($type->isEssential()) {
            return true;
        }

        // Forma validada por AccountController (event_type distinto, email_enabled booleano estricto).
        foreach ($user->notification_preferences ?? [] as $preference) {
            if ($preference['event_type'] === $type->value) {
                return $preference['email_enabled'] !== false;
            }
        }

        return true;
    }

    /**
     * Ejecutado por SendNotificationEmail. Con la fila bloqueada: si ya no está
     * `pending` no hace nada; re-evalúa preferencia y estado de cuenta; envía y
     * marca `sent` en la misma transacción. Si el envío falla, rollback: la fila
     * sigue `pending` y el job reintenta.
     */
    public function deliverEmail(int $notificationId): void
    {
        DB::transaction(function () use ($notificationId): void {
            $notification = $this->notifications->lockById($notificationId);

            if ($notification === null || $notification->email_status !== ProfileNotification::EMAIL_PENDING) {
                return;
            }

            $user = $this->users->findById($notification->user_id);
            if ($user === null || ! $notification->type->sendsEmail()) {
                $this->notifications->resolvePendingEmail($notification->id, ProfileNotification::EMAIL_NOT_APPLICABLE, null);

                return;
            }
            if (! $this->emailAllowed($user, $notification->type)) {
                $this->notifications->resolvePendingEmail($notification->id, ProfileNotification::EMAIL_SKIPPED_PREFERENCE, null);

                return;
            }
            // Cuenta suspendida: solo salen los esenciales (p. ej. el aviso de la suspensión).
            if ($user->status !== User::STATUS_ACTIVE && ! $notification->type->isEssential()) {
                $this->notifications->resolvePendingEmail($notification->id, ProfileNotification::EMAIL_NOT_APPLICABLE, null);

                return;
            }

            // Textos, rótulos de categoría y de catálogo en el idioma del destinatario.
            $locale = $user->preferredLocale();
            $mail = $this->withLocale($locale, fn (): ?ProfileNotificationMail => $this->composeMail($notification));
            if ($mail === null) {
                $this->notifications->resolvePendingEmail($notification->id, ProfileNotification::EMAIL_NOT_APPLICABLE, null);

                return;
            }

            Mail::to($user->email)->locale($locale)->send($mail);
            $this->notifications->resolvePendingEmail($notification->id, ProfileNotification::EMAIL_SENT, $this->clock->now());
        });
    }

    public function markEmailFailed(int $notificationId): void
    {
        $this->notifications->resolvePendingEmail($notificationId, ProfileNotification::EMAIL_FAILED, null);
    }

    /**
     * Reencola correos `pending` más viejos que $minutes (push perdido). Es seguro
     * repetirlo: deliverEmail solo envía una vez por fila.
     *
     * @return int cantidad reencolada
     */
    public function redispatchPendingEmails(int $minutes, int $limit): int
    {
        $ids = $this->notifications->pendingEmailIds($this->clock->now()->subMinutes($minutes), $limit);
        $dispatcher = app(Dispatcher::class);

        foreach ($ids as $id) {
            $dispatcher->dispatch(new SendNotificationEmail($id));
        }

        return count($ids);
    }

    private function recordContactRequestEvent(
        NotificationType $type,
        ContactRequest $request,
        Profile $target,
        Profile $counterpart,
        string $role,
        string $keySuffix,
    ): void {
        $owner = $this->owner($target);

        $this->record($owner, $target, $type, [
            'profile_id' => $target->public_id,
            'contact_request_id' => $request->public_id,
            'role' => $role,
            'counterpart_profile_id' => $counterpart->public_id,
            'counterpart_display_name' => $counterpart->display_name,
            'counterpart_category' => $counterpart->category->value,
        ], $type->value.':'.$request->public_id.$keySuffix);
    }

    private function owner(Profile $profile): User
    {
        $owner = $profile->relationLoaded('owner') ? $profile->getRelation('owner') : null;
        if ($owner instanceof User && $owner->id === $profile->owner_user_id) {
            return $owner;
        }

        $owner = $this->users->findById($profile->owner_user_id);
        if ($owner === null) {
            throw new LogicException('Perfil sin titular.');
        }

        return $owner;
    }

    /**
     * Contenido mínimo (diseño: snapshots e IDs). El correo de solicitud muestra
     * la identidad pública de la empresa, el servicio y un link de login; ningún
     * correo incluye email/teléfono de contacto ni documentos.
     */
    private function composeMail(ProfileNotification $notification): ?ProfileNotificationMail
    {
        $data = $notification->safe_data;
        $profileId = is_string($data['profile_id'] ?? null) ? $data['profile_id'] : null;
        $counterpart = $this->counterpartLabel($data);
        $privacy = __('Por seguridad, Contactur no envía datos de contacto por correo: los ves dentro de la plataforma.');

        switch ($notification->type) {
            case NotificationType::ContactRequestReceived:
                $request = $this->contactRequests->findForNotificationEmail((string) ($data['contact_request_id'] ?? ''));
                if ($request === null || $profileId === null) {
                    return null;
                }
                $lines = [
                    __(':counterpart te envió una solicitud de contacto.', ['counterpart' => $counterpart]),
                    __('Servicio solicitado: :service', ['service' => $request->service_description]),
                ];
                if ($request->requested_from !== null && $request->requested_to !== null) {
                    $lines[] = __('Fechas: del :from al :to.', [
                        'from' => $this->date($request->requested_from),
                        'to' => $this->date($request->requested_to),
                    ]);
                }
                if ($request->requestedCity !== null) {
                    $lines[] = __('Ciudad: :city.', ['city' => $request->requestedCity->name]);
                }
                $lines[] = __('Ingresá a Contactur para aceptarla o rechazarla.');

                return new ProfileNotificationMail(
                    __('Nueva solicitud de contacto'),
                    __('Recibiste una solicitud de contacto'),
                    $lines,
                    __('Ingresar para responder'),
                    $this->loginUrl('/app/requests/'.$request->public_id.'?profile='.$profileId),
                    $privacy,
                );
            case NotificationType::ContactRequestAccepted:
                return $this->requestMail(
                    __('Solicitud aceptada'),
                    __(':counterpart aceptó tu solicitud de contacto. Ingresá a Contactur para ver cómo continuar.', ['counterpart' => $counterpart]),
                    $data,
                    $profileId,
                    $privacy,
                );
            case NotificationType::ContactRequestRejected:
                return $this->requestMail(
                    __('Solicitud rechazada'),
                    __(':counterpart rechazó tu solicitud de contacto.', ['counterpart' => $counterpart]),
                    $data,
                    $profileId,
                    null,
                );
            case NotificationType::ContactRequestExpired:
                return $this->requestMail(
                    __('Solicitud vencida'),
                    ($data['role'] ?? null) === 'recipient'
                        ? __('La solicitud de contacto de :counterpart venció sin respuesta.', ['counterpart' => $counterpart])
                        : __('Tu solicitud de contacto a :counterpart venció sin respuesta.', ['counterpart' => $counterpart]),
                    $data,
                    $profileId,
                    null,
                );
            case NotificationType::AdministrativeChange:
                return $this->administrativeMail($data, $profileId);
            default:
                return null;
        }
    }

    /** @param  array<string, mixed>  $data */
    private function requestMail(string $subject, string $line, array $data, ?string $profileId, ?string $footer): ?ProfileNotificationMail
    {
        $requestId = $data['contact_request_id'] ?? null;
        if (! is_string($requestId) || $profileId === null) {
            return null;
        }

        return new ProfileNotificationMail(
            $subject,
            $subject,
            [$line],
            __('Ver la solicitud'),
            $this->loginUrl('/app/requests/'.$requestId.'?profile='.$profileId),
            $footer,
        );
    }

    /** @param  array<string, mixed>  $data */
    private function administrativeMail(array $data, ?string $profileId): ?ProfileNotificationMail
    {
        $profileName = $profileId !== null ? $this->profileName($profileId) : null;
        // Frases nominales completas (`:profile`, `:document`): el resto de la oración
        // queda en un solo literal para que cada idioma ordene sus palabras.
        $profile = ['profile' => $profileName !== null ? __('tu perfil «:name»', ['name' => $profileName]) : __('tu perfil')];
        $document = ['document' => is_string($data['document_type'] ?? null)
            ? __('el documento «:type»', ['type' => CatalogLabel::translate($data['document_type'])])
            : __('un documento')];
        $author = ($data['role'] ?? null) === 'author';

        [$subject, $line, $path] = match ($data['change'] ?? null) {
            'access_updated' => [__('Cambios en tu acceso'), __('Administración actualizó el acceso de :profile.', $profile), '/app/access'],
            'profile_suspended' => [__('Perfil suspendido'), __('Administración suspendió :profile. Mientras siga suspendido no aparece en el directorio ni puede operar solicitudes.', $profile), '/app/access'],
            'profile_restored' => [__('Perfil restaurado'), __('Administración restauró :profile.', $profile), '/app/access'],
            'account_suspended' => [__('Cuenta suspendida'), __('Administración suspendió tu cuenta de Contactur. Si creés que se trata de un error, escribinos desde el formulario de contacto.'), null],
            'account_reactivated' => [__('Cuenta reactivada'), __('Administración reactivó tu cuenta de Contactur. Ya podés volver a ingresar.'), '/app'],
            'document_approved' => [__('Documento aprobado'), __('Administración aprobó :document de :profile.', [...$document, ...$profile]), '/app/profile'],
            'document_rejected' => [__('Documento rechazado'), __('Administración rechazó :document de :profile. Ingresá a Contactur para ver el motivo y volver a cargarlo.', [...$document, ...$profile]), '/app/profile'],
            // T20: copy genérico; nunca el texto de la referencia ni el motivo del admin.
            'reference_hidden' => [__('Referencia ocultada'), $author
                ? __('Administración ocultó una referencia que escribiste desde :profile. Mientras siga moderada no se muestra en ninguna ficha.', $profile)
                : __('Administración ocultó una referencia que recibió :profile. Mientras siga moderada no se muestra en tu ficha.', $profile), '/app/references'],
            'reference_restored' => [__('Referencia restaurada'), $author
                ? __('Administración restauró una referencia que escribiste desde :profile.', $profile)
                : __('Administración restauró una referencia que recibió :profile. Se muestra en tu ficha solo si la tenés como visible.', $profile), '/app/references'],
            default => [null, null, null],
        };

        if ($subject === null || $line === null) {
            return null;
        }

        $url = null;
        if ($path !== null) {
            $url = $this->loginUrl($profileId !== null ? $path.'?profile='.$profileId : $path);
        }

        return new ProfileNotificationMail(
            $subject,
            $subject,
            [$line],
            $url !== null ? __('Ingresar a Contactur') : null,
            $url,
        );
    }

    /** @param  array<string, mixed>  $data */
    private function counterpartLabel(array $data): string
    {
        $name = is_string($data['counterpart_display_name'] ?? null) ? $data['counterpart_display_name'] : __('Un perfil');
        $category = ProfileCategory::tryFrom((string) ($data['counterpart_category'] ?? ''));

        return $category !== null ? $name.' ('.$category->label().')' : $name;
    }

    private function profileName(string $profilePublicId): ?string
    {
        return $this->profiles->findByPublicId($profilePublicId)?->display_name;
    }

    /** dd/mm/aaaa en los dos idiomas (en-GB conserva el orden día/mes). */
    private function date(string $date): string
    {
        return implode('/', array_reverse(explode('-', substr($date, 0, 10))));
    }

    /** Link de login con returnTo interno, codificado como lo hace el frontend. */
    private function loginUrl(string $returnTo): string
    {
        return (string) config('contactur.frontend_url').'/?auth=login&returnTo='.rawurlencode($returnTo);
    }
}

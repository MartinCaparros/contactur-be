<?php

namespace App\Services;

use App\Contracts\Clock;
use App\Exceptions\ApiProblemException;
use App\Jobs\SendSupportInquiryEmail;
use App\Mail\SupportInquiryMail;
use App\Models\SupportInquiry;
use App\Repositories\SupportInquiryRepository;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Contacto público (T19/R23).
 *
 * - Nunca consulta `users`: la respuesta y los efectos son idénticos exista o no
 *   una cuenta con ese email.
 * - 202 solo si la fila confirmó y el job quedó encolado. Orden: commit de la
 *   fila → push. Si el push falla se borra la fila (compensación) y se responde
 *   503: no queda una consulta `pending` sin correo ni un "éxito" falso. Se
 *   encola después del commit para que el worker siempre encuentre la fila.
 * - La IP se guarda como HMAC-SHA256 con la APP_KEY (correlación de abuso sin IP
 *   en claro).
 */
final class SupportInquiryService
{
    public function __construct(
        private readonly SupportInquiryRepository $inquiries,
        private readonly Clock $clock,
    ) {}

    /** @param  array{name: string, email: string, category: string|null, message: string}  $data */
    public function submit(array $data, ?string $ipAddress): SupportInquiry
    {
        try {
            $inquiry = DB::transaction(fn (): SupportInquiry => $this->inquiries->create(
                $data['name'],
                $data['email'],
                $data['category'],
                $data['message'],
                $this->ipHash($ipAddress),
                $this->clock->now(),
            ));
        } catch (Throwable $exception) {
            report($exception);

            throw $this->unavailable();
        }

        try {
            // Resuelto en cada llamada para respetar fakes del dispatcher en pruebas.
            app(Dispatcher::class)->dispatch(new SendSupportInquiryEmail($inquiry->id));
        } catch (Throwable $exception) {
            report($exception);
            $this->compensate($inquiry);

            throw $this->unavailable();
        }

        return $inquiry;
    }

    /** Ejecutado por SendSupportInquiryEmail: una sola entrega por consulta. */
    public function deliverEmail(int $inquiryId): void
    {
        DB::transaction(function () use ($inquiryId): void {
            $inquiry = $this->inquiries->lockById($inquiryId);

            if ($inquiry === null || $inquiry->emailed_at !== null) {
                return;
            }

            // Va al equipo de soporte: siempre en español, sin importar el idioma de quien consultó.
            Mail::to((string) config('contactur.support.email'))->locale('es')->send(new SupportInquiryMail(
                $inquiry->public_id,
                $inquiry->name,
                $inquiry->email,
                $inquiry->category,
                $inquiry->message,
            ));
            $this->inquiries->markEmailed($inquiry, $this->clock->now());
        });
    }

    private function compensate(SupportInquiry $inquiry): void
    {
        try {
            $this->inquiries->delete($inquiry);
        } catch (Throwable $exception) {
            // Doble falla (cola y base): queda registrada para revisión manual.
            report($exception);
        }
    }

    private function ipHash(?string $ipAddress): string
    {
        return hash_hmac('sha256', $ipAddress ?? 'unknown', (string) config('app.key'));
    }

    private function unavailable(): ApiProblemException
    {
        return new ApiProblemException(
            503,
            'service_unavailable',
            __('No pudimos registrar tu consulta. Intentá nuevamente en unos minutos.'),
        );
    }
}

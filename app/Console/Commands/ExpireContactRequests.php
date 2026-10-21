<?php

namespace App\Console\Commands;

use App\Services\ContactRequestService;
use Illuminate\Console\Command;

/**
 * R15: materializa `expired` en las solicitudes pendientes vencidas. El estado
 * efectivo ya las trata como vencidas aunque este job no corra; el job deja la
 * base consistente y es el punto donde T19 disparará `contact_request_expired`.
 * Idempotente: una segunda corrida no cambia filas.
 */
final class ExpireContactRequests extends Command
{
    private const MAX_BATCH = 5000;

    /** @var string */
    protected $signature = 'contactur:expire-contact-requests
        {--batch=500 : Filas por lote atómico (1–5000)}';

    /** @var string */
    protected $description = 'Marca como expired las solicitudes de contacto pendientes vencidas.';

    public function handle(ContactRequestService $contactRequests): int
    {
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => self::MAX_BATCH],
        ]);

        if ($batch === false) {
            $this->components->error('--batch debe ser un entero entre 1 y '.self::MAX_BATCH.'.');

            return self::FAILURE;
        }

        $expired = $contactRequests->expireDue($batch);
        $this->components->info('Solicitudes marcadas como expired: '.count($expired).'.');

        return self::SUCCESS;
    }
}

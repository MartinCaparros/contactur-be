<?php

use App\Console\Commands\OperationsHealth;
use App\Contracts\Clock;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// R15: el estado efectivo ya vence por reloj; el job materializa `expired` y
// registra `contact_request_expired` (T19). Idempotente; withoutOverlapping evita
// corridas solapadas.
Schedule::command('contactur:expire-contact-requests')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// T19: outbox de correos; reencola los `pending` cuyo push se perdió.
Schedule::command('contactur:dispatch-pending-notification-emails')
    ->everyTenMinutes()
    ->withoutOverlapping();

// T24: latido del scheduler para `contactur:ops-health` (el healthcheck de Compose
// solo ve que el proceso existe; esto prueba que el scheduler efectivamente corre).
Schedule::call(static function (): void {
    Cache::put(OperationsHealth::HEARTBEAT_KEY, app(Clock::class)->now()->toIso8601String(), now()->addHour());
})
    ->everyMinute()
    ->name('contactur:scheduler-heartbeat');

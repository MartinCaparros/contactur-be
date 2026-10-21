<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Enums\ProfileCategory;
use App\Http\Middleware\SetRequestLocale;
use App\Jobs\SendNotificationEmail;
use App\Mail\ProfileNotificationMail;
use App\Mail\SupportInquiryMail;
use App\Models\City;
use App\Models\DocumentType;
use App\Models\Language;
use App\Models\Profile;
use App\Models\Service;
use App\Models\Specialty;
use App\Models\User;
use App\Notifications\EmailChangedNotification;
use App\Notifications\EmailVerificationNotification;
use App\Notifications\EmailVerifiedNotification;
use App\Notifications\PasswordResetNotification;
use App\Services\NotificationService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Localizable;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\Support\ReferenceFixtures;
use Tests\TestCase;

/**
 * Español e inglés: el BFF manda `Accept-Language: es|en` con el idioma de la interfaz;
 * la cuenta guarda el suyo en `users.locale` (`es-AR` | `en`) y los correos salen en el
 * idioma del destinatario. Los `code` del sobre de error no cambian con el idioma.
 */
final class LocalizationTest extends TestCase
{
    use Localizable, ReferenceFixtures, RefreshDatabase;

    private const PASSWORD = 'Clave-segura-2026';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_only_two_languages_any_english_variant_is_english(): void
    {
        $this->assertSame('en', SetRequestLocale::fromHeader('en'));
        $this->assertSame('en', SetRequestLocale::fromHeader('en-GB,en;q=0.9'));
        $this->assertSame('en', SetRequestLocale::fromHeader('EN_us'));
        $this->assertSame('es', SetRequestLocale::fromHeader('es-AR'));
        $this->assertSame('es', SetRequestLocale::fromHeader('fr-FR,en;q=0.5'));
        $this->assertSame('es', SetRequestLocale::fromHeader('english'));
        $this->assertSame('es', SetRequestLocale::fromHeader(''));
        $this->assertSame('es', SetRequestLocale::fromHeader(null));
    }

    public function test_register_stores_the_interface_language_of_the_signup(): void
    {
        $spanish = $this->register('ana@example.test');
        $english = $this->register('john@example.test', 'en');

        $this->assertSame(User::LOCALE_SPANISH, $spanish['user']['locale']);
        $this->assertSame(User::LOCALE_ENGLISH, $english['user']['locale']);
        $this->assertSame('es', User::query()->where('email', 'ana@example.test')->firstOrFail()->preferredLocale());
        $this->assertSame('en', User::query()->where('email', 'john@example.test')->firstOrFail()->preferredLocale());
    }

    public function test_account_language_is_updated_with_patch_me_and_only_accepts_the_contract_values(): void
    {
        $token = $this->register('ana@example.test')['access_token'];

        $this->withToken($token)->patchJson('/api/v1/me', ['locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.user.locale', 'en');
        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.user.locale', 'en');
        $this->assertSame('en', User::query()->where('email', 'ana@example.test')->firstOrFail()->preferredLocale());

        foreach (['fr', 'es', 'EN', 'en-US', ''] as $invalid) {
            $this->withToken($token)->patchJson('/api/v1/me', ['locale' => $invalid])
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation_failed');
        }

        $this->withToken($token)->patchJson('/api/v1/me', ['locale' => 'es-AR'])
            ->assertOk()
            ->assertJsonPath('data.user.locale', 'es-AR');
    }

    public function test_field_messages_follow_the_request_language_and_codes_do_not_change(): void
    {
        $token = $this->register('ana@example.test')['access_token'];

        $spanish = $this->withToken($token)->patchJson('/api/v1/me', ['name' => 'A'])->assertUnprocessable();
        $english = $this->withToken($token)
            ->withHeader('Accept-Language', 'en')
            ->patchJson('/api/v1/me', ['name' => 'A'])
            ->assertUnprocessable();

        $this->assertSame('El campo nombre debe tener al menos 2 caracteres.', $spanish->json('error.fields.name.0'));
        $this->assertSame('The name field must be at least 2 characters.', $english->json('error.fields.name.0'));
        $this->assertSame($spanish->json('error.code'), $english->json('error.code'));
    }

    public function test_error_envelope_messages_follow_the_request_language_and_codes_do_not_change(): void
    {
        $token = $this->register('ana@example.test')['access_token'];

        // 422 del renderer de ValidationException.
        $this->assertEnvelope(
            fn () => $this->withToken($token)->patchJson('/api/v1/me', ['name' => 'A']),
            422,
            'validation_failed',
            'Los datos enviados no son válidos.',
            'The data sent is not valid.',
        );

        // ApiProblemException del middleware de autenticación.
        $this->assertEnvelope(
            fn () => $this->withoutToken()->getJson('/api/v1/me'),
            401,
            'unauthorized',
            'No autorizado.',
            'Unauthorised.',
        );

        // ApiProblemException de dominio con mensaje por campo.
        $wrongPassword = [
            'current_password' => 'Otra-clave-2026',
            'new_password' => 'Clave-nueva-segura-2026',
            'new_password_confirmation' => 'Clave-nueva-segura-2026',
        ];
        [$spanish, $english] = $this->assertEnvelope(
            fn () => $this->withToken($token)->postJson('/api/v1/me/change-password', $wrongPassword),
            422,
            'validation_failed',
            'Los datos enviados no son válidos.',
            'The data sent is not valid.',
        );
        $this->assertSame('La contraseña actual no es correcta.', $spanish->json('error.fields.current_password.0'));
        $this->assertSame('The current password is incorrect.', $english->json('error.fields.current_password.0'));

        // Ruta inexistente: renderer de HttpException.
        $this->assertEnvelope(
            fn () => $this->withToken($token)->getJson('/api/v1/no-existe'),
            404,
            'not_found',
            'El recurso solicitado no existe.',
            'The requested resource does not exist.',
        );
    }

    public function test_success_messages_follow_the_request_language(): void
    {
        $token = $this->register('ana@example.test')['access_token'];
        $change = fn (string $current, string $new) => [
            'current_password' => $current,
            'new_password' => $new,
            'new_password_confirmation' => $new,
        ];

        $this->withToken($token)
            ->withHeader('Accept-Language', 'en')
            ->postJson('/api/v1/me/change-password', $change(self::PASSWORD, 'Clave-nueva-segura-2026'))
            ->assertOk()
            ->assertExactJson(['data' => ['message' => 'Password updated.']]);
    }

    public function test_catalog_names_follow_the_request_language(): void
    {
        $this->seed(CatalogSeeder::class);
        $token = $this->register('ana@example.test')['access_token'];

        $spanish = $this->withToken($token)->getJson('/api/v1/catalogs')->assertOk();
        $english = $this->withToken($token)
            ->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/catalogs')
            ->assertOk();

        $names = fn ($response, string $catalog, string $field = 'name'): array => array_column($response->json('data.'.$catalog), $field, 'code');

        $this->assertSame(['es' => 'Español', 'en' => 'Inglés', 'pt' => 'Portugués'], $names($spanish, 'languages'));
        $this->assertSame(['es' => 'Spanish', 'en' => 'English', 'pt' => 'Portuguese'], $names($english, 'languages'));
        $this->assertSame('Traslado de pasajeros', $names($spanish, 'services')['passenger_transfer']);
        $this->assertSame('Passenger transfers', $names($english, 'services')['passenger_transfer']);
        $this->assertSame('Esquí', $names($spanish, 'specialties')['skiing']);
        $this->assertSame('Skiing', $names($english, 'specialties')['skiing']);
        $this->assertSame('Chofer', $names($spanish, 'categories')['driver']);
        $this->assertSame('Driver', $names($english, 'categories')['driver']);
        $this->assertSame('Habilitación profesional de guía', $names($spanish, 'document_types', 'label')['guide_license']);
        $this->assertSame('Professional guide licence', $names($english, 'document_types', 'label')['guide_license']);

        // Ningún dato del catálogo queda sin traducción, aunque coincida en los dos idiomas
        // (Trekking, Instructor). Las ciudades son nombres propios y no se traducen.
        /** @var array<string, string> $translations */
        $translations = json_decode((string) file_get_contents(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR);
        $seeded = array_merge(
            Language::query()->pluck('name')->all(),
            Service::query()->pluck('name')->all(),
            Specialty::query()->pluck('name')->all(),
            DocumentType::query()->pluck('label')->all(),
            array_column($spanish->json('data.categories'), 'name'),
        );
        foreach ($seeded as $name) {
            $this->assertArrayHasKey($name, $translations, 'Falta la traducción de "'.$name.'" en lang/en.json.');
        }
        $this->assertSame(
            array_column($spanish->json('data.cities'), 'name'),
            array_column($english->json('data.cities'), 'name'),
        );
    }

    public function test_account_notifications_use_the_account_language_not_the_request_language(): void
    {
        // Emails únicos: el limiter de recuperación vive en Redis y sobrevive entre corridas.
        [$anaEmail, $johnEmail] = ['ana-'.Str::lower(Str::ulid()).'@example.test', 'john-'.Str::lower(Str::ulid()).'@example.test'];
        $this->register($anaEmail);
        $this->register($johnEmail, 'en');
        $ana = User::query()->where('email', $anaEmail)->firstOrFail();
        $john = User::query()->where('email', $johnEmail)->firstOrFail();

        // Cada uno pide el reseteo desde una interfaz en el otro idioma.
        $this->withHeader('Accept-Language', 'en')->postJson('/api/v1/auth/forgot-password', ['email' => $ana->email])->assertAccepted();
        $this->withHeader('Accept-Language', 'es')->postJson('/api/v1/auth/forgot-password', ['email' => $john->email])->assertAccepted();

        $expected = [
            'es' => [$ana, [
                EmailVerificationNotification::class => ['Verificá tu email', 'Confirmá tu dirección de email para continuar en Contactur.', 'Verificar email'],
                PasswordResetNotification::class => ['Restablecé tu contraseña', 'Recibimos una solicitud para restablecer la contraseña de tu cuenta Contactur.', 'Restablecer contraseña'],
            ], 'Saludos, el equipo de Contactur', 'copiá y pegá esta dirección en tu navegador'],
            'en' => [$john, [
                EmailVerificationNotification::class => ['Verify your email', 'Confirm your email address to continue on Contactur.', 'Verify email'],
                PasswordResetNotification::class => ['Reset your password', 'We received a request to reset the password for your Contactur account.', 'Reset password'],
            ], 'Regards, the Contactur team', 'copy and paste the URL below'],
        ];
        foreach ($expected as $language => [$user, $notifications, $salutation, $subcopy]) {
            foreach ($notifications as $class => [$subject, $intro, $action]) {
                $sent = null;
                Notification::assertSentTo($user, $class, function (EmailVerificationNotification|PasswordResetNotification $notification, array $channels, User $notifiable, ?string $locale) use (&$sent, $language): bool {
                    $sent = [$notification, $locale];

                    return $locale === $language;
                });
                [$notification, $locale] = $sent;
                // Igual que Laravel al enviar: el mensaje se arma y renderiza en el locale elegido.
                [$message, $html] = $this->withLocale($locale, function () use ($notification, $user): array {
                    $message = $notification->toMail($user);

                    return [$message, (string) $message->render()];
                });
                $this->assertSame($subject, $message->subject);
                $this->assertSame($intro, $message->introLines[0]);
                $this->assertSame($action, $message->actionText);
                $this->assertStringContainsString($salutation, $html);
                $this->assertStringContainsString($subcopy, $html);
                $this->assertStringContainsString('lang="'.$language.'"', $html);
            }
        }
    }

    public function test_queued_account_emails_are_delivered_in_the_account_language(): void
    {
        // Canal de correo real (cola sync, transporte `array`): mismo camino que el worker.
        Notification::swap(new ChannelManager($this->app));
        [$anaEmail, $johnEmail] = ['ana-'.Str::lower(Str::ulid()).'@example.test', 'john-'.Str::lower(Str::ulid()).'@example.test'];
        $this->register($anaEmail);
        $this->register($johnEmail, 'en');
        $this->withHeader('Accept-Language', 'en')->postJson('/api/v1/auth/forgot-password', ['email' => $anaEmail])->assertAccepted();
        $this->withHeader('Accept-Language', 'es')->postJson('/api/v1/auth/forgot-password', ['email' => $johnEmail])->assertAccepted();

        $ana = $this->deliveredTo($anaEmail);
        $john = $this->deliveredTo($johnEmail);

        $this->assertSame(['Verificá tu email', 'Restablecé tu contraseña'], array_map(fn (Email $email): ?string => $email->getSubject(), $ana));
        $this->assertSame(['Verify your email', 'Reset your password'], array_map(fn (Email $email): ?string => $email->getSubject(), $john));
        $this->assertStringContainsString('Recibimos una solicitud para restablecer la contraseña', (string) $ana[1]->getHtmlBody());
        $this->assertStringContainsString('Saludos, el equipo de Contactur', (string) $ana[1]->getTextBody());
        $this->assertStringContainsString('lang="en"', (string) $john[1]->getHtmlBody());
        $this->assertStringContainsString('We received a request to reset the password', (string) $john[1]->getHtmlBody());
        $this->assertStringContainsString('Regards, the Contactur team', (string) $john[1]->getTextBody());
        $this->assertStringNotContainsString('Restablec', (string) $john[1]->getHtmlBody());
    }

    public function test_email_change_messages_to_bare_addresses_use_the_account_language(): void
    {
        foreach (['en' => 'es', 'es' => 'en'] as $accountLanguage => $requestLanguage) {
            $old = 'old-'.$accountLanguage.'@example.test';
            $new = 'new-'.$accountLanguage.'@example.test';
            $token = $this->register($old, $accountLanguage)['access_token'];
            $user = User::query()->where('email', $old)->firstOrFail();
            $verification = null;
            Notification::assertSentTo($user, EmailVerificationNotification::class, function (EmailVerificationNotification $notification) use (&$verification): bool {
                $verification = $notification->token;

                return true;
            });
            $this->withHeader('Accept-Language', $requestLanguage);
            $this->postJson('/api/v1/auth/email/verify', ['token' => $verification])->assertOk();
            $this->withToken($token)->postJson('/api/v1/me/change-email', ['email' => $new, 'password' => self::PASSWORD])->assertAccepted();

            $change = null;
            Notification::assertSentOnDemand(EmailVerificationNotification::class, function (
                EmailVerificationNotification $notification,
                array $channels,
                AnonymousNotifiable $notifiable,
                ?string $locale,
            ) use (&$change, $new, $accountLanguage): bool {
                if (! $notification->emailChange || $notifiable->routeNotificationFor('mail') !== $new) {
                    return false;
                }
                $change = $notification;

                return $locale === $accountLanguage;
            });
            $this->assertInstanceOf(EmailVerificationNotification::class, $change);
            $this->postJson('/api/v1/auth/email/verify', ['token' => $change->token])->assertOk();

            $changed = null;
            Notification::assertSentOnDemand(EmailChangedNotification::class, function (
                EmailChangedNotification $notification,
                array $channels,
                AnonymousNotifiable $notifiable,
                ?string $locale,
            ) use (&$changed, $old, $accountLanguage): bool {
                if ($notifiable->routeNotificationFor('mail') !== $old) {
                    return false;
                }
                $changed = $notification;

                return $locale === $accountLanguage;
            });
            Notification::assertSentTo($user, EmailVerifiedNotification::class, fn (EmailVerifiedNotification $notification, array $channels, User $notifiable, ?string $locale): bool => $locale === $accountLanguage);

            $this->assertInstanceOf(EmailChangedNotification::class, $changed);
            $subjects = $this->withLocale($accountLanguage, fn (): array => [$change->toMail($user)->subject, $changed->toMail($user)->subject, $changed->toMail($user)->introLines[0]]);
            $this->assertSame($accountLanguage === 'en'
                ? ['Verify your new email', 'Your account email has changed', 'The login email for your Contactur account has changed to '.$new.'.']
                : ['Verificá tu nuevo email', 'El email de tu cuenta cambió', 'El email de acceso de tu cuenta Contactur cambió a '.$new.'.'], $subjects);
            $this->withHeader('Accept-Language', 'es');
        }
    }

    public function test_a_profile_notification_email_follows_the_recipient_language_not_the_actor_language(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->freezeReferenceClock('2026-11-10T12:00:00Z');
        [$companyToken, $company] = $this->actorIn('es', 'empresa@example.test', ProfileCategory::TourismCompany);
        [, $guide] = $this->actorIn('en', 'guide@example.test', ProfileCategory::Guide);
        $city = City::query()->orderBy('id')->firstOrFail();

        // La empresa opera en español; el correo real (transporte `array`) sale en inglés.
        $this->withToken($companyToken)->postJson('/api/v1/profiles/'.$company->public_id.'/contact-requests', [
            'target_profile_id' => $guide->public_id,
            'service_description' => 'Guiado bilingüe para grupo de trekking',
            'requested_from' => '2026-11-20',
            'requested_to' => '2026-11-22',
            'requested_city_id' => $city->id,
        ])->assertCreated();

        [$email] = $this->deliveredTo('guide@example.test');
        $html = (string) $email->getHtmlBody();
        $text = (string) $email->getTextBody();
        $this->assertSame('New contact request', $email->getSubject());
        $this->assertStringContainsString('<html lang="en">', $html);
        foreach ([$html, $text] as $body) {
            foreach ([
                'You received a contact request',
                'Actor empresa (Tourism company) sent you a contact request.',
                'Requested service: Guiado bilingüe para grupo de trekking',
                'Dates: from 20/11/2026 to 22/11/2026.',
                'City: '.$city->name.'.',
                'Log in to Contactur to accept or decline it.',
                'Log in to respond',
                "For security, Contactur doesn't send contact details by email",
            ] as $expected) {
                $this->assertStringContainsString($body === $html ? e($expected) : $expected, $body);
            }
            foreach (['Empresa turística', 'te envió', 'Servicio solicitado', 'Ingresar para responder'] as $spanish) {
                $this->assertStringNotContainsString($spanish, $body);
            }
        }
    }

    public function test_a_spanish_recipient_gets_spanish_when_an_english_actor_triggers_the_email(): void
    {
        Mail::fake();
        $this->freezeReferenceClock('2026-11-10T12:00:00Z');
        [$companyToken, $company, $companyOwner] = $this->actorIn('es', 'empresa@example.test', ProfileCategory::TourismCompany);
        [$guideToken, $guide] = $this->actorIn('en', 'guide@example.test', ProfileCategory::Guide);
        $requestId = $this->createRequest($companyToken, $company, $guide);

        $this->withHeader('Accept-Language', 'en');
        $this->respond($guideToken, $guide, $requestId, 'accept')->assertOk();
        $this->withHeader('Accept-Language', 'es');

        Mail::assertSent(ProfileNotificationMail::class, function (ProfileNotificationMail $mail) use ($companyOwner): bool {
            if (! $mail->hasTo($companyOwner->email)) {
                return false;
            }
            $html = $mail->render();
            $this->assertSame('es', $mail->locale);
            $this->assertSame('Solicitud aceptada', $mail->subjectLine);
            $this->assertSame('Ver la solicitud', $mail->actionLabel);
            $this->assertStringContainsString('<html lang="es">', $html);
            $this->assertStringContainsString('Actor guide (Guía) aceptó tu solicitud de contacto.', $html);
            $this->assertStringNotContainsString('accepted', $html);

            return true;
        });
        // Y a la inversa, la solicitud recibida por la guía en inglés salió con locale `en`.
        Mail::assertSent(ProfileNotificationMail::class, fn (ProfileNotificationMail $mail): bool => $mail->hasTo('guide@example.test')
            && $mail->locale === 'en'
            && $mail->subjectLine === 'New contact request');
    }

    public function test_the_pending_email_sweeper_sends_in_the_recipient_language_from_a_spanish_console(): void
    {
        $this->seed(CatalogSeeder::class);
        Mail::fake();
        $this->freezeReferenceClock('2026-11-10T12:00:00Z');
        [, $guide, $owner] = $this->actorIn('en', 'guide@example.test', ProfileCategory::Guide);
        Queue::fake();
        DB::transaction(fn (): bool => app(NotificationService::class)->record($owner, $guide, NotificationType::AdministrativeChange, [
            'change' => 'document_approved',
            'profile_id' => $guide->public_id,
            'document_type' => 'Habilitación profesional de guía',
        ], 'test:sweeper-en'));

        // El push original se perdió: el scheduler (locale por defecto, español) la reencola.
        $this->clock->set($this->clock->now()->addMinutes(11));
        $this->app->setLocale('es');
        $this->artisan('contactur:dispatch-pending-notification-emails')->expectsOutputToContain('reencolados: 1.')->assertSuccessful();
        $job = Queue::pushed(SendNotificationEmail::class)->last();
        $this->assertInstanceOf(SendNotificationEmail::class, $job);
        $job->handle(app(NotificationService::class));

        Mail::assertSent(ProfileNotificationMail::class, 1);
        Mail::assertSent(ProfileNotificationMail::class, function (ProfileNotificationMail $mail) use ($owner): bool {
            $this->assertSame('en', $mail->locale);
            $this->assertSame('Document approved', $mail->subjectLine);
            $this->assertSame(['The admin team approved the document “Professional guide licence” for your profile “Actor guide”.'], $mail->lines);
            $this->assertSame('Log in to Contactur', $mail->actionLabel);

            return $mail->hasTo($owner->email);
        });
        $this->assertSame('es', $this->app->getLocale());
    }

    public function test_the_support_inquiry_email_stays_in_spanish_for_the_support_team(): void
    {
        Mail::fake();
        config(['contactur.support.email' => 'soporte-pruebas@contactur.test']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254)]);

        $this->withHeader('Accept-Language', 'en')->postJson('/api/v1/support-inquiries', [
            'name' => 'John Example',
            'email' => 'john@example.test',
            'category' => null,
            'message' => 'I would like to know how to request access.',
            'website' => '',
        ])->assertStatus(202);

        Mail::assertSent(SupportInquiryMail::class, function (SupportInquiryMail $mail): bool {
            $html = $mail->render();
            $this->assertSame('es', $mail->locale);
            $this->assertStringContainsString('<html lang="es">', $html);
            $this->assertStringContainsString('Nueva consulta de soporte', $html);
            $this->assertStringContainsString('Sin categoría', $html);

            return $mail->hasTo('soporte-pruebas@contactur.test');
        });
    }

    /** @return array{string, Profile, User} */
    private function actorIn(string $language, string $email, ProfileCategory $category): array
    {
        $this->withHeader('Accept-Language', $language);
        try {
            return $this->actor($email, $category);
        } finally {
            $this->withHeader('Accept-Language', 'es');
        }
    }

    /** @return list<Email> correos entregados por el transporte `array`, en orden */
    private function deliveredTo(string $address): array
    {
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return $transport->messages()
            ->map(fn (SentMessage $sent) => $sent->getOriginalMessage())
            ->filter(fn ($message): bool => $message instanceof Email
                && in_array($address, array_map(fn (Address $to): string => $to->getAddress(), $message->getTo()), true))
            ->values()
            ->all();
    }

    /**
     * Pide lo mismo en español y en inglés y compara el sobre de error.
     *
     * @param  callable(): TestResponse  $request
     * @return array{0: TestResponse, 1: TestResponse}
     */
    private function assertEnvelope(callable $request, int $status, string $code, string $spanishMessage, string $englishMessage): array
    {
        $this->withHeader('Accept-Language', 'es');
        $spanish = $request()->assertStatus($status);
        $this->withHeader('Accept-Language', 'en');
        $english = $request()->assertStatus($status);
        $this->withHeader('Accept-Language', 'es');

        $this->assertSame($code, $spanish->json('error.code'));
        $this->assertSame($code, $english->json('error.code'));
        $this->assertSame($spanishMessage, $spanish->json('error.message'));
        $this->assertSame($englishMessage, $english->json('error.message'));

        return [$spanish, $english];
    }

    /** @return array{user: array<string, mixed>, access_token: string} */
    private function register(string $email, string $language = 'es'): array
    {
        return $this->withHeader('Accept-Language', $language)
            ->postJson('/api/v1/auth/register', [
                'name' => 'Cuenta de prueba',
                'email' => $email,
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
            ])
            ->assertCreated()
            ->json('data');
    }
}

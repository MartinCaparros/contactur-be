<?php

namespace Tests\Unit;

use App\Notifications\EmailVerificationNotification;
use App\Notifications\PasswordResetNotification;
use Illuminate\Notifications\Messages\MailMessage;
use stdClass;
use Tests\TestCase;

final class AuthenticationNotificationLinkTest extends TestCase
{
    public function test_email_verification_action_keeps_token_out_of_query(): void
    {
        $token = 'verification token/+?&#=%';
        $message = (new EmailVerificationNotification($token))->toMail(new stdClass);
        $url = $this->parseActionUrl($message);
        $query = $this->parseParameters($url['query'] ?? '');

        $this->assertTrue(
            count($query) === 1 && ($query['auth'] ?? null) === 'verify-email',
            'The verification action query must contain only the auth selector.',
        );
        $this->assertFalse(
            array_key_exists('token', $query) || array_key_exists('email', $query),
            'The verification action query must not contain sensitive parameters.',
        );
        $this->assertTrue(
            isset($url['fragment'])
                && hash_equals('token='.rawurlencode($token), $url['fragment']),
            'The verification action fragment must contain the correctly encoded token.',
        );
    }

    public function test_password_reset_action_keeps_token_and_email_out_of_query(): void
    {
        $token = 'reset token/+?&#=%';
        $email = 'persona+reset@example.test';
        $message = (new PasswordResetNotification($token, $email))->toMail(new stdClass);
        $url = $this->parseActionUrl($message);
        $query = $this->parseParameters($url['query'] ?? '');

        $this->assertTrue(
            count($query) === 1 && ($query['auth'] ?? null) === 'reset-password',
            'The reset action query must contain only the auth selector.',
        );
        $this->assertFalse(
            array_key_exists('token', $query) || array_key_exists('email', $query),
            'The reset action query must not contain sensitive parameters.',
        );
        $this->assertTrue(
            isset($url['fragment'])
                && hash_equals(
                    'token='.rawurlencode($token).'&email='.rawurlencode($email),
                    $url['fragment'],
                ),
            'The reset action fragment must contain correctly encoded credentials.',
        );
    }

    /** @return array<string, string> */
    private function parseActionUrl(MailMessage $message): array
    {
        $this->assertIsString($message->actionUrl, 'The notification must define an action URL.');
        $url = parse_url($message->actionUrl);
        $this->assertIsArray($url, 'The notification action URL must be valid.');
        $this->assertSame('https', $url['scheme'] ?? null);
        $this->assertSame('frontend.example.test', $url['host'] ?? null);
        $this->assertSame('/', $url['path'] ?? null);

        /** @var array<string, string> $url */
        return $url;
    }

    /** @return array<string, string> */
    private function parseParameters(string $parameters): array
    {
        parse_str($parameters, $parsed);

        /** @var array<string, string> $parsed */
        return $parsed;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('contactur.frontend_url', 'https://frontend.example.test');
    }
}

<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Service\Mailer;

/**
 * The mailer never throws into a sign-up or reset: without SMTP it logs the
 * message (so a dev can still follow the link) and reports false, and a message
 * PHPMailer refuses is a logged false too. Nothing here opens a connection.
 */
#[CoversClass(Mailer::class)]
final class MailerTest extends TestCase
{
    private const array KEYS = ['SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASS', 'SMTP_SECURE', 'MAIL_FROM', 'MAIL_FROM_NAME'];

    /** @var array<string,mixed> */
    private array $saved = [];

    private string|false $savedErrorLog = false;

    private string $log;

    protected function setUp(): void
    {
        // '' reads as unset (Env::get), whatever the developer's shell holds.
        foreach (self::KEYS as $key) {
            $this->saved[$key] = $_ENV[$key] ?? null;
            $_ENV[$key] = '';
        }

        $this->log = (string) tempnam(sys_get_temp_dir(), 'tarot-mailer-log');
        $this->savedErrorLog = ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }

        ini_set('error_log', $this->savedErrorLog === false ? '' : $this->savedErrorLog);
        @unlink($this->log);
    }

    public function testWithoutSmtpTheActivationEmailIsLoggedWithItsLinkInsteadOfSent(): void
    {
        $mailer = new Mailer();
        $this->assertFalse($mailer->isConfigured());

        $this->assertFalse($mailer->sendActivation('reader@example.test', 'Reader', 'https://tarotgen.test/activate?token=abc123'));

        $logged = (string) file_get_contents($this->log);
        $this->assertStringContainsString('SMTP not configured; would send "Activate your TarotGen.io account" to reader@example.test', $logged);
        $this->assertStringContainsString('https://tarotgen.test/activate?token=abc123', $logged);
    }

    public function testWithoutSmtpTheResetEmailIsLoggedWithItsLinkInsteadOfSent(): void
    {
        $this->assertFalse(new Mailer()->sendPasswordReset('reader@example.test', 'Reader', 'https://tarotgen.test/reset-password?token=def456'));

        $logged = (string) file_get_contents($this->log);
        $this->assertStringContainsString('would send "Reset your TarotGen.io password" to reader@example.test', $logged);
        $this->assertStringContainsString('https://tarotgen.test/reset-password?token=def456', $logged);
    }

    public function testWithoutSmtpTheSignUpAttemptNoticeIsLoggedWithItsLinksInsteadOfSent(): void
    {
        $this->assertFalse(new Mailer()->sendRegistrationAttempt(
            'reader@example.test',
            'Reader',
            'https://tarotgen.test/login',
            'https://tarotgen.test/forgot-password'
        ));

        $logged = (string) file_get_contents($this->log);
        $this->assertStringContainsString('would send "Someone tried to sign up with your email on TarotGen.io" to reader@example.test', $logged);
        $this->assertStringContainsString('https://tarotgen.test/login', $logged);
        $this->assertStringContainsString('https://tarotgen.test/forgot-password', $logged);
    }

    public function testWithoutSmtpTheAdminTestEmailReportsThatNothingWasSent(): void
    {
        $this->assertFalse(new Mailer()->sendTest('admin@example.test'));

        $this->assertStringContainsString('would send "TarotGen.io SMTP test" to admin@example.test', (string) file_get_contents($this->log));
    }

    public function testAMessageRefusedBeforeSendingIsAFailureNotAnException(): void
    {
        // Configured, but each message fails PHPMailer's address checks, which
        // come before it would connect to the host.
        $_ENV['SMTP_HOST'] = 'smtp.invalid';
        $_ENV['SMTP_USER'] = 'mailer@example.test';
        $_ENV['SMTP_SECURE'] = 'ssl';
        $mailer = new Mailer();
        $this->assertTrue($mailer->isConfigured());

        $this->assertFalse($mailer->sendActivation('not-an-address', 'Reader', 'https://tarotgen.test/activate?token=abc123'));

        $_ENV['MAIL_FROM'] = 'not-an-address-either';
        $this->assertFalse($mailer->sendPasswordReset('reader@example.test', 'Reader', 'https://tarotgen.test/reset-password?token=def456'));

        $logged = (string) file_get_contents($this->log);
        $this->assertSame(2, substr_count($logged, '[Mailer] send failed: '));
        $this->assertStringContainsString('not-an-address', $logged);
    }
}

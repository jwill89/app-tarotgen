<?php

namespace Tarot\Service;

use PHPMailer\PHPMailer\PHPMailer;
use Throwable;
use Tarot\Config\Env;

/**
 * Thin wrapper around PHPMailer for transactional email (account activation,
 * password resets, a notice of a sign-up attempt on an existing address).
 *
 * Configuration comes from the environment (SMTP_HOST, SMTP_PORT, SMTP_USER,
 * SMTP_PASS, SMTP_SECURE, MAIL_FROM, MAIL_FROM_NAME). When SMTP isn't
 * configured — e.g. local dev — sending degrades gracefully: the message is
 * written to the PHP error log instead of thrown, so flows keep working and the
 * activation link is still recoverable.
 */
class Mailer
{
    public function isConfigured(): bool
    {
        return Env::get('SMTP_HOST') !== null
            && class_exists(PHPMailer::class);
    }

    /**
     * Send an account-activation email. Returns true only when actually sent
     * via SMTP; false when unconfigured or on failure (both are logged).
     */
    public function sendActivation(string $toEmail, string $toName, string $activationLink): bool
    {
        $subject = 'Activate your TarotGen.io account';

        $textBody = "Welcome to TarotGen.io!\n\n"
            . "Activate your account by opening this link:\n{$activationLink}\n\n"
            . "This link expires in 24 hours. If you didn't create an account, you can ignore this email.";

        $safeLink = htmlspecialchars($activationLink, ENT_QUOTES, 'UTF-8');
        $htmlBody = '<p>Welcome to <strong>TarotGen.io</strong>!</p>'
            . '<p>Activate your account by clicking the link below:</p>'
            . '<p><a href="' . $safeLink . '">Activate my account</a></p>'
            . '<p style="color:#666;font-size:13px">This link expires in 24 hours. '
            . "If you didn't create an account, you can ignore this email.</p>";

        return $this->send($toEmail, $toName, $subject, $htmlBody, $textBody);
    }

    /**
     * Send a password-reset email. Returns true only when actually sent via
     * SMTP; false when unconfigured or on failure (both are logged).
     */
    public function sendPasswordReset(string $toEmail, string $toName, string $resetLink): bool
    {
        $subject = 'Reset your TarotGen.io password';

        $textBody = "We received a request to reset your TarotGen.io password.\n\n"
            . "Reset it using this link:\n{$resetLink}\n\n"
            . "This link expires in 1 hour. If you didn't request this, you can ignore this email "
            . "— your password won't change.";

        $safeLink = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');
        $htmlBody = '<p>We received a request to reset your <strong>TarotGen.io</strong> password.</p>'
            . '<p><a href="' . $safeLink . '">Reset my password</a></p>'
            . '<p style="color:#666;font-size:13px">This link expires in 1 hour. '
            . "If you didn't request this, you can ignore this email — your password won't change.</p>";

        return $this->send($toEmail, $toName, $subject, $htmlBody, $textBody);
    }

    /**
     * Tell the owner of an address that someone tried to sign up with it. Sign-up
     * answers that attempt exactly like a new account (so it can't be used to
     * find out who has one); this is how the real owner hears about it. Returns
     * true only when actually sent via SMTP.
     */
    public function sendRegistrationAttempt(string $toEmail, string $toName, string $signInLink, string $resetLink): bool
    {
        $subject = 'Someone tried to sign up with your email on TarotGen.io';

        $textBody = "Someone just tried to create a TarotGen.io account with this email address, "
            . "but you already have one.\n\n"
            . "If it was you, sign in here:\n{$signInLink}\n\n"
            . "Forgotten your password? Reset it here:\n{$resetLink}\n\n"
            . "If it wasn't you, you can ignore this email. Nothing about your account has changed.";

        $safeSignIn = htmlspecialchars($signInLink, ENT_QUOTES, 'UTF-8');
        $safeReset  = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');
        $htmlBody = '<p>Someone just tried to create a <strong>TarotGen.io</strong> account with this email '
            . 'address, but you already have one.</p>'
            . '<p>If it was you, <a href="' . $safeSignIn . '">sign in</a>, or '
            . '<a href="' . $safeReset . '">reset your password</a> if you have forgotten it.</p>'
            . '<p style="color:#666;font-size:13px">If it wasn\'t you, you can ignore this email. '
            . 'Nothing about your account has changed.</p>';

        return $this->send($toEmail, $toName, $subject, $htmlBody, $textBody);
    }

    /** Send a simple diagnostic email to verify SMTP configuration. */
    public function sendTest(string $toEmail): bool
    {
        $subject = 'TarotGen.io SMTP test';
        $text = "This is a test message confirming your TarotGen.io SMTP settings are working.";
        $html = '<p>This is a test message confirming your <strong>TarotGen.io</strong> SMTP settings are working. '
            . 'If you can read this, account-activation emails will send.</p>';

        return $this->send($toEmail, 'TarotGen.io Test', $subject, $html, $text);
    }

    private function send(string $toEmail, string $toName, string $subject, string $html, string $text): bool
    {
        if (!$this->isConfigured()) {
            error_log("[Mailer] SMTP not configured; would send \"{$subject}\" to {$toEmail}. Body: {$text}");
            return false;
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();

            // Some mail goes out inline during the request (an admin's resend, and
            // every email where a response can't be finished first: AfterResponse),
            // so PHPMailer's 300s default outlives Cloudflare's 100s origin timeout:
            // a stalled SMTP connect surfaces to the caller as a 524 rather than a
            // handled failure. Cap it low enough that we always get to return a
            // real response.
            $mail->Timeout    = 10;

            $mail->Host       = (string) Env::get('SMTP_HOST');
            $mail->Port       = (int) Env::get('SMTP_PORT', '587');
            $mail->SMTPAuth   = Env::get('SMTP_USER') !== null;

            if ($mail->SMTPAuth) {
                $mail->Username = (string) Env::get('SMTP_USER');
                $mail->Password = Env::get('SMTP_PASS', '');
            }

            // 'tls' (STARTTLS, port 587) or 'ssl' (implicit TLS, port 465).
            $secure = strtolower(Env::get('SMTP_SECURE', 'tls'));
            if ($secure === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($secure === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $fromEmail = Env::get('MAIL_FROM', Env::get('SMTP_USER', 'noreply@tarotgen.io'));
            $fromName  = Env::get('MAIL_FROM_NAME', 'TarotGen.io');

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($toEmail, $toName);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = $text;

            $mail->send();
            return true;
        } catch (Throwable $e) {
            error_log('[Mailer] send failed: ' . $e->getMessage());
            return false;
        }
    }
}

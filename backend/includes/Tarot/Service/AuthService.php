<?php

namespace Tarot\Service;

use Tarot\Config\Env;
use Tarot\Repository\UserRepository;
use Tarot\Repository\UserTokenRepository;
use Tarot\Structure\User;
use Tarot\Utility\AfterResponse;
use Tarot\Utility\Clock;
use Tarot\Utility\DbSessionHandler;

/**
 * Account lifecycle: registration (+ activation token/email), activation,
 * password login, password change and reset, and deletion. Keeps all the rules
 * in one place so the controller stays a thin HTTP adapter.
 */
class AuthService
{
    public const string TOKEN_ACTIVATION = 'activation';
    public const string TOKEN_PASSWORD_RESET = 'password_reset';

    /** Activation links are valid for 24 hours. */
    private const int ACTIVATION_TTL = 86400;

    /** Password-reset links are valid for 1 hour (shorter, higher-stakes). */
    private const int RESET_TTL = 3600;

    private const int DISPLAY_NAME_MIN = 3;
    private const int DISPLAY_NAME_MAX = 30;

    /**
     * What a sign-in with no password to check is checked against instead (an
     * unknown email, or an account made through Google), by hashing algorithm:
     * hashes of random text, made with that algorithm's current default cost so
     * the check takes as long as a real one. (Keyed by the algorithms' names,
     * not PASSWORD_ARGON2ID, which a PHP built without Argon2 doesn't define.)
     */
    private const array STAND_IN_HASHES = [
        'argon2id' => '$argon2id$v=19$m=65536,t=4,p=1$bWJ1b3BsUEMzZElHZnFRNA$X9aLMZUcpqn+yu2PUwPZxBtVCxQlUam7vwtGBSBVhnc',
        '2y'       => '$2y$12$rCTmNfTMGa3duqqLowPcCej7Fwg5UCEI8TA4gmnyQ1h5ugtk0W19e',
    ];

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserTokenRepository $tokens,
        private readonly Mailer $mailer,
        private readonly DbSessionHandler $sessions,
        private readonly AccountProtection $protection,
        private readonly AfterResponse $afterResponse,
    ) {}

    /**
     * The password hashing algorithm: Argon2id when the PHP build supports it
     * (preferred), otherwise the platform default (bcrypt).
     */
    public static function passwordAlgo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    /**
     * The hash a sign-in with no password behind it is checked against, for the
     * algorithm passwordAlgo() picks: made at that algorithm's current default
     * cost, so checking it takes as long as checking a fresh password's hash.
     */
    public static function standInHash(): string
    {
        return self::STAND_IN_HASHES[self::passwordAlgo()] ?? self::STAND_IN_HASHES['2y'];
    }

    /**
     * Register a new account. On success a user is created (inactive) and an
     * activation email is sent.
     *
     * An email that already has an account succeeds too, as far as the caller
     * can tell: nothing is created, and the address's owner is emailed that
     * someone tried (so sign-up can't be used to find out who has an account).
     * Only a new account comes back with `user` and `activation_link`. A taken
     * display name is still an error: names are public anyway.
     *
     * @return array{
     *   ok: bool,
     *   errors?: string[],
     *   user?: User,
     *   emailed?: bool,
     *   activation_link?: string
     * }
     */
    public function register(string $email, string $displayName, #[\SensitiveParameter] string $password): array
    {
        $email       = strtolower(trim($email));
        $displayName = trim($displayName);

        $errors = $this->validateRegistration($email, $displayName, $password);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        // Uniqueness (the columns are COLLATE NOCASE, so case-insensitive).
        if ($this->users->displayNameExists($displayName)) {
            return ['ok' => false, 'errors' => ['That display name is already taken.']];
        }

        // Hashed whether or not the account gets made, so a taken email takes as
        // long to answer as a new one.
        $hash = password_hash($password, self::passwordAlgo());

        $existing = $this->users->findByEmail($email);
        if ($existing !== null) {
            $base    = rtrim(Env::get('APP_URL', 'https://tarotgen.io'), '/');
            $emailed = $this->sendAfterResponse(fn(): bool => $this->mailer->sendRegistrationAttempt(
                $existing->email,
                $existing->display_name,
                $base . '/login',
                $base . '/forgot-password'
            ));

            return ['ok' => true, 'emailed' => $emailed];
        }

        $user = $this->users->create($email, $displayName, $hash);

        if ($user === null) {
            return ['ok' => false, 'errors' => ['Could not create the account. Please try again.']];
        }

        $link    = $this->issueActivationLink($user->user_id);
        $emailed = $this->sendAfterResponse(fn(): bool => $this->mailer->sendActivation($user->email, $user->display_name, $link));

        return [
            'ok'              => true,
            'user'            => $user,
            'emailed'         => $emailed,
            'activation_link' => $link,
        ];
    }

    /**
     * Activate an account from a raw token. Returns true when a matching,
     * unused, unexpired activation token was found and consumed.
     */
    public function activate(#[\SensitiveParameter] string $rawToken): bool
    {
        $rawToken = trim($rawToken);
        if ($rawToken === '') {
            return false;
        }

        $token = $this->tokens->findValid(self::hashToken($rawToken), self::TOKEN_ACTIVATION);
        if ($token === null) {
            return false;
        }

        $this->users->activate($token->user_id);
        $this->tokens->markUsed($token->token_id);

        return true;
    }

    /**
     * Re-issue and (re)send an activation email for an existing, not-yet-active
     * account. Used by the admin user-management screen.
     *
     * @return array{ok:bool, error?:string, emailed?:bool, activation_link?:string}
     */
    public function resendActivation(int $userId): array
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            return ['ok' => false, 'error' => 'User not found.'];
        }
        if ($user->is_active) {
            return ['ok' => false, 'error' => 'That account is already active.'];
        }

        $link    = $this->issueActivationLink($userId);
        $emailed = $this->mailer->sendActivation($user->email, $user->display_name, $link);

        return ['ok' => true, 'emailed' => $emailed, 'activation_link' => $link];
    }

    /**
     * Verify credentials. On success updates last_login and transparently
     * rehashes the password if the algorithm/params have changed.
     *
     * @return array{status:'ok'|'invalid'|'inactive'|'password_disabled', user?:User}
     */
    public function authenticate(string $email, #[\SensitiveParameter] string $password): array
    {
        $email = strtolower(trim($email));
        $auth  = $this->users->findAuthByEmail($email);

        if ($auth === null || !$auth->hasPassword()) {
            // No account, or one made through Google with no password yet. Spend
            // the time a password check takes anyway, so the answer's timing
            // doesn't say which emails have accounts.
            password_verify($password, self::standInHash());
            return ['status' => 'invalid'];
        }

        if (!$auth->verify($password)) {
            return ['status' => 'invalid'];
        }

        if (!$auth->is_active) {
            return ['status' => 'inactive'];
        }

        $userId = $auth->user_id;

        // Check if password login has been disabled for this account.
        if ($this->users->isPasswordLoginDisabled($userId)) {
            return ['status' => 'password_disabled'];
        }

        if ($auth->needsRehash(self::passwordAlgo())) {
            $this->users->updatePasswordHash($userId, password_hash($password, self::passwordAlgo()));
        }

        $this->users->touchLogin($userId);

        $user = $this->users->findById($userId);
        if ($user === null) {
            // The row was verified moments ago; a null here means it vanished
            // mid-request (e.g. concurrent deletion). Treat as a failed login.
            return ['status' => 'invalid'];
        }

        return ['status' => 'ok', 'user' => $user];
    }

    /**
     * Begin a password reset: if the email belongs to an account, issue a reset
     * token and email the link. The caller responds identically whether or not
     * the account exists (no account enumeration); `exists` is for internal/dev
     * use only.
     *
     * @return array{ok:bool, exists:bool, emailed?:bool, reset_link?:string}
     */
    public function requestPasswordReset(string $email): array
    {
        $email = strtolower(trim($email));
        $user  = $this->users->findByEmail($email);

        if ($user === null) {
            return ['ok' => true, 'exists' => false];
        }

        $link    = $this->issueResetLink($user->user_id);
        $emailed = $this->sendAfterResponse(fn(): bool => $this->mailer->sendPasswordReset($user->email, $user->display_name, $link));

        return ['ok' => true, 'exists' => true, 'emailed' => $emailed, 'reset_link' => $link];
    }

    /**
     * Complete a password reset from a raw token. The new password must satisfy
     * the policy; a weak password is reported without consuming the token so the
     * user can retry with the same link. On success the password is updated, the
     * token consumed, every session of the account ended (whoever knew the old
     * password is signed out; linked plugins keep their tokens), and — since
     * clicking the emailed link proves control of the inbox — the account is
     * also marked active.
     *
     * @return array{status:'ok'|'invalid'|'weak', error?:string}
     */
    public function resetPassword(
        #[\SensitiveParameter]
        string $rawToken,
        #[\SensitiveParameter]
        string $newPassword,
    ): array {
        $rawToken = trim($rawToken);
        if ($rawToken === '') {
            return ['status' => 'invalid'];
        }

        $token = $this->tokens->findValid(self::hashToken($rawToken), self::TOKEN_PASSWORD_RESET);
        if ($token === null) {
            return ['status' => 'invalid'];
        }

        $pwError = PasswordPolicy::validate($newPassword);
        if ($pwError !== null) {
            return ['status' => 'weak', 'error' => $pwError];
        }

        $userId = $token->user_id;
        $this->users->updatePasswordHash($userId, password_hash($newPassword, self::passwordAlgo()));
        $this->tokens->markUsed($token->token_id);
        $this->users->activate($userId);
        $this->sessions->destroyForUser($userId);

        return ['status' => 'ok'];
    }

    /**
     * Change a user's display name (enforcing the same rules and case-insensitive
     * uniqueness as registration).
     *
     * @return array{ok:bool, error?:string}
     */
    public function changeDisplayName(int $userId, string $displayName): array
    {
        $displayName = trim($displayName);

        $error = $this->validateDisplayName($displayName);
        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }

        $current = $this->users->findById($userId);
        if ($current === null) {
            return ['ok' => false, 'error' => 'Account not found.'];
        }

        // Allow keeping the same name (e.g. a capitalisation change); otherwise
        // the name must be free.
        if (
            mb_strtolower($displayName) !== mb_strtolower($current->display_name)
            && $this->users->displayNameExists($displayName)
        ) {
            return ['ok' => false, 'error' => 'That display name is already taken.'];
        }

        $this->users->updateDisplayName($userId, $displayName);
        return ['ok' => true];
    }

    /**
     * Change a user's password after verifying their current one. Every other
     * session of the account ends; the one whose id is $keepSessionId (the
     * browser making the change) stays. Linked plugins keep their tokens.
     *
     * @return array{ok:bool, error?:string}
     */
    public function changePassword(
        int $userId,
        #[\SensitiveParameter]
        string $currentPassword,
        #[\SensitiveParameter]
        string $newPassword,
        ?string $keepSessionId = null,
    ): array {
        $hash = $this->users->getPasswordHash($userId);
        if ($hash === null || !password_verify($currentPassword, $hash)) {
            return ['ok' => false, 'error' => 'Your current password is incorrect.'];
        }

        $pwError = PasswordPolicy::validate($newPassword);
        if ($pwError !== null) {
            return ['ok' => false, 'error' => $pwError];
        }

        $this->users->updatePasswordHash($userId, password_hash($newPassword, self::passwordAlgo()));
        $this->sessions->destroyForUser($userId, $keepSessionId);
        return ['ok' => true];
    }

    /**
     * Sign the account out everywhere but the browser asking (the session whose id
     * is $keepSessionId). Returns how many sessions that ended. Linked plugins keep
     * their tokens: they're revoked one by one under Connected Apps.
     */
    public function endOtherSessions(int $userId, ?string $keepSessionId): int
    {
        return $this->sessions->destroyForUser($userId, $keepSessionId);
    }

    /**
     * Delete a user's own account after verifying their password — unless it's
     * a protected account (AccountProtection), which can't be deleted.
     *
     * @return array{ok:bool, error?:string}
     */
    public function deleteAccount(int $userId, #[\SensitiveParameter] string $password): array
    {
        $user = $this->users->findById($userId);
        $refusal = $user === null ? null : $this->protection->refuseSelfDeletion($user);
        if ($refusal !== null) {
            return ['ok' => false, 'error' => $refusal];
        }

        $hash = $this->users->getPasswordHash($userId);
        if ($hash === null || !password_verify($password, $hash)) {
            return ['ok' => false, 'error' => 'Password is incorrect.'];
        }

        $this->removeAccount($userId);
        return ['ok' => true];
    }

    /**
     * Delete an account and end its sessions. Its readings, spreads, passkeys
     * and plugin tokens go with it by the ON DELETE CASCADE foreign keys; the
     * sessions table has none (a request still in flight must be able to save
     * its session), so they're ended here.
     */
    public function removeAccount(int $userId): void
    {
        $this->users->delete($userId);
        $this->sessions->destroyForUser($userId);
    }

    /**
     * Create (and email) a fresh activation token for a user, replacing any
     * outstanding ones. Returns the activation link.
     */
    private function issueActivationLink(int $userId): string
    {
        $this->tokens->deleteForUserType($userId, self::TOKEN_ACTIVATION);

        $raw     = bin2hex(random_bytes(32));
        $expires = Clock::in(self::ACTIVATION_TTL);
        $this->tokens->store($userId, self::TOKEN_ACTIVATION, self::hashToken($raw), $expires);

        $base = rtrim(Env::get('APP_URL', 'https://tarotgen.io'), '/');
        return $base . '/activate?token=' . $raw;
    }

    /**
     * Create a fresh password-reset token for a user, replacing any outstanding
     * ones. Returns the reset link.
     */
    private function issueResetLink(int $userId): string
    {
        $this->tokens->deleteForUserType($userId, self::TOKEN_PASSWORD_RESET);

        $raw     = bin2hex(random_bytes(32));
        $expires = Clock::in(self::RESET_TTL);
        $this->tokens->store($userId, self::TOKEN_PASSWORD_RESET, self::hashToken($raw), $expires);

        $base = rtrim(Env::get('APP_URL', 'https://tarotgen.io'), '/');
        return $base . '/reset-password?token=' . $raw;
    }

    /**
     * Send a public account email (sign-up, password reset) after the response,
     * where the server can (PHP-FPM, {@see AfterResponse}). Waiting on SMTP only
     * when an address has an account would make those answers slower for it,
     * which would tell anyone timing them who has one. Returns whether the email
     * was sent; one handed on counts as sent, and its failure is logged as any
     * is. Unset SMTP is answered at once: false, so outside production the link
     * is shown.
     *
     * @param callable(): bool $send
     */
    private function sendAfterResponse(callable $send): bool
    {
        if (!$this->afterResponse->isAvailable() || !$this->mailer->isConfigured()) {
            return $send();
        }

        $this->afterResponse->defer($send);

        return true;
    }

    private static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /** @return string[] */
    private function validateRegistration(string $email, string $displayName, string $password): array
    {
        $errors = [];

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Please enter a valid email address.';
        }

        $nameError = $this->validateDisplayName($displayName);
        if ($nameError !== null) {
            $errors[] = $nameError;
        }

        $pwError = PasswordPolicy::validate($password);
        if ($pwError !== null) {
            $errors[] = $pwError;
        }

        return $errors;
    }

    /** @return string|null  Null when valid, otherwise the reason it was rejected. */
    private function validateDisplayName(string $displayName): ?string
    {
        $len = mb_strlen($displayName);
        if ($len < self::DISPLAY_NAME_MIN || $len > self::DISPLAY_NAME_MAX) {
            return 'Display name must be between ' . self::DISPLAY_NAME_MIN
                . ' and ' . self::DISPLAY_NAME_MAX . ' characters.';
        }
        if (preg_match('/^[\p{L}\p{N} ._\-]+$/u', $displayName) !== 1) {
            return 'Display name may only contain letters, numbers, spaces, and . _ -';
        }
        return null;
    }
}

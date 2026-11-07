<?php

namespace Tarot\Service;

use Tarot\Repository\PasskeyRepository;
use Tarot\Repository\UserRepository;

/**
 * An account always keeps at least one way to sign in: a password (one is set
 * and password login is on), a passkey, or a linked Google account. Turning
 * password login off, deleting a passkey and unlinking Google each ask here
 * first; the answer is null when the change may go ahead, or what to do before
 * it can.
 */
final readonly class SignInMethods
{
    private const string PASSWORD = 'password';
    private const string PASSKEY = 'passkey';
    private const string GOOGLE = 'google';

    public function __construct(
        private UserRepository $users,
        private PasskeyRepository $passkeys,
    ) {}

    public function refuseDisablingPasswordLogin(int $userId): ?string
    {
        return $this->refusal($userId, self::PASSWORD);
    }

    /** Asked before deleting one of the account's own passkeys. */
    public function refuseDeletingPasskey(int $userId): ?string
    {
        return $this->refusal($userId, self::PASSKEY);
    }

    public function refuseUnlinkingGoogle(int $userId): ?string
    {
        return $this->refusal($userId, self::GOOGLE);
    }

    /** The one rule, for losing one $method (one passkey of possibly several). */
    private function refusal(int $userId, string $method): ?string
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            // Deleted since the request was let in: nothing left to protect.
            return null;
        }

        $passwordWorks = $user->has_password && !$user->password_login_disabled;
        $passkeysLeft = $this->passkeys->countByUser($userId) - ($method === self::PASSKEY ? 1 : 0);

        $left = ($method !== self::PASSWORD && $passwordWorks)
            || $passkeysLeft > 0
            || ($method !== self::GOOGLE && $user->google_linked);
        if ($left) {
            return null;
        }

        // Everything that would give the account another way in, in the words
        // of the settings page.
        $fixes = [];
        if ($method !== self::PASSWORD) {
            $fixes[] = $user->has_password ? 'turn password login back on' : 'set a password';
        }
        if ($method !== self::PASSKEY) {
            $fixes[] = 'add a passkey';
        }
        if ($method !== self::GOOGLE) {
            $fixes[] = 'link your Google account';
        }

        return "That would leave you no way to sign in. First " . implode(' or ', $fixes) . '.';
    }
}

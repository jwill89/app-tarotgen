<?php

namespace Tarot\Data;

use Tarot\Structure\User;
use Tarot\Structure\UserCredentials;
use Tarot\Utility\Clock;

class UserData extends AbstractData
{
    /**
     * Exactly the properties User declares. Notably not password_hash: only
     * findAuthByEmail() and getPasswordHash() ever select it. The three flags are
     * worked out here so the Google id and the hash never leave SQL and listing
     * every user is one query, not one passkey lookup per user.
     */
    private const string SELECT_COLS = 'user_id, email, display_name, is_active, is_admin, registered_at, '
        . "last_login_at, COALESCE(google_id, '') <> '' AS google_linked, password_hash <> '' AS has_password, "
        . 'password_login_disabled, '
        . 'EXISTS (SELECT 1 FROM user_passkeys p WHERE p.user_id = users.user_id) AS has_passkeys';

    /**
     * Every account, newest first — for the admin user-management screen.
     *
     * @return list<User>
     */
    public function getAll(): array
    {
        return $this->fetchAllAs(
            'SELECT ' . self::SELECT_COLS . ' FROM users ORDER BY registered_at DESC, user_id DESC',
            [],
            User::class
        );
    }

    public function findById(int $userId): ?User
    {
        return $this->fetchOne('SELECT ' . self::SELECT_COLS . ' FROM users WHERE user_id = :id', [':id' => $userId], User::class);
    }

    public function findByEmail(string $email): ?User
    {
        // The email column is COLLATE NOCASE, so this match is case-insensitive.
        return $this->fetchOne('SELECT ' . self::SELECT_COLS . ' FROM users WHERE email = :email', [':email' => $email], User::class);
    }

    /**
     * The password sign-in check for an email. The hash goes into
     * UserCredentials, where it stays private, never onto the User.
     */
    public function findAuthByEmail(string $email): ?UserCredentials
    {
        return $this->fetchOne(
            'SELECT user_id, password_hash, is_active FROM users WHERE email = :email',
            [':email' => $email],
            UserCredentials::class
        );
    }

    public function emailExists(string $email): bool
    {
        return $this->exists('SELECT 1 FROM users WHERE email = :email', [':email' => $email]);
    }

    public function displayNameExists(string $displayName): bool
    {
        return $this->exists('SELECT 1 FROM users WHERE display_name = :name', [':name' => $displayName]);
    }

    public function create(string $email, string $displayName, string $passwordHash): ?User
    {
        $sql = 'INSERT INTO users (email, password_hash, display_name, is_active, is_admin, registered_at)
                VALUES (:email, :hash, :name, 0, 0, :now)';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':email' => $email,
            ':hash'  => $passwordHash,
            ':name'  => $displayName,
            ':now'   => Clock::now(),
        ]);

        return $this->findById((int) $this->db->lastInsertId());
    }

    public function activate(int $userId): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET is_active = 1 WHERE user_id = :id');
        return $stmt->execute([':id' => $userId]);
    }

    public function setAdmin(int $userId, bool $isAdmin): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET is_admin = :admin WHERE user_id = :id');
        return $stmt->execute([':admin' => $isAdmin ? 1 : 0, ':id' => $userId]);
    }

    public function touchLogin(int $userId): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET last_login_at = :now WHERE user_id = :id');
        return $stmt->execute([':now' => Clock::now(), ':id' => $userId]);
    }

    public function updatePasswordHash(int $userId, string $passwordHash): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET password_hash = :hash WHERE user_id = :id');
        return $stmt->execute([':hash' => $passwordHash, ':id' => $userId]);
    }

    public function updateDisplayName(int $userId, string $displayName): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET display_name = :name WHERE user_id = :id');
        return $stmt->execute([':name' => $displayName, ':id' => $userId]);
    }

    /** The stored password hash for a user, for credential re-verification. */
    public function getPasswordHash(int $userId): ?string
    {
        $stmt = $this->db->prepare('SELECT password_hash FROM users WHERE user_id = :id');
        $stmt->execute([':id' => $userId]);
        $hash = $stmt->fetchColumn();

        return is_string($hash) ? $hash : null;
    }

    public function delete(int $userId): bool
    {
        // user_tokens rows cascade via the FK (foreign_keys pragma is ON).
        $stmt = $this->db->prepare('DELETE FROM users WHERE user_id = :id');
        return $stmt->execute([':id' => $userId]);
    }

    // ── Google OAuth ─────────────────────────────────────────────

    public function findByGoogleId(string $googleId): ?User
    {
        return $this->fetchOne('SELECT ' . self::SELECT_COLS . ' FROM users WHERE google_id = :gid', [':gid' => $googleId], User::class);
    }

    public function setGoogleId(int $userId, ?string $googleId): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET google_id = :gid WHERE user_id = :id');
        return $stmt->execute([':gid' => $googleId, ':id' => $userId]);
    }

    public function googleIdExists(string $googleId): bool
    {
        return $this->exists('SELECT 1 FROM users WHERE google_id = :gid', [':gid' => $googleId]);
    }

    /**
     * Create a user account from Google OAuth (no password, already active).
     */
    public function createFromGoogle(string $email, string $displayName, string $googleId): ?User
    {
        $sql = 'INSERT INTO users (email, password_hash, display_name, is_active, is_admin, registered_at, google_id)
                VALUES (:email, :hash, :name, 1, 0, :now, :gid)';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':email' => $email,
            ':hash'  => '', // No password — Google-only accounts log in via OAuth.
            ':name'  => $displayName,
            ':now'   => Clock::now(),
            ':gid'   => $googleId,
        ]);

        return $this->findById((int) $this->db->lastInsertId());
    }

    /**
     * Whether the query matches any row.
     *
     * @param array<array-key,mixed> $params
     */
    private function exists(string $sql, array $params): bool
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    // ── Password Login Toggle ────────────────────────────────────────

    public function setPasswordLoginDisabled(int $userId, bool $disabled): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET password_login_disabled = :val WHERE user_id = :id');
        return $stmt->execute([':val' => $disabled ? 1 : 0, ':id' => $userId]);
    }

    public function isPasswordLoginDisabled(int $userId): bool
    {
        $stmt = $this->db->prepare('SELECT password_login_disabled FROM users WHERE user_id = :id');
        $stmt->execute([':id' => $userId]);
        $val = $stmt->fetchColumn();
        return (bool) (int) $val;
    }
}

<?php

namespace Tarot\Repository;

use Tarot\Data\UserData;
use Tarot\Structure\User;
use Tarot\Structure\UserCredentials;

readonly class UserRepository
{
    public function __construct(private UserData $data) {}

    /** @return User[] */
    public function getAll(): array
    {
        return $this->data->getAll();
    }

    public function findById(int $userId): ?User
    {
        return $this->data->findById($userId);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->data->findByEmail($email);
    }

    public function findAuthByEmail(string $email): ?UserCredentials
    {
        return $this->data->findAuthByEmail($email);
    }

    public function emailExists(string $email): bool
    {
        return $this->data->emailExists($email);
    }

    public function displayNameExists(string $displayName): bool
    {
        return $this->data->displayNameExists($displayName);
    }

    public function create(string $email, string $displayName, string $passwordHash): ?User
    {
        return $this->data->create($email, $displayName, $passwordHash);
    }

    public function activate(int $userId): bool
    {
        return $this->data->activate($userId);
    }

    public function setAdmin(int $userId, bool $isAdmin): bool
    {
        return $this->data->setAdmin($userId, $isAdmin);
    }

    public function touchLogin(int $userId): bool
    {
        return $this->data->touchLogin($userId);
    }

    public function updatePasswordHash(int $userId, string $passwordHash): bool
    {
        return $this->data->updatePasswordHash($userId, $passwordHash);
    }

    public function updateDisplayName(int $userId, string $displayName): bool
    {
        return $this->data->updateDisplayName($userId, $displayName);
    }

    public function getPasswordHash(int $userId): ?string
    {
        return $this->data->getPasswordHash($userId);
    }

    public function delete(int $userId): bool
    {
        return $this->data->delete($userId);
    }

    // ── Google OAuth ─────────────────────────────────────────────

    public function findByGoogleId(string $googleId): ?User
    {
        return $this->data->findByGoogleId($googleId);
    }

    public function setGoogleId(int $userId, ?string $googleId): bool
    {
        return $this->data->setGoogleId($userId, $googleId);
    }

    public function googleIdExists(string $googleId): bool
    {
        return $this->data->googleIdExists($googleId);
    }

    public function createFromGoogle(string $email, string $displayName, string $googleId): ?User
    {
        return $this->data->createFromGoogle($email, $displayName, $googleId);
    }

    // ── Password Login Toggle ────────────────────────────────────────

    public function setPasswordLoginDisabled(int $userId, bool $disabled): bool
    {
        return $this->data->setPasswordLoginDisabled($userId, $disabled);
    }

    public function isPasswordLoginDisabled(int $userId): bool
    {
        return $this->data->isPasswordLoginDisabled($userId);
    }
}

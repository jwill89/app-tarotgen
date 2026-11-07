<?php

namespace Tarot\Repository;

use Tarot\Data\ContactData;
use Tarot\Structure\Contact;

readonly class ContactRepository
{
    public function __construct(private ContactData $data) {}

    /**
     * @return Contact[]
     */
    public function get(?bool $unreadOnly = null): array
    {
        return $this->data->retrieve($unreadOnly);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): ?Contact
    {
        return $this->data->store($data);
    }

    public function exists(int $contact_id): bool
    {
        return $this->data->exists($contact_id);
    }

    public function markRead(int $contact_id, bool $read = true): bool
    {
        return $this->data->markRead($contact_id, $read);
    }

    public function countUnread(): int
    {
        return $this->data->countUnread();
    }
}

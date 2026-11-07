<?php

namespace Tarot\Data;

use Tarot\Structure\Contact;

class ContactData extends AbstractData
{
    /**
     * A contact as the admin sees it: when the sender was signed in, their
     * current display name and email from users, otherwise what they typed.
     */
    private const string SELECT = 'SELECT c.contact_id, c.user_id,
                COALESCE(u.display_name, c.name) AS name,
                COALESCE(u.email, c.email) AS email,
                c.message, c.is_read, c.submitted_at
         FROM contacts c
         LEFT JOIN users u ON c.user_id = u.user_id';

    /**
     * Retrieve contacts, newest first, optionally only the unread ones.
     *
     * @return list<Contact>
     */
    public function retrieve(?bool $unreadOnly = null): array
    {
        $where = $unreadOnly === true ? ' WHERE c.is_read = 0' : '';

        return $this->fetchAllAs(self::SELECT . $where . ' ORDER BY c.submitted_at DESC', [], Contact::class);
    }

    /**
     * Store a new contact submission.
     *
     * @param array<string,mixed> $data
     */
    public function store(array $data): ?Contact
    {
        $sql = "INSERT INTO contacts (user_id, name, email, message)
                VALUES (:user_id, :name, :email, :message)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id' => $data['user_id'] ?? null,
            ':name'    => mb_substr(trim((string) ($data['name'] ?? '')), 0, 200),
            ':email'   => mb_substr(trim((string) ($data['email'] ?? '')), 0, 320),
            ':message' => trim((string) ($data['message'] ?? '')),
        ]);

        return $this->fetchOne(
            self::SELECT . ' WHERE c.contact_id = :contact_id',
            [':contact_id' => (int) $this->db->lastInsertId()],
            Contact::class
        );
    }

    public function exists(int $contact_id): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM contacts WHERE contact_id = :contact_id');
        $stmt->execute([':contact_id' => $contact_id]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Mark a contact as read (or unread).
     */
    public function markRead(int $contact_id, bool $read = true): bool
    {
        $stmt = $this->db->prepare("UPDATE contacts SET is_read = :is_read WHERE contact_id = :contact_id");
        return $stmt->execute([
            ':is_read'    => $read ? 1 : 0,
            ':contact_id' => $contact_id,
        ]);
    }

    /**
     * Count unread contacts.
     */
    public function countUnread(): int
    {
        return (int) $this->query("SELECT COUNT(*) FROM contacts WHERE is_read = 0")->fetchColumn();
    }
}

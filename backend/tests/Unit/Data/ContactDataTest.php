<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Data;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\ContactData;

#[CoversClass(ContactData::class)]
final class ContactDataTest extends TestCase
{
    private PDO $pdo;
    private ContactData $data;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            "CREATE TABLE contacts (
                contact_id   INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id      INTEGER DEFAULT NULL,
                name         TEXT NOT NULL,
                email        TEXT NOT NULL,
                message      TEXT NOT NULL,
                is_read      INTEGER NOT NULL DEFAULT 0,
                submitted_at DATETIME NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE users (
                user_id      INTEGER PRIMARY KEY,
                display_name TEXT NOT NULL,
                email        TEXT NOT NULL
            )"
        );
        $this->pdo->exec("INSERT INTO users (user_id, display_name, email) VALUES (1, 'Stargazer', 'star@example.com')");
        $this->data = new ContactData($this->pdo);
    }

    public function testStoreCreatesContactAndReturnsIt(): void
    {
        $contact = $this->data->store([
            'name'    => 'Alice',
            'email'   => 'alice@example.com',
            'message' => 'Hello there!',
        ]);

        $this->assertNotNull($contact);
        $this->assertSame(1, $contact->contact_id);
        $this->assertSame('Alice', $contact->name);
        $this->assertSame('alice@example.com', $contact->email);
        $this->assertSame('Hello there!', $contact->message);
        $this->assertFalse($contact->isRead());
        $this->assertNull($contact->user_id);
    }

    public function testStoreWithUserIdLinksToAccount(): void
    {
        $contact = $this->data->store([
            'user_id' => 1,
            'name'    => 'Stargazer',
            'email'   => 'star@example.com',
            'message' => 'Hi from account!',
        ]);

        $this->assertNotNull($contact);
        $this->assertSame(1, $contact->user_id);
        // Name/email resolved from the users table.
        $this->assertSame('Stargazer', $contact->name);
        $this->assertSame('star@example.com', $contact->email);
    }

    public function testRetrieveReturnsAllContactsNewestFirst(): void
    {
        // Insert with explicit timestamps to ensure ordering.
        $this->pdo->exec("INSERT INTO contacts (name, email, message, submitted_at) VALUES ('A', 'a@x.com', 'First', '2026-01-01T00:00:00Z')");
        $this->pdo->exec("INSERT INTO contacts (name, email, message, submitted_at) VALUES ('B', 'b@x.com', 'Second', '2026-01-02T00:00:00Z')");

        $all = $this->data->retrieve();
        $this->assertCount(2, $all);
        // Newest first.
        $this->assertSame('B', $all[0]->name);
        $this->assertSame('A', $all[1]->name);
    }

    public function testRetrieveUnreadOnlyFiltersReadContacts(): void
    {
        $this->data->store(['name' => 'A', 'email' => 'a@x.com', 'message' => 'One']);
        $this->data->store(['name' => 'B', 'email' => 'b@x.com', 'message' => 'Two']);
        $this->data->markRead(1, true);

        $unread = $this->data->retrieve(true);
        $this->assertCount(1, $unread);
        $this->assertSame('B', $unread[0]->name);
    }

    public function testMarkReadTogglesReadStatus(): void
    {
        $this->data->store(['name' => 'A', 'email' => 'a@x.com', 'message' => 'Test']);

        $this->assertTrue($this->data->markRead(1, true));
        $contacts = $this->data->retrieve();
        $this->assertTrue($contacts[0]->isRead());

        $this->assertTrue($this->data->markRead(1, false));
        $contacts = $this->data->retrieve();
        $this->assertFalse($contacts[0]->isRead());
    }

    public function testCountUnreadReturnsCorrectCount(): void
    {
        $this->assertSame(0, $this->data->countUnread());

        $this->data->store(['name' => 'A', 'email' => 'a@x.com', 'message' => 'One']);
        $this->data->store(['name' => 'B', 'email' => 'b@x.com', 'message' => 'Two']);
        $this->assertSame(2, $this->data->countUnread());

        $this->data->markRead(1, true);
        $this->assertSame(1, $this->data->countUnread());
    }

    public function testUserDisplayNameIsResolvedLive(): void
    {
        $this->data->store([
            'user_id' => 1,
            'name'    => 'Stargazer',
            'email'   => 'star@example.com',
            'message' => 'Hi',
        ]);

        // Rename the user account.
        $this->pdo->exec("UPDATE users SET display_name = 'New Name', email = 'new@example.com' WHERE user_id = 1");

        $contacts = $this->data->retrieve();
        $this->assertSame('New Name', $contacts[0]->name);
        $this->assertSame('new@example.com', $contacts[0]->email);
    }

    public function testStoreTrimAndTruncatesNameAndEmail(): void
    {
        $longName = str_repeat('A', 300);
        $contact = $this->data->store([
            'name'    => "  {$longName}  ",
            'email'   => 'trim@example.com',
            'message' => '  Hello  ',
        ]);

        $this->assertNotNull($contact);
        $this->assertSame(200, mb_strlen($contact->name));
        $this->assertSame('Hello', $contact->message);
    }
}

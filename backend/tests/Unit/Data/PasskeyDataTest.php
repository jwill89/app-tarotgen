<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Data;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\PasskeyData;

/**
 * WebAuthn passkey storage: create/find/list/count, sign-count updates, and
 * ownership-scoped rename/delete. In-memory SQLite.
 */
#[CoversClass(PasskeyData::class)]
final class PasskeyDataTest extends TestCase
{
    private PDO $pdo;
    private PasskeyData $data;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            "CREATE TABLE user_passkeys (
                passkey_id     INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id        INTEGER NOT NULL,
                credential_id  TEXT    NOT NULL,
                public_key_pem TEXT    NOT NULL,
                name           TEXT    NOT NULL DEFAULT 'My Passkey',
                sign_count     INTEGER NOT NULL DEFAULT 0,
                created_at     DATETIME    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
                last_used_at   TEXT    DEFAULT NULL
            )"
        );
        $this->data = new PasskeyData($this->pdo);
    }

    public function testCreateAndFindByCredentialId(): void
    {
        $id = $this->data->create(1, 'cred-abc', '-----PEM-----', 'Laptop', 0);
        $this->assertGreaterThan(0, $id);

        $found = $this->data->findByCredentialId('cred-abc');
        $this->assertNotNull($found);
        $this->assertSame(1, $found->user_id);
        $this->assertSame('-----PEM-----', $found->public_key_pem);

        $this->assertNull($this->data->findByCredentialId('does-not-exist'));
    }

    public function testListAndCountAreScopedToUser(): void
    {
        $this->data->create(1, 'c1', 'pem', 'Phone', 0);
        $this->data->create(1, 'c2', 'pem', 'Laptop', 0);
        $this->data->create(2, 'c3', 'pem', 'Tablet', 0);

        $this->assertCount(2, $this->data->getByUser(1));
        $this->assertSame(2, $this->data->countByUser(1));
        $this->assertSame(1, $this->data->countByUser(2));
        $this->assertSame(0, $this->data->countByUser(99));
    }

    public function testGetCredentialIdsReturnsRawIds(): void
    {
        $this->data->create(1, 'c1', 'pem', 'Phone', 0);
        $this->data->create(1, 'c2', 'pem', 'Laptop', 0);

        $ids = $this->data->getCredentialIds(1);
        sort($ids);
        $this->assertSame(['c1', 'c2'], $ids);
    }

    public function testUpdateSignCountAndStampsLastUsed(): void
    {
        $id = $this->data->create(1, 'c1', 'pem', 'Phone', 5);
        $this->data->updateSignCount($id, 12);

        $row = $this->data->findByCredentialId('c1');
        $this->assertNotNull($row);
        $this->assertSame(12, $row->sign_count);

        $stamp = $this->pdo->prepare('SELECT last_used_at FROM user_passkeys WHERE passkey_id = :id');
        $stamp->execute([':id' => $id]);
        $this->assertNotNull($stamp->fetchColumn(), 'last_used_at should be stamped on use');
    }

    public function testRenameIsOwnershipScoped(): void
    {
        $id = $this->data->create(1, 'c1', 'pem', 'Old', 0);

        $this->assertFalse($this->data->rename($id, 2, 'Hijacked'), 'wrong owner cannot rename');
        $this->assertTrue($this->data->rename($id, 1, 'New Name'));

        $this->assertSame('New Name', $this->data->getByUser(1)[0]->name);
    }

    public function testDeleteIsOwnershipScoped(): void
    {
        $id = $this->data->create(1, 'c1', 'pem', 'Phone', 0);

        $this->assertFalse($this->data->delete($id, 2), 'wrong owner cannot delete');
        $this->assertSame(1, $this->data->countByUser(1));

        $this->assertTrue($this->data->delete($id, 1));
        $this->assertSame(0, $this->data->countByUser(1));
    }
}

<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Service;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tarot\Data\DrawKeyData;
use Tarot\Repository\DrawKeyRepository;
use Tarot\Service\DrawKeyService;
use Tarot\Utility\Clock;
use Tarot\Utility\Pkce;

/**
 * The draw-key engine against the production schema (db/schema.sql) in memory:
 * minting a code, redeeming it once with its verifier, and telling a live key
 * in a header from a dead one or from any other credential.
 */
#[CoversClass(DrawKeyService::class)]
#[CoversClass(DrawKeyRepository::class)]
#[CoversClass(DrawKeyData::class)]
final class DrawKeyServiceTest extends TestCase
{
    private PDO $db;

    private DrawKeyService $service;

    private DrawKeyData $data;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec((string) file_get_contents(dirname(__DIR__, 3) . '/db/schema.sql'));
        $this->data = new DrawKeyData($this->db);
        $this->service = new DrawKeyService(new DrawKeyRepository($this->data));
    }

    public function testACodeIsStoredOnlyAsItsHashWithItsChallenge(): void
    {
        $code = $this->service->createCode('the-challenge');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $code);
        $this->assertSame(
            [['code_hash' => hash('sha256', $code), 'code_challenge' => 'the-challenge', 'key_hash' => null]],
            $this->rows('SELECT code_hash, code_challenge, key_hash FROM plugin_draw_keys')
        );
    }

    public function testTheRightVerifierRedeemsTheCodeForAKeyOnce(): void
    {
        $code = $this->service->createCode(Pkce::challengeFor('verifier'));

        $key = $this->service->exchangeCode($code, 'verifier');

        $this->assertIsString($key);
        $this->assertStringStartsWith(DrawKeyService::KEY_PREFIX, $key);
        $this->assertNull($this->service->exchangeCode($code, 'verifier'));
        $this->assertSame(hash('sha256', $key), $this->value('SELECT key_hash FROM plugin_draw_keys'));
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function failedExchanges(): iterable
    {
        yield 'no code' => ['', 'verifier'];
        yield 'no verifier' => ['{code}', ''];
        yield 'the wrong verifier' => ['{code}', 'not-the-verifier'];
        yield 'a code nobody minted' => [str_repeat('ab', 32), 'verifier'];
    }

    #[DataProvider('failedExchanges')]
    public function testAFailedExchangeGivesNoKeyAndLeavesTheCodeRedeemable(string $code, string $verifier): void
    {
        $minted = $this->service->createCode(Pkce::challengeFor('verifier'));

        $this->assertNull($this->service->exchangeCode(str_replace('{code}', $minted, $code), $verifier));

        $this->assertIsString($this->service->exchangeCode($minted, 'verifier'));
    }

    public function testAnExpiredCodeCannotBeRedeemed(): void
    {
        $code = $this->service->createCode(Pkce::challengeFor('verifier'));
        $this->db->exec("UPDATE plugin_draw_keys SET code_expires_at = datetime('now', '-1 second')");

        $this->assertNull($this->service->exchangeCode($code, 'verifier'));
    }

    public function testOfTwoRedemptionsRacingForOneCodeOnlyOneStoresAKey(): void
    {
        $this->service->createCode('challenge');
        $id = (int) $this->value('SELECT id FROM plugin_draw_keys');

        $this->assertTrue($this->data->issueKey($id, hash('sha256', 'first')));
        $this->assertFalse($this->data->issueKey($id, hash('sha256', 'second')));
        $this->assertSame(hash('sha256', 'first'), $this->value('SELECT key_hash FROM plugin_draw_keys'));
    }

    public function testAHeaderPresentsAKeyOnlyWhenItsBearerHasTheDrawKeyPrefix(): void
    {
        $this->assertTrue($this->service->presentsKey('Bearer tg_dk_anything'));
        $this->assertTrue($this->service->presentsKey('bearer   tg_dk_anything  '));
        $this->assertFalse($this->service->presentsKey('Bearer tg_pat_anything'));
        $this->assertFalse($this->service->presentsKey('Basic tg_dk_anything'));
        $this->assertFalse($this->service->presentsKey(''));
    }

    public function testALiveKeyResolvesToItsFingerprintAndRecordsTheUse(): void
    {
        $key = $this->issue();
        $this->assertNull($this->value('SELECT last_used_at FROM plugin_draw_keys'));

        $this->assertSame(hash('sha256', $key), $this->service->resolve("Bearer {$key}"));

        $this->assertIsString($this->value('SELECT last_used_at FROM plugin_draw_keys'));
    }

    public function testADeadKeyOrAnotherCredentialResolvesToNothing(): void
    {
        $key = $this->issue();

        $this->assertNull($this->service->resolve(''));
        $this->assertNull($this->service->resolve('Bearer tg_dk_' . str_repeat('0', 64)));
        // The account token table is never consulted, nor this one for an account token.
        $this->assertNull($this->service->resolve('Bearer tg_pat_' . substr($key, strlen(DrawKeyService::KEY_PREFIX))));

        $this->db->exec("UPDATE plugin_draw_keys SET revoked_at = datetime('now')");
        $this->assertNull($this->service->resolve("Bearer {$key}"));
        $this->assertNull($this->value('SELECT last_used_at FROM plugin_draw_keys'));
    }

    public function testRetentionDeletesOnlyCodesThatExpiredUnredeemedAndADryRunKeepsThem(): void
    {
        $this->data->storeCode(hash('sha256', 'expired'), 'c', Clock::in(-60));
        $this->data->storeCode(hash('sha256', 'pending'), 'c', Clock::in(300));
        // Issued keys stay whatever their code's expiry, a revoked one included.
        $this->issue();
        $this->issue();
        $this->db->exec("UPDATE plugin_draw_keys SET code_expires_at = datetime('now', '-1 day') WHERE key_hash IS NOT NULL");
        $this->db->exec("UPDATE plugin_draw_keys SET revoked_at = datetime('now') WHERE id = (SELECT max(id) FROM plugin_draw_keys)");
        $repository = new DrawKeyRepository($this->data);

        $this->assertSame(1, $repository->purgeUnredeemedCodes(dryRun: true));
        $this->assertSame(4, (int) $this->value('SELECT count(*) FROM plugin_draw_keys'));

        $this->assertSame(1, $repository->purgeUnredeemedCodes());
        $this->assertSame(
            [hash('sha256', 'pending')],
            array_column($this->rows('SELECT code_hash FROM plugin_draw_keys WHERE key_hash IS NULL'), 'code_hash')
        );
        $this->assertSame(2, (int) $this->value('SELECT count(*) FROM plugin_draw_keys WHERE key_hash IS NOT NULL'));
        $this->assertSame(0, $repository->purgeUnredeemedCodes());
    }

    public function testARetentionRunThatFailsLeavesNothingHalfDone(): void
    {
        // A database from before the migration: the DELETE fails on the missing table.
        $old = new PDO('sqlite::memory:');
        $old->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        try {
            new DrawKeyData($old)->purgeUnredeemedCodes(false);
            $this->fail('The purge ran without its table.');
        } catch (PDOException $e) {
            $this->assertStringContainsString('plugin_draw_keys', $e->getMessage());
        }

        $this->assertFalse($old->inTransaction());
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function value(string $sql): mixed
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchColumn();
    }

    private function issue(): string
    {
        $key = $this->service->exchangeCode($this->service->createCode(Pkce::challengeFor('verifier')), 'verifier');
        $this->assertIsString($key);
        return $key;
    }
}

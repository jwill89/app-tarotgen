<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Service\PasskeyDecoys;

/**
 * The stand-in credential ids passkey sign-in options name for an email with no
 * passkeys: the same every time for the same email and key, different across
 * emails and keys, shaped like real ones, and the key kept in a file made on
 * first use.
 */
#[CoversClass(PasskeyDecoys::class)]
final class PasskeyDecoysTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tarot-decoys-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $files = glob($this->dir . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testTheSameEmailAlwaysGetsTheSameStandIns(): void
    {
        $decoys = new PasskeyDecoys($this->keyFile());

        $this->assertSame($decoys->forEmail('nobody@example.test'), $decoys->forEmail('nobody@example.test'));
        // A later request reads the same key back from the file.
        $this->assertSame($decoys->forEmail('nobody@example.test'), new PasskeyDecoys($this->keyFile())->forEmail('nobody@example.test'));
    }

    public function testStandInsLookLikeRealCredentialIds(): void
    {
        $decoys = new PasskeyDecoys($this->keyFile());
        $counts = [];
        $lengths = [];

        for ($i = 0; $i < 200; $i++) {
            $ids = $decoys->forEmail("reader{$i}@example.test");
            $counts[count($ids)] = true;
            foreach ($ids as $id) {
                $lengths[strlen($id)] = true;
            }
        }

        // Mostly one passkey, sometimes two; ids the lengths real providers use.
        ksort($counts);
        ksort($lengths);
        $this->assertSame([1, 2], array_keys($counts));
        $this->assertSame([16, 20, 32, 64], array_keys($lengths));
    }

    public function testDifferentEmailsAndDifferentKeysGetDifferentStandIns(): void
    {
        $decoys = new PasskeyDecoys($this->keyFile());
        $first = $decoys->forEmail('nobody@example.test');

        $this->assertNotSame($first, $decoys->forEmail('somebody@example.test'));
        $this->assertNotSame($first, new PasskeyDecoys($this->dir . '/other.key')->forEmail('nobody@example.test'));
    }

    public function testTheKeyIsMadeOnFirstUseAndKept(): void
    {
        $this->assertFileDoesNotExist($this->keyFile());

        new PasskeyDecoys($this->keyFile())->forEmail('nobody@example.test');

        $key = (string) file_get_contents($this->keyFile());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
        new PasskeyDecoys($this->keyFile())->forEmail('somebody@example.test');
        $this->assertSame($key, file_get_contents($this->keyFile()));
        // Nothing left behind but the key.
        $this->assertSame([$this->keyFile()], glob($this->dir . '/*'));
    }

    public function testAnUnusableKeyFileIsReplaced(): void
    {
        file_put_contents($this->keyFile(), "half-written\n");

        new PasskeyDecoys($this->keyFile())->forEmail('nobody@example.test');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) file_get_contents($this->keyFile()));
    }

    public function testAKeyThatCannotBeKeptStillGivesStandIns(): void
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'tarot-decoys-log');
        $previous = ini_set('error_log', $log);
        try {
            $ids = new PasskeyDecoys($this->dir . '/missing-dir/decoy.key')->forEmail('nobody@example.test');
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $this->assertContains(strlen($ids[0]), [16, 20, 32, 64]);
        $this->assertStringContainsString('could not create', (string) file_get_contents($log));
        unlink($log);
    }

    private function keyFile(): string
    {
        return $this->dir . '/decoy.key';
    }
}

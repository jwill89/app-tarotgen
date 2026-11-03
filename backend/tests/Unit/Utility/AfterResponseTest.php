<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Utility;

use ArrayObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SessionHandlerInterface;
use Tarot\Utility\AfterResponse;

/**
 * Work queued for after the response: it runs only once the response is handed
 * over, in order and once, and one failing task can't stop the others. The
 * finisher is a stand-in for fastcgi_finish_request(), which the CLI lacks.
 */
#[CoversClass(AfterResponse::class)]
final class AfterResponseTest extends TestCase
{
    private string|false $savedErrorLog = false;

    private string $log;

    protected function setUp(): void
    {
        $this->log = (string)tempnam(sys_get_temp_dir(), 'tarot-after-response-log');
        $this->savedErrorLog = ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->savedErrorLog === false ? '' : $this->savedErrorLog);
        @unlink($this->log);
    }

    public function testWithoutPhpFpmWorkCannotWaitForTheResponse(): void
    {
        // The CLI, as PHP's built-in dev server: callers do the work inline.
        $this->assertFalse(function_exists('fastcgi_finish_request'));
        $this->assertFalse(new AfterResponse()->isAvailable());
        $this->assertTrue(new AfterResponse(static fn() => null)->isAvailable());
    }

    public function testTheResponseIsHandedOverFirstThenEachTaskRunsInOrderOnce(): void
    {
        /** @var ArrayObject<int,string> $steps */
        $steps = new ArrayObject();
        $after = new AfterResponse(static function () use ($steps): void {
            $steps->append('response sent');
        });

        $after->defer(static function () use ($steps): void {
            $steps->append('first');
        });
        $after->defer(static function () use ($steps): void {
            $steps->append('second');
        });
        $this->assertSame([], $steps->getArrayCopy());

        $after->run();
        $after->run();

        $this->assertSame(['response sent', 'first', 'second'], $steps->getArrayCopy());
    }

    public function testWithNothingQueuedTheResponseIsLeftAlone(): void
    {
        $finished = false;
        $after = new AfterResponse(static function () use (&$finished): void {
            $finished = true;
        });

        $after->run();

        $this->assertFalse($finished);
    }

    public function testATaskThatThrowsIsLoggedAndTheRestStillRun(): void
    {
        $ran = false;
        $after = new AfterResponse(static fn() => null);
        $after->defer(static function (): never {
            throw new RuntimeException('SMTP went away');
        });
        $after->defer(static function () use (&$ran): void {
            $ran = true;
        });

        $after->run();

        $this->assertTrue($ran);
        $this->assertStringContainsString('[AfterResponse] task failed: SMTP went away', (string)file_get_contents($this->log));
    }

    public function testTheSessionIsWrittenBeforeTheResponseIsHandedOver(): void
    {
        /** @var ArrayObject<string,string> $stored */
        $stored = new ArrayObject();
        session_set_save_handler(new readonly class ($stored) implements SessionHandlerInterface {
            /** @param ArrayObject<string,string> $stored */
            public function __construct(private ArrayObject $stored)
            {
            }

            public function open(string $path, string $name): bool
            {
                return true;
            }

            public function close(): bool
            {
                return true;
            }

            public function read(string $id): string
            {
                return $this->stored[$id] ?? '';
            }

            public function write(string $id, string $data): bool
            {
                $this->stored[$id] = $data;
                return true;
            }

            public function destroy(string $id): bool
            {
                unset($this->stored[$id]);
                return true;
            }

            public function gc(int $max_lifetime): int
            {
                return 0;
            }
        }, true);
        session_id('after-response-test');
        session_start();
        // Strict mode (Session::start() turns it on, process-wide) may have issued another id.
        $id = (string)session_id();
        $_SESSION['user_id'] = 7;

        $seen = [];
        $after = new AfterResponse(static function () use (&$seen, $stored, $id): void {
            $seen = [session_status(), $stored[$id] ?? null];
        });
        $after->defer(static fn() => null);
        $after->run();
        $_SESSION = [];

        $this->assertSame([PHP_SESSION_NONE, 'user_id|i:7;'], $seen);
    }
}

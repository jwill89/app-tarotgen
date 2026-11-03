<?php

namespace Tarot\Utility;

use Closure;
use Throwable;

/**
 * Work that waits until the browser has its answer. Under PHP-FPM,
 * fastcgi_finish_request() hands the response over and the request carries on,
 * so slow work queued here (the public account emails, AuthService) costs the
 * visitor nothing and can't make an answer slower, or tell anyone something.
 *
 * Where a response can't be finished early (the CLI the tests run in, PHP's
 * built-in dev server), {@see isAvailable()} is false and callers do the work
 * inline, as before. api/index.php calls {@see run()} once the app has sent
 * its response; the container shares one instance, so whatever a service
 * queued during the request is there.
 */
final class AfterResponse
{
    /** @var list<callable(): mixed> */
    private array $tasks = [];

    /** Hands the response over: fastcgi_finish_request(), where there is one. */
    private readonly ?Closure $finishResponse;

    public function __construct(?Closure $finishResponse = null)
    {
        $this->finishResponse = $finishResponse
            ?? (function_exists('fastcgi_finish_request') ? fastcgi_finish_request(...) : null);
    }

    /** Whether queued work really runs after the response (PHP-FPM), not before it. */
    public function isAvailable(): bool
    {
        return $this->finishResponse !== null;
    }

    /** @param callable(): mixed $task */
    public function defer(callable $task): void
    {
        $this->tasks[] = $task;
    }

    /**
     * Finish the response, then run what was queued, in order. A task that throws
     * is logged and the rest still run: nobody is left waiting to hear about it.
     */
    public function run(): void
    {
        if ($this->tasks === []) {
            return;
        }

        // The session is written at the end of the script, and the browser's next
        // request may arrive before the queued work is done: write it now.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if ($this->finishResponse !== null) {
            ($this->finishResponse)();
        }

        $tasks       = $this->tasks;
        $this->tasks = [];
        foreach ($tasks as $task) {
            try {
                $task();
            } catch (Throwable $e) {
                error_log('[AfterResponse] task failed: ' . $e->getMessage());
            }
        }
    }
}

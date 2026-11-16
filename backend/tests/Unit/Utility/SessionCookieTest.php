<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Utility\Session;

/**
 * The session cookie the bootstrap sets: HttpOnly, SameSite=Lax, for the whole
 * site, and in production Secure under a `__Host-` name.
 */
#[CoversClass(Session::class)]
final class SessionCookieTest extends TestCase
{
    public function testInProductionTheCookieIsSecureUnderAHostPrefixedName(): void
    {
        $cookie = Session::cookieSettings(true);

        $this->assertSame('__Host-tarotgen', $cookie['name']);
        $this->assertStringStartsWith('__Host-', $cookie['name']);
        // What the prefix demands, or the browser drops the cookie.
        $this->assertTrue($cookie['params']['secure']);
        $this->assertSame('/', $cookie['params']['path']);
        $this->assertArrayNotHasKey('domain', $cookie['params']);
        $this->assertTrue($cookie['params']['httponly']);
        $this->assertSame(0, $cookie['params']['lifetime']);
    }

    public function testOutsideProductionTheCookieWorksOverPlainHttp(): void
    {
        $cookie = Session::cookieSettings(false);

        $this->assertSame('tarotgen', $cookie['name']);
        $this->assertFalse($cookie['params']['secure']);
        $this->assertTrue($cookie['params']['httponly']);
    }
}

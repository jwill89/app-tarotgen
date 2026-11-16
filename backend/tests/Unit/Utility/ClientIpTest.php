<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tarot\Utility\ClientIp;

/**
 * The address a rate limit counts: REMOTE_ADDR as Apache restored it, never a
 * forwarding header, and an IPv6 client by its /64.
 */
#[CoversClass(ClientIp::class)]
final class ClientIpTest extends TestCase
{
    private function request(string $remoteAddr, ?string $cfConnectingIp = null): ServerRequestInterface
    {
        $request = new ServerRequestFactory()->createServerRequest(
            'GET',
            'http://tarotgen.io/api/readings/abc',
            $remoteAddr === '' ? [] : ['REMOTE_ADDR' => $remoteAddr]
        );

        if ($cfConnectingIp !== null) {
            $request = $request->withHeader('CF-Connecting-IP', $cfConnectingIp);
        }

        return $request;
    }

    public function testAnIpv4AddressIsCountedAsItIs(): void
    {
        $this->assertSame('198.51.100.4', ClientIp::resolve($this->request('198.51.100.4')));
    }

    public function testAForwardingHeaderIsNeverRead(): void
    {
        // Apache has already put the visitor in REMOTE_ADDR; a header sent straight
        // to the origin, even from a Cloudflare address, changes nothing.
        $this->assertSame('173.245.48.9', ClientIp::resolve($this->request('173.245.48.9', '203.0.113.7')));
        $this->assertSame('198.51.100.4', ClientIp::resolve($this->request('198.51.100.4', '203.0.113.7')));
    }

    public function testAnIpv6ClientIsCountedByItsSlash64(): void
    {
        $this->assertSame('2001:db8:1:2::/64', ClientIp::resolve($this->request('2001:db8:1:2:aaaa:bbbb:cccc:dddd')));
        $this->assertSame(
            ClientIp::resolve($this->request('2001:db8:1:2::1')),
            ClientIp::resolve($this->request('2001:db8:1:2:ffff:ffff:ffff:fffe')),
        );
        $this->assertNotSame(
            ClientIp::resolve($this->request('2001:db8:1:2::1')),
            ClientIp::resolve($this->request('2001:db8:1:3::1')),
        );
    }

    public function testAnIpv4MappedAddressIsCountedAsItsIpv4Address(): void
    {
        $this->assertSame('198.51.100.4', ClientIp::resolve($this->request('::ffff:198.51.100.4')));
    }

    public function testUnknownWhenNoRemoteAddr(): void
    {
        $this->assertSame('unknown', ClientIp::resolve($this->request('')));
    }
}

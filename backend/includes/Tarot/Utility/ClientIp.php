<?php

namespace Tarot\Utility;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The visitor's address, as the rate limiters count it.
 *
 * The site sits behind Cloudflare, and Apache restores the visitor's address
 * before PHP runs: the vhost's mod_remoteip takes `CF-Connecting-IP`, trusting it
 * only from Cloudflare's published ranges, and puts it in `REMOTE_ADDR`. So this
 * reads `REMOTE_ADDR` alone, and never a forwarding header a client could send
 * straight to the origin.
 *
 * An IPv6 client is counted by its /64, the block one household or host is
 * handed, so stepping through the addresses inside it doesn't reset a limit.
 */
final class ClientIp
{
    /**
     * The address a limit is kept against: the IPv4 address as it is, an IPv6
     * address as its /64 (`2001:db8:1:2::/64`), or 'unknown' when there is none.
     */
    public static function resolve(ServerRequestInterface $request): string
    {
        $remote = trim((string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));
        if ($remote === '') {
            return 'unknown';
        }

        if (filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $remote;
        }

        $packed = inet_pton($remote);
        if ($packed === false) {
            return $remote;
        }

        // An IPv4 client on a dual-stack socket arrives as ::ffff:a.b.c.d.
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        $prefix = inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8));
        return $prefix === false ? $remote : $prefix . '/64';
    }
}

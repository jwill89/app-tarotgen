<?php

namespace Tarot\Utility;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the real client IP for a request that reaches us through Cloudflare.
 *
 * The site sits behind Cloudflare, so the transport peer (`REMOTE_ADDR`) is a
 * Cloudflare edge address — using it directly would collapse every visitor onto a
 * handful of edge IPs and make IP rate-limiting fire all-or-nothing. Cloudflare
 * forwards the true client in the `CF-Connecting-IP` header.
 *
 * That header is only trustworthy when the request actually came *from* Cloudflare,
 * so we honour it only when `REMOTE_ADDR` falls inside Cloudflare's published
 * ranges. This is safe in every deployment shape:
 *   - Behind Cloudflare (no mod_remoteip): REMOTE_ADDR is a CF edge → trust the header.
 *   - Behind Cloudflare *with* mod_remoteip already rewriting REMOTE_ADDR to the real
 *     client: REMOTE_ADDR is no longer a CF range → we return it (the real client) and
 *     ignore the header.
 *   - Direct hit to the origin (attacker bypassing CF): REMOTE_ADDR is the attacker's
 *     real address → a spoofed CF-Connecting-IP is ignored.
 *
 * Firewalling the origin's :443 to Cloudflare's ranges remains the belt to this
 * suspenders, but the range check means a missing firewall can't be used to forge
 * an arbitrary client IP.
 */
final class ClientIp
{
    /**
     * Cloudflare IPv4 ranges — https://www.cloudflare.com/ips-v4
     * Update if Cloudflare changes them (rare; a handful of times over many years).
     *
     * @var list<string>
     */
    private const array CLOUDFLARE_IPV4 = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
    ];

    /**
     * Cloudflare IPv6 ranges — https://www.cloudflare.com/ips-v6
     *
     * @var list<string>
     */
    private const array CLOUDFLARE_IPV6 = [
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /**
     * The best-known client IP for this request, or 'unknown' when unavailable.
     */
    public static function resolve(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');

        if ($remote !== '' && self::isCloudflare($remote)) {
            $forwarded = trim($request->getHeaderLine('CF-Connecting-IP'));
            if ($forwarded !== '' && filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
                return $forwarded;
            }
        }

        return $remote !== '' ? $remote : 'unknown';
    }

    /** True when $ip is inside any Cloudflare range (matched by address family). */
    private static function isCloudflare(string $ip): bool
    {
        $ranges = str_contains($ip, ':') ? self::CLOUDFLARE_IPV6 : self::CLOUDFLARE_IPV4;
        return array_any($ranges, fn($cidr) => self::ipInCidr($ip, $cidr));
    }

    /** Whether $ip (v4 or v6) falls within the $cidr block. */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        $parts  = explode('/', $cidr, 2);
        $subnet = $parts[0];
        if (!isset($parts[1])) {
            return $ip === $subnet;
        }
        $maskBits = (int) $parts[1];

        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false; // unparseable, or a v4/v6 family mismatch
        }

        $length = strlen($ipBin);
        if ($maskBits < 0 || $maskBits > $length * 8) {
            return false;
        }

        $fullBytes     = intdiv($maskBits, 8);
        $remainderBits = $maskBits % 8;

        if ($fullBytes > 0 && strncmp($ipBin, $subnetBin, $fullBytes) !== 0) {
            return false;
        }

        if ($remainderBits === 0) {
            return true;
        }

        $maskByte = (0xff << (8 - $remainderBits)) & 0xff;
        return (ord($ipBin[$fullBytes]) & $maskByte) === (ord($subnetBin[$fullBytes]) & $maskByte);
    }
}

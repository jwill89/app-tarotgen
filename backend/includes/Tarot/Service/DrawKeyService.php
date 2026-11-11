<?php

namespace Tarot\Service;

use Random\RandomException;
use Tarot\Repository\DrawKeyRepository;
use Tarot\Utility\BearerToken;
use Tarot\Utility\Clock;
use Tarot\Utility\Pkce;

/**
 * Draw keys: how an unlinked plugin install draws readings once Turnstile guards
 * New Draw. A game client can't show a browser challenge, so the person passes
 * one once, on the site's key page, and the install keeps the key.
 *
 * Minted the way the account link's tokens are (PKCE over a loopback redirect):
 *   1. The key page, its Turnstile challenge passed, calls {@see createCode()}
 *      with the plugin's `code_challenge` and redirects the code to the plugin.
 *   2. The plugin calls {@see exchangeCode()} with the code and its verifier, and
 *      gets the key, once.
 *   3. Each draw sends it as `Authorization: Bearer tg_dk_…`; {@see resolve()}.
 *
 * A key only lets a draw through: it names no account, and the account routes
 * look up `tg_pat_` tokens only, so it opens nothing else. Only hashes are kept.
 */
class DrawKeyService
{
    /** Tells a draw key from an account token (`tg_pat_`), in a header or in a leak. */
    public const string KEY_PREFIX = 'tg_dk_';

    /** Codes are single-use and short-lived, as the account link's are. */
    private const int CODE_TTL_SECONDS = 300;

    public function __construct(private readonly DrawKeyRepository $keys) {}

    /**
     * Mint a one-time code bound to the plugin's PKCE challenge, for a browser
     * that passed the key page's challenge. Returns the raw code.
     *
     * @throws RandomException
     */
    public function createCode(string $codeChallenge): string
    {
        $code = bin2hex(random_bytes(32));
        $this->keys->storeCode($this->hash($code), $codeChallenge, Clock::in(self::CODE_TTL_SECONDS));

        return $code;
    }

    /**
     * Redeem a code and its PKCE verifier for a draw key (256 random bits).
     * Returns the raw key once, or null for an unknown, expired or redeemed code
     * or the wrong verifier. A wrong verifier doesn't spend the code.
     *
     * @throws RandomException
     */
    public function exchangeCode(string $code, string $codeVerifier): ?string
    {
        if ($code === '' || $codeVerifier === '') {
            return null;
        }

        $pending = $this->keys->findRedeemableCode($this->hash($code));
        if ($pending === null || !Pkce::verifies($codeVerifier, $pending->code_challenge)) {
            return null;
        }

        $key = self::KEY_PREFIX . bin2hex(random_bytes(32));

        return $this->keys->issueKey($pending->id, $this->hash($key)) ? $key : null;
    }

    /** Whether the header carries a draw key (live or not), as opposed to no or another credential. */
    public function presentsKey(string $authorizationHeader): bool
    {
        return str_starts_with(BearerToken::from($authorizationHeader) ?? '', self::KEY_PREFIX);
    }

    /**
     * The live draw key in an `Authorization: Bearer …` header, as its fingerprint
     * (the SHA-256 it is stored as: what the per-key rate limits count against),
     * or null when there is none or it is unknown or revoked. Records the use.
     */
    public function resolve(string $authorizationHeader): ?string
    {
        $key = BearerToken::from($authorizationHeader);
        if ($key === null || !str_starts_with($key, self::KEY_PREFIX)) {
            return null;
        }

        $fingerprint = $this->hash($key);
        $id = $this->keys->findActiveKeyId($fingerprint);
        if ($id === null) {
            return null;
        }

        $this->keys->touchLastUsed($id);

        return $fingerprint;
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}

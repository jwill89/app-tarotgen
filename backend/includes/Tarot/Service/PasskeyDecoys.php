<?php

namespace Tarot\Service;

use Tarot\Config\Paths;

/**
 * Stand-in passkey credential ids, so that passkey sign-in options for an email
 * look the same whether or not it belongs to an account with passkeys (the
 * decoy approach WebAuthn describes for preventing username enumeration).
 *
 * Why not simply drop allowCredentials: passkeys are registered with
 * residentKey "preferred", so an authenticator that can't store one (an older
 * security key) made a non-discoverable credential, and nothing recorded which.
 * Those only answer when the server names their id, so an email-scoped sign-in
 * has to keep naming them.
 *
 * The stand-ins are derived from the email with a secret key, so asking twice
 * gives the same ids, as a real account's would, and nobody without the key can
 * tell them from real ones. Their count and lengths vary the way real accounts'
 * do (mostly one passkey; ids of 16 to 64 bytes, by provider).
 *
 * The key is made on first use and kept beside the database (in production
 * outside the web root): no setting to configure. It isn't in the database
 * backup; losing it only changes every stand-in once.
 */
class PasskeyDecoys
{
    /** Credential id lengths real providers use, in bytes. */
    private const array LENGTHS = [16, 20, 32, 64];

    private ?string $key = null;

    /** @param string|null $keyFile Where the key lives; null for the default beside the database. */
    public function __construct(private readonly ?string $keyFile = null) {}

    /**
     * The stand-in credential ids for $email (raw bytes, like a stored id once
     * base64url-decoded). Always the same for the same email and key.
     *
     * @return non-empty-list<string>
     */
    public function forEmail(string $email): array
    {
        $key  = $this->key();
        $seed = hash_hmac('sha256', "shape\0" . $email, $key, true);

        $ids = [self::credentialId($key, $seed, 0, $email)];
        // One in four gets a second passkey.
        if (ord($seed[0]) % 4 === 0) {
            $ids[] = self::credentialId($key, $seed, 1, $email);
        }

        return $ids;
    }

    /** Stand-in number $n for $email, of a length its $seed picks. */
    private static function credentialId(string $key, string $seed, int $n, string $email): string
    {
        $length = self::LENGTHS[ord($seed[$n + 1]) % count(self::LENGTHS)];

        return hash_hkdf('sha256', $key, $length, "credential {$n}\0" . $email);
    }

    private function key(): string
    {
        return $this->key ??= $this->loadKey();
    }

    private function loadKey(): string
    {
        $file = $this->keyFile ?? dirname(Paths::database()) . '/passkey-decoy.key';

        $key = self::read($file);
        if ($key !== null) {
            return $key;
        }

        // None yet (or an unusable one): make it. Written whole to a temporary
        // file and renamed into place, so nobody ever reads a half-written key.
        // Two first requests at once would each rename theirs; the later key
        // wins, and the earlier answer's stand-ins change once.
        if (is_writable(dirname($file))) {
            $temporary = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
            file_put_contents($temporary, bin2hex(random_bytes(32)));
            chmod($temporary, 0o600);
            rename($temporary, $file);
        }

        $key = self::read($file);
        if ($key !== null) {
            return $key;
        }

        // Can't keep a key (an unwritable directory): stand-ins still go out, so
        // sign-in works, but they change on every request until this is fixed.
        error_log("PasskeyDecoys: could not create {$file}; passkey sign-in options can tell which emails have passkeys until it exists.");
        return random_bytes(32);
    }

    /** The key in $file, or null when there's no usable one. */
    private static function read(string $file): ?string
    {
        $hex = is_readable($file) ? file_get_contents($file) : false;
        if ($hex === false || preg_match('/^[0-9a-f]{64}$/', trim($hex)) !== 1) {
            return null;
        }

        return (string) hex2bin(trim($hex));
    }
}

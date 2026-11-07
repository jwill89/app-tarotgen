<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;
use Routes\Internal\PasskeyController;
use Tarot\Repository\PasskeyRepository;
use Tarot\Structure\User;
use Tarot\Utility\Session;

/**
 * The WebAuthn ceremonies end to end, with the test playing the authenticator: a
 * P-256 key pair, "none" attestation (what most passkey providers send) and
 * hand-built CBOR, so the server verifies real signatures. Covers creating a
 * passkey, signing in with it, and each way either ceremony is refused.
 */
#[CoversClass(PasskeyController::class)]
#[CoversClass(PasskeyRepository::class)]
#[CoversClass(Session::class)]
final class PasskeyCeremonyTest extends HttpTestCase
{
    private const string ORIGIN = 'https://' . self::HOST;

    /** Authenticator flags: user present, and attested credential data included. */
    private const int FLAG_USER_PRESENT = 0x01;
    private const int FLAG_ATTESTED_DATA = 0x40;

    private OpenSSLAsymmetricKey $key;

    /** The raw credential id the authenticator makes up for its key. */
    private string $credentialId;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        // The relying party id comes from the site URL; the test host plays the site.
        $this->setEnv('APP_URL', self::ORIGIN);

        $this->key = self::newKey();
        $this->credentialId = random_bytes(16);
        $this->reader = $this->createUser('reader@example.test');
    }

    public function testAPasskeyCreatedOnTheAccountPageIsStoredForThatAccount(): void
    {
        $this->signInAs($this->reader->user_id);

        $created = $this->assertJsonResponse(201, $this->post('/auth/passkeys/register', $this->attestation($this->registrationChallenge(), ['name' => 'Laptop'])));

        $this->assertDocumented('post', '/auth/passkeys/register', 201, $created);
        $this->assertTrue($created['success']);
        $this->assertIsArray($created['passkey']);
        $this->assertSame('Laptop', $created['passkey']['name']);
        $this->assertNull($created['passkey']['last_used_at']);

        $stored = $this->rows('SELECT passkey_id, user_id, credential_id, name, sign_count FROM user_passkeys');
        $this->assertSame([[
            'passkey_id' => $created['passkey']['passkey_id'],
            'user_id' => $this->reader->user_id,
            'credential_id' => self::base64Url($this->credentialId),
            'name' => 'Laptop',
            'sign_count' => 0,
        ]], $stored);

        $me = $this->assertJsonResponse(200, $this->get('/auth/me'));
        $this->assertIsArray($me['user']);
        $this->assertTrue($me['user']['has_passkeys']);
    }

    public function testAPasskeyWithoutANameIsCalledMyPasskey(): void
    {
        $this->signInAs($this->reader->user_id);

        $created = $this->assertJsonResponse(201, $this->post('/auth/passkeys/register', $this->attestation($this->registrationChallenge(), ['name' => ' '])));

        $this->assertIsArray($created['passkey']);
        $this->assertSame('My Passkey', $created['passkey']['name']);
    }

    public function testRegisteringNeedsTheChallengeFromTheOptionsRequest(): void
    {
        $this->signInAs($this->reader->user_id);

        $this->assertSame(
            ['error' => 'No pending registration challenge.'],
            $this->assertJsonResponse(400, $this->post('/auth/passkeys/register', $this->attestation(self::base64Url(random_bytes(32)))))
        );

        $this->registrationChallenge();
        $this->assertSame(
            ['error' => 'Registration failed: invalid challenge'],
            $this->assertJsonResponse(400, $this->post('/auth/passkeys/register', $this->attestation(self::base64Url(random_bytes(32)))))
        );
        $this->assertSame(0, $this->rowCount('user_passkeys'));
    }

    public function testAChallengeIsSpentByAFailedAttempt(): void
    {
        $this->signInAs($this->reader->user_id);
        $challenge = $this->registrationChallenge();

        $this->assertSame(
            ['error' => 'Missing attestation data.'],
            $this->assertJsonResponse(400, $this->post('/auth/passkeys/register', ['name' => 'Laptop']))
        );

        $this->assertSame(
            ['error' => 'No pending registration challenge.'],
            $this->assertJsonResponse(400, $this->post('/auth/passkeys/register', $this->attestation($challenge)))
        );
    }

    public function testAnAttestationMadeForAnotherSiteIsRefused(): void
    {
        $this->signInAs($this->reader->user_id);

        $this->assertSame(
            ['error' => 'Registration failed: invalid origin'],
            $this->assertJsonResponse(400, $this->post('/auth/passkeys/register', $this->attestation($this->registrationChallenge(), ['origin' => 'https://evil.example'])))
        );

        $this->assertSame(
            ['error' => 'Registration failed: invalid rpId hash'],
            $this->assertJsonResponse(400, $this->post('/auth/passkeys/register', $this->attestation($this->registrationChallenge(), ['rpId' => 'evil.example'])))
        );
        $this->assertSame(0, $this->rowCount('user_passkeys'));
    }

    public function testAMalformedAttestationIsRefused(): void
    {
        $this->signInAs($this->reader->user_id);
        $response = $this->attestation($this->registrationChallenge());
        $response['attestationObject'] = self::base64Url('not CBOR at all');

        $refused = $this->assertJsonResponse(400, $this->post('/auth/passkeys/register', $response));

        $this->assertIsString($refused['error']);
        $this->assertStringStartsWith('Registration failed: ', $refused['error']);
        $this->assertSame(0, $this->rowCount('user_passkeys'));
    }

    public function testAPasskeyAlreadyRegisteredIsRefusedNotStoredTwice(): void
    {
        $this->signInAs($this->reader->user_id);
        $this->assertJsonResponse(201, $this->post('/auth/passkeys/register', $this->attestation($this->registrationChallenge())));

        // Browsers skip an excluded credential, but nothing makes a client honour the list.
        $this->assertSame(
            ['error' => 'This passkey is already registered.'],
            $this->assertJsonResponse(400, $this->post('/auth/passkeys/register', $this->attestation($this->registrationChallenge())))
        );
        $this->assertSame(1, $this->rowCount('user_passkeys'));
    }

    public function testSigningInWithAPasskeyStartsAFreshRememberedSession(): void
    {
        $this->createPasskey();
        $this->useSessionCookie(null);

        $challenge = $this->signInChallenge();
        $visitor = $this->sessionCookie();
        $this->assertNotNull($visitor);

        $signedIn = $this->assertJsonResponse(200, $this->post('/auth/passkeys/login', $this->assertion($challenge, 1)));

        $this->assertDocumented('post', '/auth/passkeys/login', 200, $signedIn);
        $this->assertTrue($signedIn['success']);
        $this->assertIsArray($signedIn['user']);
        $this->assertSame($this->reader->user_id, $signedIn['user']['user_id']);

        // A new session id, kept for 30 days; the visitor's id is not promoted.
        $this->assertNotSame($visitor, $this->sessionCookie());
        $this->assertGreaterThan(time() + Session::REMEMBER_ME_LIFETIME - 60, $this->sessionExpiry());
        $this->assertJsonResponse(200, $this->get('/auth/me'));
        $this->useSessionCookie($visitor);
        $this->assertJsonResponse(401, $this->get('/auth/me'));
    }

    public function testSigningInRecordsTheCounterAndWhenThePasskeyAndAccountWereUsed(): void
    {
        $this->createPasskey();
        $this->useSessionCookie(null);

        $signedIn = $this->assertJsonResponse(200, $this->post('/auth/passkeys/login', $this->assertion($this->signInChallenge(), 7)));

        $passkey = $this->rows('SELECT sign_count, last_used_at FROM user_passkeys');
        $this->assertSame(7, $passkey[0]['sign_count']);
        $this->assertIsString($passkey[0]['last_used_at']);
        $lastLogin = $this->rows('SELECT last_login_at FROM users')[0]['last_login_at'];
        $this->assertIsString($lastLogin);
        // The user in the answer is the account after this sign-in, not before it.
        $this->assertIsArray($signedIn['user']);
        $this->assertSame($lastLogin, $signedIn['user']['last_login_at']);
    }

    public function testAReplayedAssertionWhoseCounterDidNotMoveIsRefused(): void
    {
        $this->createPasskey();
        $this->useSessionCookie(null);
        $this->assertJsonResponse(200, $this->post('/auth/passkeys/login', $this->assertion($this->signInChallenge(), 5)));
        $this->useSessionCookie(null);

        // A counter that doesn't move past the stored one suggests a cloned authenticator.
        $this->assertSame(
            ['error' => 'Passkey authentication failed: signature counter not valid'],
            $this->assertJsonResponse(401, $this->post('/auth/passkeys/login', $this->assertion($this->signInChallenge(), 5)))
        );
        $this->assertJsonResponse(401, $this->get('/auth/me'));
    }

    public function testSigningInNeedsTheChallengeFromTheOptionsRequest(): void
    {
        $this->createPasskey();
        $this->useSessionCookie(null);

        $this->assertSame(
            ['error' => 'No pending authentication challenge.'],
            $this->assertJsonResponse(400, $this->post('/auth/passkeys/login', $this->assertion(self::base64Url(random_bytes(32)), 1)))
        );

        $this->signInChallenge();
        $this->assertSame(
            ['error' => 'Passkey authentication failed: invalid challenge'],
            $this->assertJsonResponse(401, $this->post('/auth/passkeys/login', $this->assertion(self::base64Url(random_bytes(32)), 1)))
        );
        $this->assertJsonResponse(401, $this->get('/auth/me'));
    }

    public function testAnAssertionWithMissingPartsIsRefused(): void
    {
        $this->createPasskey();
        $this->useSessionCookie(null);
        $assertion = $this->assertion($this->signInChallenge(), 1);
        unset($assertion['signature']);

        $this->assertSame(
            ['error' => 'Missing assertion data.'],
            $this->assertJsonResponse(400, $this->post('/auth/passkeys/login', $assertion))
        );
    }

    public function testAnUnknownCredentialIsRefused(): void
    {
        $this->useSessionCookie(null);

        $this->assertSame(
            ['error' => 'Unknown passkey credential.'],
            $this->assertJsonResponse(401, $this->post('/auth/passkeys/login', $this->assertion($this->signInChallenge(), 1)))
        );
    }

    public function testASignatureFromAnotherKeyIsRefusedAndSpendsTheChallenge(): void
    {
        $this->createPasskey();
        $this->useSessionCookie(null);
        $challenge = $this->signInChallenge();

        $forged = $this->assertion($challenge, 1, self::newKey());
        $this->assertSame(
            ['error' => 'Passkey authentication failed: invalid signature'],
            $this->assertJsonResponse(401, $this->post('/auth/passkeys/login', $forged))
        );

        $this->assertSame(
            ['error' => 'No pending authentication challenge.'],
            $this->assertJsonResponse(400, $this->post('/auth/passkeys/login', $this->assertion($challenge, 1)))
        );
        $this->assertJsonResponse(401, $this->get('/auth/me'));
    }

    public function testAnUnactivatedAccountCannotSignInWithAPasskey(): void
    {
        $this->createPasskey();
        $this->db->exec('UPDATE users SET is_active = 0');
        $this->useSessionCookie(null);

        $this->assertSame(
            ['error' => 'Your account is not activated.'],
            $this->assertJsonResponse(403, $this->post('/auth/passkeys/login', $this->assertion($this->signInChallenge(), 1)))
        );
        $this->assertJsonResponse(401, $this->get('/auth/me'));
    }

    /** Register this test's authenticator on the reader's account, signed in as them. */
    private function createPasskey(): void
    {
        $this->signInAs($this->reader->user_id);
        $this->assertJsonResponse(201, $this->post('/auth/passkeys/register', $this->attestation($this->registrationChallenge())));
    }

    private function registrationChallenge(): string
    {
        return $this->challengeFrom($this->post('/auth/passkeys/register/options'));
    }

    private function signInChallenge(): string
    {
        return $this->challengeFrom($this->post('/auth/passkeys/login/options'));
    }

    private function challengeFrom(ResponseInterface $options): string
    {
        $publicKey = $this->assertJsonResponse(200, $options)['publicKey'] ?? null;
        $this->assertIsArray($publicKey);
        $this->assertIsString($publicKey['challenge']);
        return $publicKey['challenge'];
    }

    /**
     * What the browser posts after navigator.credentials.create(): "none"
     * attestation over this test's key. $tamper may set 'origin' or 'rpId' to
     * forge the response for another site, and 'name' to name the passkey.
     *
     * @param array<string,string> $tamper
     * @return array<string,string>
     */
    private function attestation(string $challenge, array $tamper = []): array
    {
        $clientData = json_encode([
            'type' => 'webauthn.create', 'challenge' => $challenge, 'origin' => $tamper['origin'] ?? self::ORIGIN,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $point = openssl_pkey_get_details($this->key)['ec'] ?? null;
        $this->assertIsArray($point);
        $coseKey = self::cborMap([
            [self::cborInt(1), self::cborInt(2)],     // kty: EC2
            [self::cborInt(3), self::cborInt(-7)],    // alg: ES256
            [self::cborInt(-1), self::cborInt(1)],    // crv: P-256
            [self::cborInt(-2), self::cborBytes(str_pad((string) $point['x'], 32, "\0", STR_PAD_LEFT))],
            [self::cborInt(-3), self::cborBytes(str_pad((string) $point['y'], 32, "\0", STR_PAD_LEFT))],
        ]);

        $authData = hash('sha256', $tamper['rpId'] ?? self::HOST, true)
            . chr(self::FLAG_USER_PRESENT | self::FLAG_ATTESTED_DATA)
            . pack('N', 0)
            . str_repeat("\0", 16) // AAGUID: none given
            . pack('n', strlen($this->credentialId)) . $this->credentialId
            . $coseKey;

        $attestationObject = self::cborMap([
            [self::cborText('fmt'), self::cborText('none')],
            [self::cborText('attStmt'), self::cborMap([])],
            [self::cborText('authData'), self::cborBytes($authData)],
        ]);

        $response = ['clientDataJSON' => self::base64Url($clientData), 'attestationObject' => self::base64Url($attestationObject)];
        if (isset($tamper['name'])) {
            $response['name'] = $tamper['name'];
        }
        return $response;
    }

    /**
     * What the browser posts after navigator.credentials.get(): the authenticator
     * data and client data, signed by $key (this test's own unless given).
     *
     * @return array<string,string>
     */
    private function assertion(string $challenge, int $signCount, ?OpenSSLAsymmetricKey $key = null): array
    {
        $clientData = json_encode(['type' => 'webauthn.get', 'challenge' => $challenge, 'origin' => self::ORIGIN], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $authData = hash('sha256', self::HOST, true) . chr(self::FLAG_USER_PRESENT) . pack('N', $signCount);

        $this->assertTrue(openssl_sign($authData . hash('sha256', $clientData, true), $signature, $key ?? $this->key, OPENSSL_ALGO_SHA256));
        $this->assertIsString($signature);

        return [
            'id' => self::base64Url($this->credentialId),
            'clientDataJSON' => self::base64Url($clientData),
            'authenticatorData' => self::base64Url($authData),
            'signature' => self::base64Url($signature),
        ];
    }

    /** When the browser's current session row expires (the table keys rows by the id's hash). */
    private function sessionExpiry(): int
    {
        $cookie = $this->sessionCookie();
        $this->assertNotNull($cookie);
        $row = $this->rows("SELECT expires FROM sessions WHERE id = '" . hash('sha256', $cookie) . "'");
        $this->assertCount(1, $row);
        return (int) $row[0]['expires'];
    }

    private static function newKey(): OpenSSLAsymmetricKey
    {
        // An empty config file: on Windows PHP's OpenSSL otherwise looks for an
        // openssl.cnf that usually isn't there and refuses to make a key.
        $config = tempnam(sys_get_temp_dir(), 'tarot-openssl');
        self::assertIsString($config);

        try {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $config]);
        } finally {
            unlink($config);
        }

        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        return $key;
    }

    private static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    // The little CBOR (RFC 8949) an attestation needs: small ints, byte and text
    // strings, and maps of those, all under 64 KiB.

    private static function cborHead(int $majorType, int $argument): string
    {
        return match (true) {
            $argument < 24 => pack('C', $majorType << 5 | $argument),
            $argument < 256 => pack('CC', $majorType << 5 | 24, $argument),
            default => pack('Cn', $majorType << 5 | 25, $argument),
        };
    }

    private static function cborInt(int $value): string
    {
        return $value >= 0 ? self::cborHead(0, $value) : self::cborHead(1, -1 - $value);
    }

    private static function cborBytes(string $bytes): string
    {
        return self::cborHead(2, strlen($bytes)) . $bytes;
    }

    private static function cborText(string $text): string
    {
        return self::cborHead(3, strlen($text)) . $text;
    }

    /** @param list<array{string,string}> $pairs Each an encoded key and its encoded value. */
    private static function cborMap(array $pairs): string
    {
        return self::cborHead(5, count($pairs)) . implode('', array_map(static fn(array $pair): string => $pair[0] . $pair[1], $pairs));
    }
}

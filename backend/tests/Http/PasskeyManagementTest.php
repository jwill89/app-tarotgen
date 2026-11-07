<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\PasskeyController;
use Tarot\Repository\PasskeyRepository;
use Tarot\Repository\UserRepository;
use Tarot\Service\AuthService;
use Tarot\Service\PasskeyDecoys;
use Tarot\Service\SignInMethods;
use Tarot\Structure\User;
use Tarot\Data\UserData;

/**
 * Everything about passkeys short of the WebAuthn ceremonies themselves
 * (PasskeyCeremonyTest): the options a browser is handed, the account page's
 * list/rename/delete, and switching password sign-in off — each only ever on the
 * signed-in user's own account, and never taking away its last way to sign in.
 */
#[CoversClass(PasskeyController::class)]
#[CoversClass(PasskeyRepository::class)]
#[CoversClass(UserRepository::class)]
#[CoversClass(SignInMethods::class)]
#[CoversClass(PasskeyDecoys::class)]
#[CoversClass(UserData::class)]
final class PasskeyManagementTest extends HttpTestCase
{
    private const array SIGNED_OUT = ['error' => 'Not authenticated'];

    private PasskeyRepository $passkeys;

    /** Where the stand-in credential key lives for this test (not beside the developer's database). */
    private string $decoyKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->passkeys = $this->service(PasskeyRepository::class);
        $this->decoyKey = sys_get_temp_dir() . '/tarot-decoy-' . bin2hex(random_bytes(6)) . '.key';
        $this->replaceService(PasskeyDecoys::class, new PasskeyDecoys($this->decoyKey));
    }

    protected function tearDown(): void
    {
        if (is_file($this->decoyKey)) {
            unlink($this->decoyKey);
        }
        parent::tearDown();
    }

    public function testEveryRouteThatTouchesAnAccountNeedsASignedInUser(): void
    {
        $reader = $this->createUser('reader@example.test');
        $passkeyId = $this->addPasskey($reader, 'Laptop');

        $this->assertSame(self::SIGNED_OUT, $this->assertJsonResponse(401, $this->post('/auth/passkeys/register/options')));
        $this->assertSame(self::SIGNED_OUT, $this->assertJsonResponse(401, $this->post('/auth/passkeys/register', ['clientDataJSON' => 'e30', 'attestationObject' => 'oA'])));
        $this->assertSame(self::SIGNED_OUT, $this->assertJsonResponse(401, $this->get('/auth/passkeys')));
        $this->assertSame(self::SIGNED_OUT, $this->assertJsonResponse(401, $this->request('PATCH', "/auth/passkeys/{$passkeyId}", ['name' => 'Mine now'])));
        $this->assertSame(self::SIGNED_OUT, $this->assertJsonResponse(401, $this->request('DELETE', "/auth/passkeys/{$passkeyId}")));
        $this->assertSame(self::SIGNED_OUT, $this->assertJsonResponse(401, $this->request('PATCH', '/auth/passkeys/password-login', ['disable' => true])));

        $this->assertSame([['name' => 'Laptop']], $this->rows('SELECT name FROM user_passkeys'));
        $this->assertSame([['password_login_disabled' => 0]], $this->rows('SELECT password_login_disabled FROM users'));
    }

    public function testAnAccountThatIsNotActiveCannotManagePasskeys(): void
    {
        // A session left behind by an account that was since deactivated (or never
        // activated): UserAuth refuses it, as on every other account route.
        $reader = $this->createUser('reader@example.test', active: false);
        $passkeyId = $this->addPasskey($reader, 'Laptop');
        $this->signInAs($reader->user_id);

        $this->assertSame(self::SIGNED_OUT, $this->assertJsonResponse(401, $this->post('/auth/passkeys/register/options')));
        $this->assertSame(self::SIGNED_OUT, $this->assertJsonResponse(401, $this->post('/auth/passkeys/register', ['clientDataJSON' => 'e30', 'attestationObject' => 'oA'])));
        $this->assertSame(self::SIGNED_OUT, $this->assertJsonResponse(401, $this->get('/auth/passkeys')));
        $this->assertSame(self::SIGNED_OUT, $this->assertJsonResponse(401, $this->request('PATCH', "/auth/passkeys/{$passkeyId}", ['name' => 'Renamed'])));
        $this->assertSame(self::SIGNED_OUT, $this->assertJsonResponse(401, $this->request('DELETE', "/auth/passkeys/{$passkeyId}")));
        $this->assertSame(self::SIGNED_OUT, $this->assertJsonResponse(401, $this->request('PATCH', '/auth/passkeys/password-login', ['disable' => true])));

        $this->assertSame([['name' => 'Laptop']], $this->rows('SELECT name FROM user_passkeys'));
        $this->assertSame([['password_login_disabled' => 0]], $this->rows('SELECT password_login_disabled FROM users'));
    }

    public function testTheListHoldsOnlyYourOwnPasskeysAndNoKeyMaterial(): void
    {
        $reader = $this->createUser('reader@example.test');
        $other = $this->createUser('other@example.test');
        $this->addPasskey($reader, 'Laptop');
        $this->addPasskey($other, 'Phone');
        $this->signInAs($reader->user_id);

        $list = $this->assertJsonResponse(200, $this->get('/auth/passkeys'));

        $this->assertDocumented('get', '/auth/passkeys', 200, $list);
        $this->assertCount(1, $list);
        $this->assertIsArray($list[0]);
        $this->assertSame('Laptop', $list[0]['name']);
        $this->assertSame(self::credentialId('Laptop'), $list[0]['credential_id']);
        $this->assertNull($list[0]['last_used_at']);
        $this->assertStringNotContainsString('PUBLIC KEY', json_encode($list, JSON_THROW_ON_ERROR));
    }

    public function testYouCanRenameYourOwnPasskeyButNotSomeoneElses(): void
    {
        $reader = $this->createUser('reader@example.test');
        $other = $this->createUser('other@example.test');
        $mine = $this->addPasskey($reader, 'Laptop');
        $theirs = $this->addPasskey($other, 'Phone');
        $this->signInAs($reader->user_id);

        $this->assertSame(
            ['error' => 'Passkey not found.'],
            $this->assertJsonResponse(404, $this->request('PATCH', "/auth/passkeys/{$theirs}", ['name' => 'Mine now']))
        );
        $renamed = $this->assertJsonResponse(200, $this->request('PATCH', "/auth/passkeys/{$mine}", ['name' => '  Work laptop  ']));
        $this->assertDocumented('patch', '/auth/passkeys/{id}', 200, $renamed);
        $this->assertSame(['success' => true], $renamed);

        $this->assertSame(
            [['user_id' => $reader->user_id, 'name' => 'Work laptop'], ['user_id' => $other->user_id, 'name' => 'Phone']],
            $this->rows('SELECT user_id, name FROM user_passkeys ORDER BY passkey_id')
        );
    }

    public function testANewNameIsRequiredAndCappedAtFiftyCharacters(): void
    {
        $reader = $this->createUser('reader@example.test');
        $mine = $this->addPasskey($reader, 'Laptop');
        $this->signInAs($reader->user_id);

        $this->assertSame(
            ['error' => 'Name is required.'],
            $this->assertJsonResponse(422, $this->request('PATCH', "/auth/passkeys/{$mine}", ['name' => '   ']))
        );
        $this->assertJsonResponse(200, $this->request('PATCH', "/auth/passkeys/{$mine}", ['name' => str_repeat('k', 60)]));

        $this->assertSame([['name' => str_repeat('k', 50)]], $this->rows('SELECT name FROM user_passkeys'));
    }

    public function testYouCanDeleteYourOwnPasskeyButNotSomeoneElses(): void
    {
        $reader = $this->createUser('reader@example.test');
        $other = $this->createUser('other@example.test');
        $mine = $this->addPasskey($reader, 'Laptop');
        $theirs = $this->addPasskey($other, 'Phone');
        $this->signInAs($reader->user_id);

        $this->assertSame(['error' => 'Passkey not found.'], $this->assertJsonResponse(404, $this->request('DELETE', "/auth/passkeys/{$theirs}")));
        $this->assertSame(['error' => 'Passkey not found.'], $this->assertJsonResponse(404, $this->request('DELETE', '/auth/passkeys/999')));

        $deleted = $this->request('DELETE', "/auth/passkeys/{$mine}");
        $this->assertSame(204, $deleted->getStatusCode());
        $this->assertSame('', (string) $deleted->getBody());

        $this->assertSame([['name' => 'Phone']], $this->rows('SELECT name FROM user_passkeys'));
    }

    public function testSomeoneElsesPasskeyIsNotFoundEvenWithPasswordSignInOff(): void
    {
        $reader = $this->createUser('reader@example.test');
        $other = $this->createUser('other@example.test');
        $this->addPasskey($reader, 'Laptop');
        $theirs = $this->addPasskey($other, 'Phone');
        $this->service(UserRepository::class)->setPasswordLoginDisabled($reader->user_id, true);
        $this->signInAs($reader->user_id);

        // Not "your last passkey": the passkey isn't this account's at all.
        $this->assertSame(['error' => 'Passkey not found.'], $this->assertJsonResponse(404, $this->request('DELETE', "/auth/passkeys/{$theirs}")));
        $this->assertSame(2, $this->rowCount('user_passkeys'));
    }

    public function testPasswordSignInCannotBeSwitchedOffWithoutAnotherWayIn(): void
    {
        $reader = $this->createUser('reader@example.test');
        $this->signInAs($reader->user_id);

        $this->assertSame(
            ['error' => 'That would leave you no way to sign in. First add a passkey or link your Google account.'],
            $this->assertJsonResponse(409, $this->request('PATCH', '/auth/passkeys/password-login', ['disable' => true]))
        );
        $this->assertSame([['password_login_disabled' => 0]], $this->rows('SELECT password_login_disabled FROM users'));
    }

    public function testAGoogleLinkIsEnoughToSwitchPasswordSignInOff(): void
    {
        $reader = $this->createUser('reader@example.test');
        $this->service(UserRepository::class)->setGoogleId($reader->user_id, 'google-sub-1');
        $this->signInAs($reader->user_id);

        $off = $this->assertJsonResponse(200, $this->request('PATCH', '/auth/passkeys/password-login', ['disable' => true]));

        $this->assertTrue($off['password_login_disabled']);
        $this->assertSame([['password_login_disabled' => 1]], $this->rows('SELECT password_login_disabled FROM users'));
    }

    public function testWithAPasskeyPasswordSignInCanBeSwitchedOffAndOnAgain(): void
    {
        $password = 'test-only-' . bin2hex(random_bytes(8));
        $reader = $this->createUser('reader@example.test');
        $this->service(UserRepository::class)->updatePasswordHash($reader->user_id, password_hash($password, AuthService::passwordAlgo()));
        $this->addPasskey($reader, 'Laptop');
        $this->signInAs($reader->user_id);

        $off = $this->assertJsonResponse(200, $this->request('PATCH', '/auth/passkeys/password-login', ['disable' => true]));
        $this->assertDocumented('patch', '/auth/passkeys/password-login', 200, $off);
        $this->assertTrue($off['password_login_disabled']);
        $this->assertIsArray($off['user']);
        $this->assertTrue($off['user']['password_login_disabled']);
        $this->assertTrue($off['user']['has_passkeys']);

        $signIn = ['email' => 'reader@example.test', 'password' => $password];
        $this->assertJsonResponse(403, $this->post('/auth/login', $signIn));

        $on = $this->assertJsonResponse(200, $this->request('PATCH', '/auth/passkeys/password-login', ['disable' => false]));
        $this->assertFalse($on['password_login_disabled']);
        $this->assertJsonResponse(200, $this->post('/auth/login', $signIn));
    }

    public function testTheLastPasskeyStaysWhilePasswordSignInIsOff(): void
    {
        $reader = $this->createUser('reader@example.test');
        $laptop = $this->addPasskey($reader, 'Laptop');
        $phone = $this->addPasskey($reader, 'Phone');
        $this->signInAs($reader->user_id);
        $this->assertJsonResponse(200, $this->request('PATCH', '/auth/passkeys/password-login', ['disable' => true]));

        $this->assertSame(204, $this->request('DELETE', "/auth/passkeys/{$laptop}")->getStatusCode());
        $this->assertSame(
            ['error' => 'That would leave you no way to sign in. First turn password login back on or link your Google account.'],
            $this->assertJsonResponse(409, $this->request('DELETE', "/auth/passkeys/{$phone}"))
        );
        $this->assertSame(1, $this->rowCount('user_passkeys'));

        $this->assertJsonResponse(200, $this->request('PATCH', '/auth/passkeys/password-login', ['disable' => false]));
        $this->assertSame(204, $this->request('DELETE', "/auth/passkeys/{$phone}")->getStatusCode());
    }

    public function testWithGoogleLinkedTheLastPasskeyCanGoEvenWithPasswordSignInOff(): void
    {
        $reader = $this->createUser('reader@example.test');
        $phone = $this->addPasskey($reader, 'Phone');
        $users = $this->service(UserRepository::class);
        $users->setPasswordLoginDisabled($reader->user_id, true);
        $users->setGoogleId($reader->user_id, 'google-sub-1');
        $this->signInAs($reader->user_id);

        $this->assertSame(204, $this->request('DELETE', "/auth/passkeys/{$phone}")->getStatusCode());
        $this->assertSame(0, $this->rowCount('user_passkeys'));
    }

    public function testAnAccountWithNoPasswordKeepsItsLastPasskey(): void
    {
        $reader = $this->createUser('reader@example.test');
        $phone = $this->addPasskey($reader, 'Phone');
        // An account made through Google, since unlinked: the passkey is all it has.
        $this->db->exec("UPDATE users SET password_hash = ''");
        $this->signInAs($reader->user_id);

        $this->assertSame(
            ['error' => 'That would leave you no way to sign in. First set a password or link your Google account.'],
            $this->assertJsonResponse(409, $this->request('DELETE', "/auth/passkeys/{$phone}"))
        );
        $this->assertSame(1, $this->rowCount('user_passkeys'));
    }

    public function testRegistrationOptionsNameTheSiteAndTheUserAndExcludeTheirPasskeys(): void
    {
        $this->setEnv('APP_URL', 'https://' . self::HOST);
        $reader = $this->createUser('reader@example.test');
        $this->addPasskey($reader, 'Laptop');
        $this->signInAs($reader->user_id);

        $body = $this->assertJsonResponse(200, $this->post('/auth/passkeys/register/options'));

        $this->assertDocumented('post', '/auth/passkeys/register/options', 200, $body);
        $options = $this->publicKey($body);
        $this->assertSame(['name' => 'Tarot Generator', 'id' => self::HOST], $options['rp']);
        $this->assertIsArray($options['user']);
        $this->assertSame('reader@example.test', $options['user']['name']);
        $this->assertSame('reader', $options['user']['displayName']);
        $this->assertIsString($options['challenge']);
        $this->assertGreaterThanOrEqual(16, strlen($this->base64UrlDecode($options['challenge'])));
        $this->assertIsArray($options['excludeCredentials']);
        $this->assertCount(1, $options['excludeCredentials']);
        $this->assertIsArray($options['excludeCredentials'][0]);
        $this->assertSame(self::credentialId('Laptop'), $options['excludeCredentials'][0]['id']);
    }

    public function testEachOptionsRequestIssuesAFreshChallenge(): void
    {
        $first = $this->publicKey($this->assertJsonResponse(200, $this->post('/auth/passkeys/login/options')));
        $second = $this->publicKey($this->assertJsonResponse(200, $this->post('/auth/passkeys/login/options')));

        $this->assertNotSame($first['challenge'], $second['challenge']);
    }

    public function testRegistrationOptionsForADeletedAccountAreRefused(): void
    {
        $reader = $this->createUser('reader@example.test');
        $this->signInAs($reader->user_id);
        $this->service(UserRepository::class)->delete($reader->user_id);

        $this->assertSame(self::SIGNED_OUT, $this->assertJsonResponse(401, $this->post('/auth/passkeys/register/options')));
    }

    public function testAnEmailScopesSignInOptionsToThatAccountsPasskeys(): void
    {
        $this->setEnv('APP_URL', 'https://' . self::HOST);
        $reader = $this->createUser('reader@example.test');
        $this->addPasskey($reader, 'Laptop');

        $body = $this->assertJsonResponse(200, $this->post('/auth/passkeys/login/options', ['email' => ' Reader@Example.test ']));

        $this->assertDocumented('post', '/auth/passkeys/login/options', 200, $body);
        $options = $this->publicKey($body);
        $this->assertSame(self::HOST, $options['rpId']);
        $this->assertIsArray($options['allowCredentials']);
        $this->assertCount(1, $options['allowCredentials']);
        $this->assertIsArray($options['allowCredentials'][0]);
        $this->assertSame(self::credentialId('Laptop'), $options['allowCredentials'][0]['id']);
    }

    public function testAnEmailWithoutPasskeysGetsStandInsThatLookLikeAnAccountsOwn(): void
    {
        $withPasskey = $this->createUser('reader@example.test');
        $this->addPasskey($withPasskey, 'Laptop');
        $this->createUser('nopasskeys@example.test');

        $real = $this->allowedCredentials('reader@example.test');
        $unknown = $this->allowedCredentials('nobody@example.test');
        $noPasskeys = $this->allowedCredentials('nopasskeys@example.test');

        // Each lists credentials of the same form: base64url ids a real
        // authenticator could have made, with the same transports.
        foreach ([$unknown, $noPasskeys] as $standIns) {
            $this->assertNotSame([], $standIns);
            $this->assertNotSame($real, $standIns);
            foreach ($standIns as $credential) {
                $this->assertIsArray($credential);
                $this->assertSame(array_keys($real[0]), array_keys($credential));
                $this->assertIsArray($real[0]);
                $this->assertSame($real[0]['transports'], $credential['transports']);
                $this->assertIsString($credential['id']);
                $this->assertContains(strlen($this->base64UrlDecode($credential['id'])), [16, 20, 32, 64]);
            }
        }
        $this->assertNotSame($unknown, $noPasskeys);

        // And they stay the same, as a real account's do, whatever the case or spacing.
        $this->assertSame($unknown, $this->allowedCredentials(' NOBODY@example.test'));
        $this->assertSame($noPasskeys, $this->allowedCredentials('nopasskeys@example.test'));
        $this->assertSame($real, $this->allowedCredentials('reader@example.test'));
    }

    public function testWithoutAnEmailAnyDiscoverablePasskeyMayAnswer(): void
    {
        $reader = $this->createUser('reader@example.test');
        $this->addPasskey($reader, 'Laptop');

        $anyone = $this->publicKey($this->assertJsonResponse(200, $this->post('/auth/passkeys/login/options')));

        $this->assertArrayNotHasKey('allowCredentials', $anyone);
        $this->assertFileDoesNotExist($this->decoyKey, 'no stand-ins needed');
    }

    public function testTheStandInsKeyIsMadeOnFirstUse(): void
    {
        $this->assertFileDoesNotExist($this->decoyKey);

        $this->allowedCredentials('nobody@example.test');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) file_get_contents($this->decoyKey));
    }

    /**
     * The credentials sign-in options for $email name.
     *
     * @return list<mixed>
     */
    private function allowedCredentials(string $email): array
    {
        $body = $this->assertJsonResponse(200, $this->post('/auth/passkeys/login/options', ['email' => $email]));
        $this->assertDocumented('post', '/auth/passkeys/login/options', 200, $body);
        $allowed = $this->publicKey($body)['allowCredentials'] ?? null;
        $this->assertIsArray($allowed);
        $this->assertTrue(array_is_list($allowed));

        return $allowed;
    }

    /** Store a passkey for $user directly (a placeholder key: no ceremony uses it). */
    private function addPasskey(User $user, string $name): int
    {
        return $this->passkeys->create($user->user_id, self::credentialId($name), "-----BEGIN PUBLIC KEY-----\n-----END PUBLIC KEY-----\n", $name, 0);
    }

    /** The credential id addPasskey() gives $name: base64url, as the browser sends it. */
    private static function credentialId(string $name): string
    {
        return rtrim(strtr(base64_encode("credential for {$name}"), '+/', '-_'), '=');
    }

    /**
     * @param array<mixed> $options
     * @return array<mixed>
     */
    private function publicKey(array $options): array
    {
        $this->assertIsArray($options['publicKey']);
        return $options['publicKey'];
    }

    private function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}

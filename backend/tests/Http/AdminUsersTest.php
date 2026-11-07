<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\AdminUserController;
use Tarot\Data\ContactData;
use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;
use Tarot\Data\FavoriteDeckData;
use Tarot\Data\FavoriteSpreadData;
use Tarot\Data\PasskeyData;
use Tarot\Data\PendingSpreadData;
use Tarot\Data\UserSpreadData;
use Tarot\Service\PluginTokenService;
use Tarot\Structure\User;
use Tarot\Service\AccountProtection;
use Tarot\Service\AuthService;

/**
 * Account administration: the user list, activating an account by hand or
 * re-sending its activation link, the admin flag, and deleting an account
 * (with what goes and what stays of the things it made).
 */
#[CoversClass(AdminUserController::class)]
#[CoversClass(AccountProtection::class)]
#[CoversClass(AuthService::class)]
final class AdminUsersTest extends HttpTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createUser('admin@example.test', admin: true);
        $this->signInAs($this->admin->user_id);
    }

    public function testTheUserListShowsEveryAccountNewestFirst(): void
    {
        $this->createUser('first@example.test');
        $this->createUser('second@example.test', active: false);

        $users = $this->assertJsonResponse(200, $this->get('/admin/users'));

        $this->assertDocumented('get', '/admin/users', 200, $users);
        $this->assertSame(['second@example.test', 'first@example.test', 'admin@example.test'], array_column($users, 'email'));
        $this->assertSame([false, true, true], array_column($users, 'is_active'));
    }

    public function testActivatingAnAccountByHand(): void
    {
        $pending = $this->createUser('pending@example.test', active: false);

        $response = $this->post("/admin/users/{$pending->user_id}/activate");

        $body = $this->assertJsonResponse(200, $response);
        $this->assertDocumented('post', '/admin/users/{user_id}/activate', 200, $body);
        $this->assertSame(['success' => true], $body);
        $this->assertSame([['is_active' => 1]], $this->rows("SELECT is_active FROM users WHERE email = 'pending@example.test'"));
    }

    public function testReSendingActivationHandsBackAWorkingLinkWhileEmailIsOff(): void
    {
        $pending = $this->createUser('pending@example.test', active: false);

        $this->assertJsonResponse(200, $this->post("/admin/users/{$pending->user_id}/resend-activation"));
        $body = $this->assertJsonResponse(200, $this->post("/admin/users/{$pending->user_id}/resend-activation"));

        $this->assertDocumented('post', '/admin/users/{user_id}/resend-activation', 200, $body);
        $this->assertSame(['success', 'emailed', 'activation_link'], array_keys($body));
        $this->assertSame([true, false], [$body['success'], $body['emailed']]);
        $this->assertIsString($body['activation_link']);
        $this->assertMatchesRegularExpression('#^https://tarotgen\.io/activate\?token=[0-9a-f]{64}$#', $body['activation_link']);
        // Each resend replaces the outstanding link rather than adding another.
        $this->assertSame(1, $this->rowCount('user_tokens'));

        parse_str((string) parse_url($body['activation_link'], PHP_URL_QUERY), $query);
        $this->useSessionCookie(null);
        $this->assertJsonResponse(200, $this->post('/auth/activate', ['token' => $query['token'] ?? '']));
        $this->assertSame([['is_active' => 1]], $this->rows("SELECT is_active FROM users WHERE email = 'pending@example.test'"));
    }

    public function testInProductionTheActivationLinkStaysInTheEmail(): void
    {
        $this->setEnv('APP_ENV', 'production');
        $pending = $this->createUser('pending@example.test', active: false);

        $body = $this->assertJsonResponse(200, $this->post("/admin/users/{$pending->user_id}/resend-activation"));

        $this->assertDocumented('post', '/admin/users/{user_id}/resend-activation', 200, $body);
        $this->assertSame(['success' => true, 'emailed' => false], $body);
    }

    public function testActivationIsOnlyResentToAnAccountWaitingForIt(): void
    {
        $active = $this->createUser('active@example.test');

        $this->assertSame(
            ['error' => 'That account is already active.'],
            $this->assertJsonResponse(400, $this->post("/admin/users/{$active->user_id}/resend-activation"))
        );
        $this->assertSame(['error' => 'User not found.'], $this->assertJsonResponse(400, $this->post('/admin/users/999/resend-activation')));
        $this->assertSame(0, $this->rowCount('user_tokens'));
    }

    public function testGrantingAdminOpensTheAdminPagesToThatAccount(): void
    {
        $helper = $this->createUser('helper@example.test');

        $granted = $this->assertJsonResponse(200, $this->request('PATCH', "/admin/users/{$helper->user_id}", ['is_admin' => true]));

        $this->assertDocumented('patch', '/admin/users/{user_id}', 200, $granted);
        $this->assertSame(['helper@example.test', true], [$granted['email'], $granted['is_admin']]);
        $this->signInAs($helper->user_id);
        $this->assertJsonResponse(200, $this->get('/admin/users'));
    }

    public function testRevokingAdminClosesThemAgain(): void
    {
        $helper = $this->createUser('helper@example.test', admin: true);

        $revoked = $this->assertJsonResponse(200, $this->request('PATCH', "/admin/users/{$helper->user_id}", ['is_admin' => false]));

        $this->assertFalse($revoked['is_admin']);
        $this->signInAs($helper->user_id);
        $this->assertJsonResponse(401, $this->get('/admin/users'));
    }

    public function testAnUpdateWithoutTheAdminFlagChangesNothing(): void
    {
        $helper = $this->createUser('helper@example.test');

        $same = $this->assertJsonResponse(200, $this->request('PATCH', "/admin/users/{$helper->user_id}", ['email' => 'new@example.test', 'is_active' => false]));

        $this->assertSame(['helper@example.test', true, false], [$same['email'], $same['is_active'], $same['is_admin']]);
    }

    public function testAnAccountThatDoesNotExistIsNotFound(): void
    {
        $notFound = ['error' => 'User not found'];

        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->post('/admin/users/999/activate')));
        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->post('/admin/users/0/activate')));
        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->request('PATCH', '/admin/users/999', ['is_admin' => true])));
        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->request('DELETE', '/admin/users/999')));
        $this->assertSame(1, $this->rowCount('users'));
    }

    public function testDeletingAnAccountTakesItsOwnDataAndKeepsWhatItSentTheSite(): void
    {
        $leaving = $this->createUser('leaving@example.test');
        $staying = $this->createUser('staying@example.test');
        $id = $leaving->user_id;

        $system = $this->service(DeckSystemData::class)->store(['name' => 'Lenormand', 'short_name' => 'LEN', 'submitted_by' => $id]);
        $this->assertNotNull($system);
        $deck = $this->service(DeckData::class)->store(['name' => 'Petit', 'deck_system_id' => $system->deck_system_id, 'submitted_by' => $id]);
        $this->assertNotNull($deck);
        $insert = $this->db->prepare('INSERT INTO readings (reading_id, reading_info, user_id) VALUES (?, ?, ?)');
        $insert->execute(['leaving001', '{"deck_id":1}', $id]);
        $insert->execute(['staying001', '{"deck_id":1}', $staying->user_id]);
        $this->service(UserSpreadData::class)->store($id, ['name' => 'Mine', 'positions' => []]);
        $this->service(FavoriteSpreadData::class)->add($id, 'public', 1);
        $this->service(FavoriteDeckData::class)->add($id, $deck->deck_id);
        $this->service(PasskeyData::class)->create($id, 'cred-1', '-----PEM-----', 'Laptop', 0);
        $this->service(PluginTokenService::class)->issueToken($id);
        $this->service(ContactData::class)->store(['user_id' => $id, 'name' => 'Typed Name', 'email' => 'typed@example.test', 'message' => 'Hello']);
        $this->service(PendingSpreadData::class)->store(['name' => 'Crossroads'], $id);

        $this->assertSame(204, $this->request('DELETE', "/admin/users/{$id}")->getStatusCode());

        $this->assertSame(['admin@example.test', 'staying@example.test'], array_column($this->rows('SELECT email FROM users ORDER BY email'), 'email'));
        $this->assertSame(['staying001'], array_column($this->rows('SELECT reading_id FROM readings'), 'reading_id'));
        foreach (['user_spreads', 'user_favorite_spreads', 'user_favorite_decks', 'user_passkeys', 'plugin_tokens'] as $table) {
            $this->assertSame(0, $this->rowCount($table), "{$table} should go with the account");
        }

        // What they sent the site stays, credited to what they typed or to a guest.
        $contacts = $this->assertJsonResponse(200, $this->get('/admin/contacts'));
        $this->assertSame([[null, 'Typed Name', 'typed@example.test', 'Hello']], array_map(
            static fn(mixed $c): array => is_array($c) ? [$c['user_id'], $c['name'], $c['email'], $c['message']] : [],
            $contacts
        ));
        $queue = $this->assertJsonResponse(200, $this->get('/admin/pending-spreads'));
        $this->assertSame([['Crossroads', 'Guest', null]], array_map(
            static fn(mixed $p): array => is_array($p) ? [$p['name'], $p['submitter'], $p['user_id']] : [],
            $queue
        ));
        // submitted_by has no foreign key, so decks and systems keep naming an
        // account that no longer exists (reported, not changed here).
        $this->assertSame([['submitted_by' => $id]], $this->rows('SELECT submitted_by FROM decks'));
        $this->assertSame([['submitted_by' => $id]], $this->rows('SELECT submitted_by FROM deck_systems'));
    }

    public function testAnAdminCannotDemoteOrDeleteThemselves(): void
    {
        $self = $this->admin->user_id;

        $this->assertSame(
            ['error' => "You can't remove your own admin access. Another admin can."],
            $this->assertJsonResponse(403, $this->request('PATCH', "/admin/users/{$self}", ['is_admin' => false]))
        );
        $this->assertSame(
            ['error' => "You can't delete your own account here. Use Account Settings to delete it."],
            $this->assertJsonResponse(403, $this->request('DELETE', "/admin/users/{$self}"))
        );
        $this->assertSame([['is_admin' => 1]], $this->rows("SELECT is_admin FROM users WHERE user_id = {$self}"));
        $this->assertJsonResponse(200, $this->get('/admin/users'));

        // Keeping the flag changes nothing, so that's fine; and another admin may do either.
        $this->assertJsonResponse(200, $this->request('PATCH', "/admin/users/{$self}", ['is_admin' => true]));
        $this->signInAs($this->createUser('other@example.test', admin: true)->user_id);
        $this->assertJsonResponse(200, $this->request('PATCH', "/admin/users/{$self}", ['is_admin' => false]));
        $this->assertSame(204, $this->request('DELETE', "/admin/users/{$self}")->getStatusCode());
    }

    public function testAProtectedAccountCannotBeDemotedOrDeletedByAnyAdmin(): void
    {
        $this->setEnv('PROTECTED_ADMIN_EMAILS', ' founder@example.test ,OWNER@Example.TEST,');
        $owner = $this->createUser('owner@example.test', admin: true);

        $this->assertSame(
            ['error' => "This account is protected: its admin access can't be removed."],
            $this->assertJsonResponse(403, $this->request('PATCH', "/admin/users/{$owner->user_id}", ['is_admin' => false]))
        );
        $this->assertSame(
            ['error' => "This account is protected and can't be deleted."],
            $this->assertJsonResponse(403, $this->request('DELETE', "/admin/users/{$owner->user_id}"))
        );
        $this->assertSame([['is_admin' => 1]], $this->rows("SELECT is_admin FROM users WHERE user_id = {$owner->user_id}"));

        // Granting admin to a protected account is no loss, so it's allowed.
        $founder = $this->createUser('founder@example.test');
        $granted = $this->assertJsonResponse(200, $this->request('PATCH', "/admin/users/{$founder->user_id}", ['is_admin' => true]));
        $this->assertSame([true, true], [$granted['is_admin'], $granted['is_protected']]);
    }

    public function testTheUserListSaysWhichAccountsAreProtected(): void
    {
        $this->setEnv('PROTECTED_ADMIN_EMAILS', 'Owner@example.test');
        $this->createUser('owner@example.test', admin: true);
        $this->createUser('reader@example.test');

        $users = $this->assertJsonResponse(200, $this->get('/admin/users'));

        $this->assertDocumented('get', '/admin/users', 200, $users);
        $this->assertSame(
            ['reader@example.test' => false, 'owner@example.test' => true, 'admin@example.test' => false],
            array_column($users, 'is_protected', 'email')
        );
    }

    public function testDeletingAnAccountSignsItOutEverywhere(): void
    {
        $adminBrowser = $this->sessionCookie();
        $leaving = $this->createUser('leaving@example.test');
        $this->useSessionCookie(null);
        $this->signInAs($leaving->user_id);
        $theirBrowser = $this->sessionCookie();
        $this->useSessionCookie($adminBrowser);

        $this->assertSame(204, $this->request('DELETE', "/admin/users/{$leaving->user_id}")->getStatusCode());

        $this->assertSame([['user_id' => $this->admin->user_id]], $this->rows('SELECT user_id FROM sessions'));
        $this->useSessionCookie($theirBrowser);
        $this->assertJsonResponse(401, $this->get('/auth/me'));
    }
}

<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\AdminContactController;
use Tarot\Data\ContactData;
use Tarot\Repository\ContactRepository;

/**
 * The contact-form inbox: unread messages by default, everything on request,
 * and the read flag an admin toggles.
 */
#[CoversClass(AdminContactController::class)]
#[CoversClass(ContactRepository::class)]
#[CoversClass(ContactData::class)]
final class AdminContactsTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);
    }

    public function testTheInboxShowsUnreadMessagesNewestFirst(): void
    {
        $this->message('Old', '2026-01-01 09:00:00');
        $this->message('New', '2026-01-03 09:00:00');
        $this->message('Handled', '2026-01-02 09:00:00', read: true);

        $unread = $this->assertJsonResponse(200, $this->get('/admin/contacts'));
        $this->assertDocumented('get', '/admin/contacts', 200, $unread);
        $this->assertSame(['New', 'Old'], array_column($unread, 'message'));

        $all = $this->assertJsonResponse(200, $this->get('/admin/contacts?show_read=1'));
        $this->assertSame(['New', 'Handled', 'Old'], array_column($all, 'message'));
        $this->assertSame([0, 1, 0], array_column($all, 'is_read'));
    }

    public function testASignedInSenderIsShownByTheirAccount(): void
    {
        $sender = $this->createUser('morgan@example.test');
        $this->service(ContactData::class)->store([
            'user_id' => $sender->user_id, 'name' => 'Typed Name', 'email' => 'typed@example.test', 'message' => 'Hello',
        ]);

        $inbox = $this->assertJsonResponse(200, $this->get('/admin/contacts'));

        $this->assertIsArray($inbox[0]);
        $this->assertSame(
            [$sender->user_id, 'morgan', 'morgan@example.test'],
            [$inbox[0]['user_id'], $inbox[0]['name'], $inbox[0]['email']]
        );
    }

    public function testMarkingAMessageReadFilesItAwayUntilItIsMarkedUnread(): void
    {
        $id = $this->message('Hello', '2026-01-01 09:00:00');

        $body = $this->assertJsonResponse(200, $this->request('PATCH', "/admin/contacts/{$id}", ['is_read' => true]));

        $this->assertDocumented('patch', '/admin/contacts/{contact_id}', 200, $body);
        $this->assertSame(['success' => true], $body);
        $whileRead = $this->assertJsonResponse(200, $this->get('/admin/contacts'));
        $this->assertSame([], $whileRead);

        $this->assertJsonResponse(200, $this->request('PATCH', "/admin/contacts/{$id}", ['is_read' => false]));
        $unreadAgain = $this->assertJsonResponse(200, $this->get('/admin/contacts'));
        $this->assertSame(['Hello'], array_column($unreadAgain, 'message'));
    }

    public function testAnUpdateWithoutTheReadFlagChangesNothing(): void
    {
        $id = $this->message('Hello', '2026-01-01 09:00:00', read: true);

        $this->assertSame(['success' => true], $this->assertJsonResponse(200, $this->request('PATCH', "/admin/contacts/{$id}", ['message' => 'Edited'])));

        $this->assertSame([['message' => 'Hello', 'is_read' => 1]], $this->rows('SELECT message, is_read FROM contacts'));
    }

    public function testAContactIdThatIsNotAPositiveNumberIsRefused(): void
    {
        $invalid = ['error' => 'Invalid contact ID'];

        $this->assertSame($invalid, $this->assertJsonResponse(400, $this->request('PATCH', '/admin/contacts/0', ['is_read' => true])));
        $this->assertSame($invalid, $this->assertJsonResponse(400, $this->request('PATCH', '/admin/contacts/latest', ['is_read' => true])));
    }

    public function testAMessageThatDoesNotExistIsNotFound(): void
    {
        $this->message('Hello', '2026-01-01 09:00:00');
        $notFound = ['error' => 'Contact not found'];

        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->request('PATCH', '/admin/contacts/999', ['is_read' => true])));
        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->request('PATCH', '/admin/contacts/999', [])));
        $this->assertSame([['is_read' => 0]], $this->rows('SELECT is_read FROM contacts'));
    }

    public function testTheInboxNeedsAnAdmin(): void
    {
        $id = $this->message('Hello', '2026-01-01 09:00:00');
        $this->signInAs($this->createUser('reader@example.test')->user_id);

        $this->assertJsonResponse(401, $this->get('/admin/contacts'));
        $this->assertJsonResponse(401, $this->request('PATCH', "/admin/contacts/{$id}", ['is_read' => true]));
        $this->assertSame([['is_read' => 0]], $this->rows('SELECT is_read FROM contacts'));
    }

    private function message(string $text, string $submittedAt, bool $read = false): int
    {
        $contact = $this->service(ContactData::class)->store(['name' => 'Visitor', 'email' => 'visitor@example.test', 'message' => $text]);
        $this->assertNotNull($contact);
        $this->db->prepare('UPDATE contacts SET submitted_at = ?, is_read = ? WHERE contact_id = ?')
            ->execute([$submittedAt, (int) $read, $contact->contact_id]);

        return $contact->contact_id;
    }
}

<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\ReadingController;
use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;
use Tarot\Repository\ReadingRepository;

/**
 * Who may see a reading through its link: anyone, unless the owner set a view
 * password; then only the owner, and visitors who unlock it, for as long as
 * their session lasts. Also what a locked reading gives away (its name, nothing
 * else) and the limits on reads and password guesses.
 */
#[CoversClass(ReadingController::class)]
#[CoversClass(ReadingRepository::class)]
final class ReadingAccessTest extends HttpTestCase
{
    private const string PASSWORD = 'open sesame';

    private int $deckId;

    protected function setUp(): void
    {
        parent::setUp();

        $system = $this->service(DeckSystemData::class)->store(['name' => 'Rider-Waite-Smith', 'short_name' => 'RWS']);
        $this->assertNotNull($system);
        $deck = $this->service(DeckData::class)->store(['name' => 'Test Deck', 'deck_system_id' => $system->deck_system_id]);
        $this->assertNotNull($deck);
        $this->deckId = $deck->deck_id;
    }

    public function testAnUnknownReadingIs404(): void
    {
        $this->assertSame('InvalidReadingID', $this->assertProblem(404, $this->get('/readings/0000000000')));
        $this->assertSame(
            'InvalidReadingID',
            $this->assertProblem(404, $this->post('/readings/0000000000/unlock', ['password' => self::PASSWORD]))
        );
    }

    public function testAGuestReadingIsOpenToAnyoneAndCreditedToGuest(): void
    {
        $id = $this->generate();

        $this->useSessionCookie(null);
        $response = $this->get("/readings/{$id}");
        $viewed = $this->assertJsonResponse(200, $response);

        $this->assertSame('Guest', $viewed['reader']);
        $this->assertFalse($viewed['is_owner']);
        $this->assertFalse($viewed['can_draw_more']);
        $this->assertIsArray($viewed['reading_info']);
        $this->assertSame($this->deckId, $viewed['reading_info']['deck_id']);
        // What a viewer is shown depends on their session, so no shared cache may keep it.
        $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testTheOwnersDisplayNameIsShownUnlessTheyHideIt(): void
    {
        $owner = $this->createUser('morgan@example.test');
        $this->signInAs($owner->user_id);
        $credited = $this->generate();
        $hidden = $this->generate(['hide_user' => true]);

        $this->useSessionCookie(null);

        $this->assertSame('morgan', $this->assertJsonResponse(200, $this->get("/readings/{$credited}"))['reader']);
        $this->assertSame('Guest', $this->assertJsonResponse(200, $this->get("/readings/{$hidden}"))['reader']);
    }

    public function testALockedReadingGivesAwayOnlyItsName(): void
    {
        $id = $this->protectedReading();

        // Not just signed-out visitors: another account is no more entitled to it.
        $this->signInAs($this->createUser('stranger@example.test')->user_id);
        $response = $this->get("/readings/{$id}");

        $this->assertSame(['locked' => true, 'reading_name' => 'Private'], $this->assertJsonResponse(200, $response));
        $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testTheOwnerNeverNeedsThePassword(): void
    {
        $owner = $this->createUser('owner@example.test');
        $this->signInAs($owner->user_id);
        $id = $this->generate(['reading_name' => 'Private', 'reading_notes' => 'Only mine', 'password' => self::PASSWORD]);

        $viewed = $this->assertJsonResponse(200, $this->get("/readings/{$id}"));
        $this->assertFalse($viewed['locked']);
        $this->assertTrue($viewed['is_owner']);
        $this->assertTrue($viewed['can_draw_more']);
        $this->assertSame('Only mine', $viewed['reading_notes']);

        // The owner "unlocking" (e.g. a stale tab) just gets the reading back.
        $unlocked = $this->assertJsonResponse(200, $this->post("/readings/{$id}/unlock"));
        $this->assertTrue($unlocked['is_owner']);
        $this->assertSame('Only mine', $unlocked['reading_notes']);
    }

    public function testAWrongOrMissingPasswordLeavesTheReadingLocked(): void
    {
        $id = $this->protectedReading();
        $this->useSessionCookie(null);

        $wrong = $this->post("/readings/{$id}/unlock", ['password' => 'not ' . self::PASSWORD]);
        $this->assertSame('Incorrect password.', $this->assertDocumentedProblem('post', '/readings/{reading_id}/unlock', 403, $wrong));

        $missing = $this->post("/readings/{$id}/unlock");
        $this->assertSame('Incorrect password.', $this->assertProblem(403, $missing));

        $this->assertTrue($this->assertJsonResponse(200, $this->get("/readings/{$id}"))['locked']);
    }

    public function testUnlockingLastsForThatBrowsersSessionOnly(): void
    {
        $id = $this->protectedReading();
        $this->useSessionCookie(null);

        $unlocked = $this->assertJsonResponse(200, $this->post("/readings/{$id}/unlock", ['password' => self::PASSWORD]));
        $this->assertFalse($unlocked['locked']);
        $this->assertFalse($unlocked['is_owner']);
        $this->assertFalse($unlocked['can_draw_more']);
        $this->assertSame('Only mine', $unlocked['reading_notes']);

        // A refresh in the same browser keeps it open...
        $this->assertFalse($this->assertJsonResponse(200, $this->get("/readings/{$id}"))['locked']);

        // ...but the password unlocks the session, not the reading.
        $this->useSessionCookie(null);
        $this->assertTrue($this->assertJsonResponse(200, $this->get("/readings/{$id}"))['locked']);
    }

    public function testUnlockingAReadingWithNoPasswordJustReturnsIt(): void
    {
        $id = $this->generate();
        $this->useSessionCookie(null);

        $unlocked = $this->assertJsonResponse(200, $this->post("/readings/{$id}/unlock", ['password' => 'anything']));

        $this->assertSame($id, $unlocked['reading_id']);
        $this->assertFalse($unlocked['locked']);
    }

    public function testPasswordGuessesAreLimitedToTenAMinute(): void
    {
        $id = $this->protectedReading();
        $this->useSessionCookie(null);

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->assertProblem(403, $this->post("/readings/{$id}/unlock", ['password' => "guess {$attempt}"]));
        }

        // Every attempt counts, so not even the right password gets through now.
        $limited = $this->post("/readings/{$id}/unlock", ['password' => self::PASSWORD]);

        $this->assertSame(
            'Too many password attempts. Please wait a minute and try again.',
            $this->assertDocumentedProblem('post', '/readings/{reading_id}/unlock', 429, $limited)
        );
        $this->assertGreaterThanOrEqual(1, (int) $limited->getHeaderLine('Retry-After'));
        $this->assertLessThanOrEqual(61, (int) $limited->getHeaderLine('Retry-After'));
        $this->assertTrue($this->assertJsonResponse(200, $this->get("/readings/{$id}"))['locked']);
    }

    public function testReadsAreLimitedTo120AMinute(): void
    {
        $id = $this->generate();

        for ($read = 1; $read <= 120; $read++) {
            $this->assertSame(200, $this->get("/readings/{$id}")->getStatusCode());
        }

        $this->assertSame(
            'Too many requests. Please slow down and try again shortly.',
            $this->assertProblem(429, $this->get("/readings/{$id}"))
        );
    }

    /**
     * Generate a one-card reading in the current browser; returns its id.
     *
     * @param array<string,mixed> $options
     */
    private function generate(array $options = []): string
    {
        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', ['deck_id' => $this->deckId] + $options));
        $this->assertIsString($created['reading_id']);
        return $created['reading_id'];
    }

    /** A reading named "Private" with notes, behind PASSWORD, made by a fresh owner. */
    private function protectedReading(): string
    {
        $this->signInAs($this->createUser('owner@example.test')->user_id);
        $id = $this->generate(['reading_name' => 'Private', 'reading_notes' => 'Only mine', 'password' => self::PASSWORD]);
        $this->useSessionCookie(null);
        return $id;
    }
}

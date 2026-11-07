<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\AccountController;
use Routes\Internal\ReadingController;
use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;
use Tarot\Data\ReadingData;
use Tarot\Repository\ReadingRepository;
use Tarot\Service\PluginTokenService;
use Tarot\Service\ReadingInput;
use Tarot\Service\ReadingService;
use Tarot\Structure\ReadingTag;

/**
 * A reading's journal fields (AUDIT §9.8): what was asked, who it was for, and
 * the owner's tags. Set when drawing or later; only the owner (and their linked
 * plugin) is ever sent them — never anyone the link is shared with, before or
 * after a password — and a guest's are ignored. GET /account/tags lists the
 * owner's tags for filtering and suggestions.
 */
#[CoversClass(ReadingController::class)]
#[CoversClass(AccountController::class)]
#[CoversClass(ReadingService::class)]
#[CoversClass(ReadingInput::class)]
#[CoversClass(ReadingRepository::class)]
#[CoversClass(ReadingData::class)]
#[CoversClass(ReadingTag::class)]
final class ReadingJournalTest extends HttpTestCase
{
    private const array JOURNAL = ['question' => 'Should I take the job?', 'querent' => 'My sister', 'tags' => ['Career', ' #Monthly ', 'career']];

    private int $deckId;

    private int $ownerId;

    protected function setUp(): void
    {
        parent::setUp();

        $system = $this->service(DeckSystemData::class)->store(['name' => 'Little Arcana', 'short_name' => 'LA', 'total_cards' => 5]);
        $this->assertNotNull($system);
        $this->service(DeckSystemData::class)->storeCard(['deck_system_id' => $system->deck_system_id, 'card_id' => 1, 'name' => 'The Magician']);
        $deck = $this->service(DeckData::class)->store(['name' => 'Test Deck', 'deck_system_id' => $system->deck_system_id]);
        $this->assertNotNull($deck);
        $this->deckId = $deck->deck_id;
        $this->ownerId = $this->createUser('owner@example.test')->user_id;
    }

    public function testTheOwnerRecordsTheJournalWhenDrawing(): void
    {
        $this->signInAs($this->ownerId);

        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', ['deck_id' => $this->deckId] + self::JOURNAL));

        $this->assertDocumented('post', '/readings/generate', 201, $created);
        // Tags are lower-cased, a leading # dropped, repeats merged, and sorted.
        $this->assertSame(['Should I take the job?', 'My sister', ['career', 'monthly']], [$created['question'], $created['querent'], $created['tags']]);
        $id = $created['reading_id'];
        $this->assertIsString($id);
        $this->assertSame([['question' => 'Should I take the job?', 'querent' => 'My sister']], $this->rows("SELECT question, querent FROM readings WHERE reading_id = '{$id}'"));
        $this->assertSame([['tag' => 'career'], ['tag' => 'monthly']], $this->rows("SELECT tag FROM reading_tags WHERE reading_id = '{$id}' ORDER BY tag"));

        $viewed = $this->assertJsonResponse(200, $this->get("/readings/{$id}"));
        $this->assertDocumented('get', '/readings/{reading_id}', 200, $viewed);
        $this->assertSame(['career', 'monthly'], $viewed['tags']);
        $this->assertFalse($viewed['hide_user']);
    }

    public function testAFormPostSendsTagsAsOneCommaSeparatedString(): void
    {
        $this->signInAs($this->ownerId);

        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', ['deck_id' => $this->deckId, 'tags' => 'love, Career,,  new moon ']));

        $this->assertSame(['career', 'love', 'new moon'], $created['tags']);
    }

    public function testACustomReadingRecordsTheJournalToo(): void
    {
        $this->signInAs($this->ownerId);

        $created = $this->assertJsonResponse(201, $this->post('/readings', [
            'deck_id' => $this->deckId, 'cards' => [['card_id' => 1]], 'visibility' => 'private',
        ] + self::JOURNAL));

        $this->assertDocumented('post', '/readings', 201, $created);
        $this->assertSame(['Should I take the job?', 'My sister', ['career', 'monthly'], 'private'], [$created['question'], $created['querent'], $created['tags'], $created['visibility']]);
    }

    public function testNoOneButTheOwnerIsEverSentTheJournal(): void
    {
        $this->signInAs($this->ownerId);
        $open = $this->assertJsonResponse(201, $this->post('/readings/generate', ['deck_id' => $this->deckId] + self::JOURNAL))['reading_id'];
        $locked = $this->assertJsonResponse(201, $this->post('/readings/generate', ['deck_id' => $this->deckId, 'password' => 'abcd'] + self::JOURNAL))['reading_id'];
        $this->assertIsString($open);
        $this->assertIsString($locked);

        $this->signInAs($this->createUser('other@example.test')->user_id);
        $shared = $this->assertJsonResponse(200, $this->get("/readings/{$open}"));
        $this->assertDocumented('get', '/readings/{reading_id}', 200, $shared);
        $stub = $this->assertJsonResponse(200, $this->get("/readings/{$locked}"));
        $this->assertDocumented('get', '/readings/{reading_id}', 200, $stub);
        $unlocked = $this->assertJsonResponse(200, $this->post("/readings/{$locked}/unlock", ['password' => 'abcd']));
        $this->assertDocumented('post', '/readings/{reading_id}/unlock', 200, $unlocked);
        $this->useSessionCookie(null);
        $guest = $this->assertJsonResponse(200, $this->get("/readings/{$open}"));

        foreach (['shared' => $shared, 'locked' => $stub, 'unlocked' => $unlocked, 'guest' => $guest] as $who => $payload) {
            foreach (['question', 'querent', 'tags', 'hide_user'] as $field) {
                $this->assertArrayNotHasKey($field, $payload, "{$who} was sent {$field}");
            }
            $this->assertStringNotContainsString('sister', (string) json_encode($payload), $who);
        }
        $this->assertSame(['locked' => true, 'reading_name' => null], $stub);
    }

    public function testTheOwnersLinkedPluginIsSentTheJournal(): void
    {
        $this->signInAs($this->ownerId);
        $id = $this->assertJsonResponse(201, $this->post('/readings/generate', ['deck_id' => $this->deckId] + self::JOURNAL))['reading_id'];
        $this->useSessionCookie(null);
        $bearer = ['Authorization' => 'Bearer ' . $this->service(PluginTokenService::class)->issueToken($this->ownerId)];

        $this->assertSame('My sister', $this->assertJsonResponse(200, $this->get("/readings/{$id}", $bearer))['querent']);
        $listed = $this->assertJsonResponse(200, $this->get('/account/readings', $bearer));
        $this->assertDocumented('get', '/account/readings', 200, $listed);
        $this->assertSame([['career', 'monthly']], array_column($listed, 'tags'));
    }

    public function testAGuestsJournalIsIgnored(): void
    {
        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', ['deck_id' => $this->deckId] + self::JOURNAL));

        $this->assertArrayNotHasKey('question', $created);
        $this->assertSame([['question' => null, 'querent' => null]], $this->rows('SELECT question, querent FROM readings'));
        $this->assertSame(0, $this->rowCount('reading_tags'));
    }

    public function testTheQuestionAndQuerentAreCappedLikeTheName(): void
    {
        $this->signInAs($this->ownerId);

        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', [
            'deck_id' => $this->deckId, 'question' => str_repeat('q', 600), 'querent' => '  ' . str_repeat('é', 150), 'tags' => [],
        ]));

        $this->assertSame(ReadingInput::QUESTION_MAX, mb_strlen((string) $created['question']));
        $this->assertSame(str_repeat('é', ReadingInput::QUERENT_MAX), $created['querent']);
        $this->assertSame([], $created['tags']);
    }

    public function testTagsOverTheLimitsAreRefused(): void
    {
        $this->signInAs($this->ownerId);

        $tooMany = array_map(static fn(int $n): string => "tag {$n}", range(1, ReadingInput::TAGS_MAX + 1));
        $this->assertSame(
            ['error' => 'A reading can have at most 10 tags.'],
            $this->assertJsonResponse(400, $this->post('/readings/generate', ['deck_id' => $this->deckId, 'tags' => $tooMany]))
        );
        $this->assertSame(
            ['error' => 'A tag can be at most 30 characters: "' . str_repeat('x', 31) . '".'],
            $this->assertJsonResponse(400, $this->post('/readings/generate', ['deck_id' => $this->deckId, 'tags' => [str_repeat('X', 31)]]))
        );
        $this->assertSame(
            ['error' => 'Tags must be text.'],
            $this->assertJsonResponse(400, $this->post('/readings/generate', ['deck_id' => $this->deckId, 'tags' => [['nested']]]))
        );
        $this->assertSame(0, $this->rowCount('readings'));

        // Exactly at the limits is fine, and a repeat doesn't count twice.
        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', [
            'deck_id' => $this->deckId, 'tags' => [...array_slice($tooMany, 0, ReadingInput::TAGS_MAX - 1), 'TAG 1', str_repeat('y', 30)],
        ]));
        $this->assertIsArray($created['tags']);
        $this->assertCount(ReadingInput::TAGS_MAX, $created['tags']);
    }

    public function testTheOwnerEditsTheJournal(): void
    {
        $this->signInAs($this->ownerId);
        $id = $this->assertJsonResponse(201, $this->post('/readings/generate', ['deck_id' => $this->deckId] + self::JOURNAL))['reading_id'];
        $this->assertIsString($id);

        $updated = $this->assertJsonResponse(200, $this->request('PATCH', "/account/readings/{$id}", [
            'question' => '  Will it last? ', 'tags' => ['love', 'Monthly'],
        ]));
        $this->assertDocumented('patch', '/account/readings/{reading_id}', 200, $updated);
        // Only what was sent changes; the tags are replaced as a set.
        $this->assertSame(['Will it last?', 'My sister', ['love', 'monthly']], [$updated['question'], $updated['querent'], $updated['tags']]);

        $cleared = $this->assertJsonResponse(200, $this->request('PATCH', "/account/readings/{$id}", ['querent' => '', 'question' => null, 'tags' => []]));
        $this->assertSame([null, null, []], [$cleared['question'], $cleared['querent'], $cleared['tags']]);
        $this->assertSame(0, $this->rowCount('reading_tags'));

        // Eleven copies of one tag are one tag.
        $repeats = $this->assertJsonResponse(200, $this->request('PATCH', "/account/readings/{$id}", ['tags' => array_fill(0, 11, 'x'), 'question' => 'Saved']));
        $this->assertSame(['x'], $repeats['tags']);
        // Eleven tags are refused, and nothing else in that edit is applied.
        $tooMany = $this->request('PATCH', "/account/readings/{$id}", ['tags' => array_map(strval(...), range(1, 11)), 'question' => 'Never saved']);
        $this->assertSame(['error' => 'A reading can have at most 10 tags.'], $this->assertJsonResponse(400, $tooMany));
        $this->assertSame([['question' => 'Saved']], $this->rows("SELECT question FROM readings WHERE reading_id = '{$id}'"));
    }

    public function testTheTagListIsYourOwnWithCounts(): void
    {
        $this->signInAs($this->ownerId);
        foreach ([['Career', 'monthly'], ['career'], []] as $tags) {
            $this->assertJsonResponse(201, $this->post('/readings/generate', ['deck_id' => $this->deckId, 'tags' => $tags]));
        }
        $this->signInAs($this->createUser('other@example.test')->user_id);
        $this->assertJsonResponse(201, $this->post('/readings/generate', ['deck_id' => $this->deckId, 'tags' => ['love', 'career']]));
        $this->signInAs($this->ownerId);

        $response = $this->get('/account/tags');
        $tags = $this->assertJsonResponse(200, $response);

        $this->assertDocumented('get', '/account/tags', 200, $tags);
        $this->assertSame([['tag' => 'career', 'count' => 2], ['tag' => 'monthly', 'count' => 1]], $tags);
        $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testTheTagListIsForASignedInBrowserOnly(): void
    {
        $this->assertSame(['error' => 'Not authenticated'], $this->assertJsonResponse(401, $this->get('/account/tags')));

        $bearer = ['Authorization' => 'Bearer ' . $this->service(PluginTokenService::class)->issueToken($this->ownerId)];
        $this->assertSame(['error' => 'Not authenticated'], $this->assertJsonResponse(401, $this->get('/account/tags', $bearer)));
    }
}

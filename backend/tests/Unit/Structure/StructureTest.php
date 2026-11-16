<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Structure;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Structure\AbstractStructure;
use Tarot\Structure\Contact;
use Tarot\Structure\Deck;
use Tarot\Structure\Reading;
use Tarot\Structure\Spread;

#[CoversClass(AbstractStructure::class)]
#[CoversClass(Contact::class)]
#[CoversClass(Deck::class)]
#[CoversClass(Spread::class)]
#[CoversClass(Reading::class)]
final class StructureTest extends TestCase
{
    public function testConstructorPopulatesKnownProperties(): void
    {
        $deck = new Deck([
            'deck_id'            => 7,
            'name'               => 'Thoth',
            'deck_system_id'     => 2,
            'system_total_cards' => 78,
            'additional_cards'   => 3,
        ]);

        $this->assertSame(7, $deck->deck_id);
        $this->assertSame('Thoth', $deck->name);
        $this->assertSame(2, $deck->deck_system_id);
        $this->assertSame(78, $deck->getEffectiveTotalCards());
        $this->assertSame(3, $deck->additional_cards);
    }

    public function testUnknownPropertiesAreIgnored(): void
    {
        $deck = new Deck([
            'deck_id'        => 3,
            'does_not_exist' => 'ignored',
        ]);

        $this->assertSame(3, $deck->deck_id);
        $this->assertObjectNotHasProperty('does_not_exist', $deck);
    }

    public function testDeckDefaults(): void
    {
        $deck = new Deck();

        $this->assertSame(0, $deck->deck_id);
        $this->assertSame(0, $deck->additional_cards);
        // No system on a bare deck → effective total falls back to a standard 78.
        $this->assertSame(78, $deck->getEffectiveTotalCards());
    }

    public function testJsonSerializeReturnsEveryDeclaredProperty(): void
    {
        $spread = new Spread([
            'spread_id'   => 2,
            'name'        => 'Three Card',
            'description' => 'Past/Present/Future',
            'card_count'  => 3,
            'positions'   => [['order' => 1]],
        ]);

        $data = $spread->jsonSerialize();

        $this->assertSame(
            ['spread_id', 'name', 'description', 'card_count', 'category_id', 'category_name', 'category_sort_order', 'positions'],
            array_keys($data)
        );
        $this->assertSame(2, $data['spread_id']);
        $this->assertSame('Three Card', $data['name']);
        $this->assertSame([['order' => 1]], $data['positions']);
    }

    public function testJsonSerializeWithoutDropsOnlyTheNamedProperties(): void
    {
        // The public deck endpoints rely on this to keep submitted_by (a user id) private.
        $deck = new Deck(['deck_id' => 4, 'name' => 'Thoth', 'submitted_by' => 7]);

        $data = $deck->jsonSerializeWithout('submitted_by');

        $this->assertArrayNotHasKey('submitted_by', $data);
        $this->assertSame(4, $data['deck_id']);
        $this->assertSame('Thoth', $data['name']);
        $this->assertSame(
            array_values(array_diff(array_keys($deck->jsonSerialize()), ['submitted_by'])),
            array_keys($data)
        );
    }

    public function testJsonEncodeRoundTrip(): void
    {
        $spread = new Spread(['spread_id' => 1, 'name' => 'Single']);

        $encoded = json_encode($spread, JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true);

        $this->assertSame(1, $decoded['spread_id']);
        $this->assertSame('Single', $decoded['name']);
        $this->assertArrayHasKey('positions', $decoded);
    }


    public function testReadingSettersAndGetters(): void
    {
        $reading = new Reading();
        $reading->setReadingId('abc123');
        $reading->setReadingInfo('{"draw":[]}');
        $reading->setReadingTime('2026-05-29T12:00:00Z');

        $this->assertSame('abc123', $reading->reading_id);
        $this->assertSame('{"draw":[]}', $reading->reading_info);
        $this->assertSame('2026-05-29T12:00:00Z', $reading->reading_time);
    }

    public function testContactConstructorAndAccessors(): void
    {
        $contact = new Contact([
            'contact_id'   => 42,
            'user_id'      => 3,
            'name'         => 'Alice',
            'email'        => 'alice@example.com',
            'message'      => 'Hello world',
            'is_read'      => 1,
            'submitted_at' => '2026-06-01T10:00:00Z',
        ]);

        $this->assertSame(42, $contact->contact_id);
        $this->assertSame(3, $contact->user_id);
        $this->assertSame('Alice', $contact->name);
        $this->assertSame('alice@example.com', $contact->email);
        $this->assertSame('Hello world', $contact->message);
        $this->assertTrue($contact->isRead());
        $this->assertSame('2026-06-01T10:00:00Z', $contact->submitted_at);
    }

    public function testContactDefaults(): void
    {
        $contact = new Contact();

        $this->assertSame(0, $contact->contact_id);
        $this->assertNull($contact->user_id);
        $this->assertSame('', $contact->name);
        $this->assertSame('', $contact->email);
        $this->assertSame('', $contact->message);
        $this->assertFalse($contact->isRead());
        $this->assertSame('', $contact->submitted_at);
    }

    public function testContactJsonSerializeIncludesAllFields(): void
    {
        $contact = new Contact([
            'contact_id' => 1,
            'name'       => 'Bob',
            'email'      => 'bob@example.com',
            'message'    => 'Test',
        ]);

        $data = $contact->jsonSerialize();

        $this->assertArrayHasKey('contact_id', $data);
        $this->assertArrayHasKey('user_id', $data);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('email', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('is_read', $data);
        $this->assertArrayHasKey('submitted_at', $data);
    }
}

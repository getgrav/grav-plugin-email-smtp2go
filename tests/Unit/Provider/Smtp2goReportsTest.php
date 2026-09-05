<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSmtp2go\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\Email\Providers\SendHeader;
use Grav\Plugin\Email\Providers\WebhookRequest;
use Grav\Plugin\EmailSmtp2go\Provider\Smtp2goReports;
use PHPUnit\Framework\TestCase;

/**
 * SMTP2GO's payloads, read into the words every provider has in common.
 *
 * These moved here from the KahunaCart Newsletter add-on, where they were
 * `tests/Unit/Providers/ParserTest.php`, along with the fixtures they read.
 * Only the namespace and the event vocabulary changed: `bounce` became
 * `bounced`, `complaint` became `complained`, and the send id is a string
 * rather than a row number, because the Email plugin has no idea what a store's
 * send id looks like and should not pretend to.
 *
 * The fixtures are in `tests/Fixtures/webhooks/smtp2go/`, and one thing about
 * them is worth saying out loud: SMTP2GO documents its field names in a table
 * and shows no JSON anywhere, so the names in these files are theirs and the
 * values are invented. That is still the test worth having — every provider
 * renames a field eventually, and a parser written against a payload somebody
 * remembered reads null forever without failing anything.
 */
final class Smtp2goReportsTest extends TestCase
{
    /**
     * Every documented sample, and the normalised event it has to become.
     *
     * Written out longhand rather than generated, because a table built from
     * the same constants the reader uses would agree with the code however
     * wrong both were.
     *
     * @return iterable<string, array{0: string, 1: array<string, mixed>|null}>
     */
    public static function samples(): iterable
    {
        yield 'delivered' => ['delivered', [
            'type' => Event::DELIVERED,
            'hard' => null,
            'email' => 'customer@example.net',
            'message_id' => '20260904101501.1234@example.com',
            'provider_id' => '1u0SwL-B9zBpi9ffUq-JAB2',
            'send_id' => '41',
        ]];

        yield 'hard bounce' => ['bounce-hard', [
            'type' => Event::BOUNCED,
            'hard' => true,
            'email' => 'nosuchuser@example.net',
            'message_id' => '20260904101501.1234@example.com',
            'send_id' => '41',
            'reason' => '550 5.1.1 <nosuchuser@example.net>: Recipient address rejected: '
                . 'User unknown in virtual mailbox table',
        ]];

        yield 'soft bounce' => ['bounce-soft', [
            'type' => Event::BOUNCED,
            'hard' => false,
        ]];

        yield 'complaint' => ['spam', [
            'type' => Event::COMPLAINED,
            'hard' => null,
            'email' => 'customer@example.net',
            'reason' => 'marked as spam',
        ]];

        yield 'open' => ['open', ['type' => Event::OPENED]];
        yield 'click' => ['click', ['type' => Event::CLICKED]];

        // SMTP2GO refusing to send at all, which is a drop and not a bounce:
        // nothing was ever handed to a receiving server. Their own message for
        // it names the reason.
        yield 'reject' => ['reject', [
            'type' => Event::DROPPED,
            'hard' => null,
            'email' => 'suppressed@example.net',
            'message_id' => '20260904101501.1234@example.com',
            'send_id' => '41',
            'reason' => 'Recipient address is on the account suppression list',
        ]];

        // Two they send and this store does not act on.
        yield 'processed is not acted on' => ['processed', null];
        yield 'unsubscribe is not acted on' => ['unsubscribe', null];
    }

    /**
     * @param array<string, mixed>|null $expected null means "read no events"
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('samples')]
    public function testTheDocumentedSampleBecomesTheNormalisedEvent(string $fixture, ?array $expected): void
    {
        $payload = (new Smtp2goReports())->parse(self::request($fixture));

        if ($expected === null) {
            self::assertTrue($payload->isEmpty(), 'this sample is not one this store acts on');
            self::assertStringContainsString('act on', $payload->note, 'and it should say why');
            self::assertFalse($payload->unreadable, 'a skipped event is not an unreadable body');

            return;
        }

        self::assertCount(1, $payload->events, 'one sample is one event');
        $event = $payload->events[0]->toArray();

        foreach ($expected as $field => $value) {
            self::assertSame($value, $event[$field], "{$fixture}: {$field}");
        }
    }

    /**
     * Every sample carries a moment that was actually read.
     *
     * A date format nobody parsed reads as zero and gets quietly stamped with
     * the receiver's clock, and a whole store's charts are then wrong in a way
     * nobody can see.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('samples')]
    public function testEverySampleCarriesAMomentThatWasActuallyRead(string $fixture, ?array $expected): void
    {
        if ($expected === null) {
            self::assertTrue(true);

            return;
        }

        $event = (new Smtp2goReports())->parse(self::request($fixture))->events[0];

        self::assertGreaterThan(
            946684800,
            $event->at,
            "{$fixture}: the timestamp was not read, so the receiver's clock would stand in for it"
        );
    }

    /**
     * A body that is not JSON at all is a note and no events, never an
     * exception.
     *
     * The caller answers 200 to it and logs the first few hundred bytes. A
     * reader that threw would be a 500 on a public address, and a 500 is what
     * makes a provider retry for a week.
     */
    public function testAnUnreadableBodyIsANoteRatherThanAnException(): void
    {
        foreach (['this is not json', '', '<html>502 Bad Gateway</html>', '[{"event":"delivered"}]'] as $body) {
            $payload = (new Smtp2goReports())->parse(new WebhookRequest(body: $body));

            self::assertTrue($payload->isEmpty(), $body);
            self::assertTrue($payload->unreadable, $body);
            self::assertNotSame('', $payload->note, $body);
        }
    }

    /**
     * An empty object is a payload with no event in it rather than a body that
     * was never SMTP2GO's, and the two are logged differently.
     */
    public function testAnEmptyObjectIsSkippedRatherThanCalledUnreadable(): void
    {
        $payload = (new Smtp2goReports())->parse(new WebhookRequest(body: '{}'));

        self::assertTrue($payload->isEmpty());
        self::assertFalse($payload->unreadable);
    }

    /**
     * The send id comes back under `custom_headers` as well as at the top
     * level, because their documentation lists an echoed header inline and
     * their activity API nests it.
     */
    public function testTheSendIdIsReadFromEitherPlaceTheHeaderCanLand(): void
    {
        $nested = (string)json_encode([
            'event' => 'delivered',
            'time' => '2026-09-04T10:15:06Z',
            'rcpt' => 'a@example.com',
            'custom_headers' => ['X-Grav-Send-Id' => 77],
        ]);

        $event = (new Smtp2goReports())->parse(new WebhookRequest(body: $nested))->events[0];

        self::assertSame('77', $event->sendId, 'a number echoed back is still the id that was sent');

        $lower = (string)json_encode([
            'event' => 'delivered',
            'time' => '2026-09-04T10:15:06Z',
            'rcpt' => 'a@example.com',
            'x-grav-send-id' => '78',
        ]);

        $event = (new Smtp2goReports())->parse(new WebhookRequest(body: $lower))->events[0];

        self::assertSame('78', $event->sendId, 'a header name is case insensitive on the wire');
    }

    /**
     * A bounce whose classification is neither of their two words is soft.
     *
     * The safe direction: a soft bounce counts against an address and a store
     * that keeps count suppresses it soon enough anyway, so a misread costs a
     * delay rather than a customer.
     */
    public function testABounceWithAnUnfamiliarClassificationIsTreatedAsSoft(): void
    {
        $body = (string)json_encode([
            'event' => 'bounce',
            'bounce' => 'something-new',
            'time' => '2026-09-04T10:15:08Z',
            'rcpt' => 'a@example.com',
        ]);

        $event = (new Smtp2goReports())->parse(new WebhookRequest(body: $body))->events[0];

        self::assertFalse($event->hard);
        self::assertFalse($event->isHardBounce());
    }

    /**
     * A bounce reported with `recipients` and no `rcpt` still names somebody to
     * suppress.
     */
    public function testTheRecipientListIsReadWhenThereIsNoSingleRecipient(): void
    {
        $body = (string)json_encode([
            'event' => 'bounce',
            'bounce' => 'hard',
            'time' => '2026-09-04T10:15:08Z',
            'recipients' => ['Jane Smith <Jane@Example.COM>'],
        ]);

        $event = (new Smtp2goReports())->parse(new WebhookRequest(body: $body))->events[0];

        self::assertSame('jane@example.com', $event->email);
    }

    /** What this provider reports, and what it needs to verify a request. */
    public function testItReportsSixEventsAndNeedsNoCredentialToVerify(): void
    {
        $reports = new Smtp2goReports();

        self::assertSame(
            [Event::DELIVERED, Event::BOUNCED, Event::COMPLAINED, Event::OPENED, Event::CLICKED, Event::DROPPED],
            $reports->events()
        );

        foreach ($reports->events() as $event) {
            self::assertContains($event, Event::TYPES, 'every event is one of the words everything downstream knows');
        }

        self::assertSame([], $reports->verificationKeys(), 'SMTP2GO signs nothing, so the URL secret is the check');
        self::assertSame(SendHeader::name(), $reports->sendHeader(), 'the name is the Email plugin\'s, never one of ours');
        self::assertSame('X-Grav-Send-Id', $reports->sendHeader());
    }

    // ------------------------------------------------------------- internals

    private static function request(string $fixture): WebhookRequest
    {
        return new WebhookRequest(
            headers: ['content-type' => 'application/json'],
            body: self::body($fixture),
        );
    }

    private static function body(string $fixture): string
    {
        $path = \dirname(__DIR__, 2) . "/Fixtures/webhooks/smtp2go/{$fixture}.json";
        self::assertFileExists($path, "there is no sample at {$path}");

        return (string)file_get_contents($path);
    }
}

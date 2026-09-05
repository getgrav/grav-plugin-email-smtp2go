<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSmtp2go\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\Email\Providers\SendHeader;
use Grav\Plugin\EmailSmtp2go\Provider\Smtp2goApi;
use Grav\Plugin\EmailSmtp2go\Provider\Smtp2goSetup;
use Grav\Plugin\EmailSmtp2go\Tests\Support\FakeHttp;
use PHPUnit\Framework\TestCase;

/**
 * The one button, and the three things that happen when it is pressed.
 *
 * It works; the key is real but not allowed to manage webhooks; SMTP2GO is not
 * reachable at all. The second of those is nearly all of the real failures, and
 * it is the one where the message matters most: "403" tells nobody anything,
 * and the merchant needs the permission named in SMTP2GO's own vocabulary
 * because every dashboard calls it something different.
 */
final class Smtp2goSetupTest extends TestCase
{
    private const URL = 'https://store.example.com/newsletter/webhook/smtp2go/a-very-long-secret';

    private const EVENTS = [
        Event::DELIVERED,
        Event::BOUNCED,
        Event::COMPLAINED,
        Event::OPENED,
        Event::CLICKED,
        Event::DROPPED,
    ];

    public function testItCreatesTheWebhookAndSaysWhichOneItMade(): void
    {
        $http = (new FakeHttp())
            ->willAnswer('/webhook/view', 200, ['data' => ['webhooks' => []]])
            ->willAnswer('/webhook/add', 200, ['data' => ['id' => 812]]);

        $result = self::button($http)->create(self::URL, self::EVENTS, []);

        self::assertTrue($result->ok);
        self::assertSame('812', $result->webhookId);
        self::assertStringContainsString('812', $result->message);
    }

    /**
     * The two settings on the request that fail silently when they are missing.
     *
     * A webhook created on their default output format posts form-encoded
     * bodies, which look perfectly healthy in their dashboard and are
     * unreadable at the other end. A webhook created without the send header
     * named echoes no header back, and every bounce then has to be tied to a
     * campaign by message id alone.
     */
    public function testTheRequestSetsTheOutputFormatAndRegistersTheSendHeader(): void
    {
        $http = (new FakeHttp())
            ->willAnswer('/webhook/view', 200, ['data' => ['webhooks' => []]])
            ->willAnswer('/webhook/add', 200, ['data' => ['id' => 1]]);

        self::button($http)->create(self::URL, self::EVENTS, []);

        $call = $http->callTo('/webhook/add');

        self::assertNotNull($call);
        self::assertSame('json', $call['body']['output_format']);
        self::assertSame([SendHeader::name()], $call['body']['headers']);
        self::assertSame(self::URL, $call['body']['url']);
        self::assertSame(['delivered', 'bounce', 'spam', 'open', 'click', 'reject'], $call['body']['events']);
        self::assertSame('api-a-real-key', $call['headers'][Smtp2goApi::KEY_HEADER]);
    }

    /**
     * A contract event SMTP2GO has no name for is dropped rather than refused.
     *
     * `dropped` is `reject` here, so the pair asked for is the pair registered;
     * a word SMTP2GO has never heard of costs the one event rather than the
     * whole webhook, because a provider maps what it can and ignores the rest.
     */
    public function testAnEventThisProviderCannotReportIsLeftOutRatherThanRefused(): void
    {
        $http = (new FakeHttp())
            ->willAnswer('/webhook/view', 200, ['data' => ['webhooks' => []]])
            ->willAnswer('/webhook/add', 200, ['data' => ['id' => 1]]);

        $result = self::button($http)->create(self::URL, [Event::BOUNCED, Event::DROPPED, 'sms_delivered'], []);

        self::assertTrue($result->ok);
        self::assertSame(['bounce', 'reject'], $http->callTo('/webhook/add')['body']['events']);
    }

    /**
     * A key that is real but is not allowed to manage webhooks gets SMTP2GO's
     * own words plus the sentence that says what to do about it.
     */
    public function testAKeyWithoutTheWebhooksPermissionAnswersInTheirWordsAndSaysWhatToDo(): void
    {
        $http = (new FakeHttp())
            ->willAnswer('/webhook/view', 403, ['data' => ['error' => 'API key does not have permission']])
            ->willAnswer('/webhook/add', 403, ['data' => ['error' => 'API key does not have permission']]);

        $result = self::button($http)->create(self::URL, self::EVENTS, []);

        self::assertFalse($result->ok);
        self::assertNull($result->webhookId);
        self::assertStringContainsString('API key does not have permission', $result->message);
        self::assertStringContainsString('tick Webhooks', $result->message);
        self::assertStringEndsWith('.', $result->message, 'a merchant reads a sentence, not a fragment');
    }

    /** SMTP2GO not being reachable at all is said plainly and is not an exception. */
    public function testANetworkFailureIsAPlainSentence(): void
    {
        $http = (new FakeHttp())
            ->willFail('/webhook/view', 'Could not resolve host: api.smtp2go.com')
            ->willFail('/webhook/add', 'Could not resolve host: api.smtp2go.com');

        $result = self::button($http)->create(self::URL, self::EVENTS, []);

        self::assertFalse($result->ok);
        self::assertStringContainsString('could not be reached', $result->message);
        self::assertStringContainsString('Could not resolve host', $result->message);
    }

    /**
     * Pressing it twice leaves one webhook.
     *
     * SMTP2GO's add call has no upsert and would happily make a second webhook
     * posting the same events at the same address, so the account's existing
     * webhooks are read first.
     */
    public function testPressingItTwiceDoesNotLeaveTwoWebhooks(): void
    {
        $http = (new FakeHttp())->willAnswer('/webhook/view', 200, [
            'data' => ['webhooks' => [['id' => 812, 'url' => self::URL]]],
        ]);

        $result = self::button($http)->create(self::URL, self::EVENTS, []);

        self::assertTrue($result->ok);
        self::assertSame('812', $result->webhookId);
        self::assertStringContainsString('already', $result->message);
        self::assertSame(0, $http->countTo('/webhook/add'), 'nothing should have been added');
    }

    /** A webhook at some other address is not this one. */
    public function testAWebhookAtAnotherAddressDoesNotCountAsThisOne(): void
    {
        $http = (new FakeHttp())
            ->willAnswer('/webhook/view', 200, [
                'data' => ['webhooks' => [['id' => 5, 'url' => 'https://elsewhere.example.com/hook']]],
            ])
            ->willAnswer('/webhook/add', 200, ['data' => ['id' => 813]]);

        $result = self::button($http)->create(self::URL, self::EVENTS, []);

        self::assertTrue($result->ok);
        self::assertSame('813', $result->webhookId);
    }

    /** No key anywhere is a sentence naming where to put one. */
    public function testNoKeyAtAllSaysWhereToPutOne(): void
    {
        $result = (new Smtp2goSetup(new Smtp2goApi(new FakeHttp()), []))->create(self::URL, self::EVENTS, []);

        self::assertFalse($result->ok);
        self::assertStringContainsString('Email SMTP2GO', $result->message);
    }

    /** No URL to register is refused before anything leaves the building. */
    public function testNoAddressToRegisterIsRefusedWithoutCallingTheApi(): void
    {
        $http = new FakeHttp();

        $result = self::button($http)->create('   ', self::EVENTS, []);

        self::assertFalse($result->ok);
        self::assertSame([], $http->calls);
    }

    /**
     * A key handed in by the caller beats the one in this plugin's config.
     *
     * That is the store with a send-only sending key and a second, narrower key
     * that is allowed to manage webhooks.
     */
    public function testAKeyHandedInWinsOverThePluginsOwn(): void
    {
        $http = (new FakeHttp())
            ->willAnswer('/webhook/view', 200, ['data' => ['webhooks' => []]])
            ->willAnswer('/webhook/add', 200, ['data' => ['id' => 1]]);

        self::button($http)->create(self::URL, self::EVENTS, ['api_key' => 'api-a-narrower-key']);

        self::assertSame('api-a-narrower-key', $http->callTo('/webhook/add')['headers'][Smtp2goApi::KEY_HEADER]);
    }

    /** The permission sentence names the box, because every dashboard names it differently. */
    public function testThePermissionSentenceNamesTheBoxToTick(): void
    {
        $needed = self::button(new FakeHttp())->permissionsNeeded();

        self::assertStringContainsString('API Keys', $needed);
        self::assertStringContainsString('Webhooks', $needed);
    }

    // ------------------------------------------------------------- internals

    private static function button(FakeHttp $http): Smtp2goSetup
    {
        return new Smtp2goSetup(new Smtp2goApi($http), ['api_key' => 'api-a-real-key']);
    }
}

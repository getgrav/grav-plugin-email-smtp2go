<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSmtp2go\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\Provider;
use Grav\Plugin\Email\Providers\ProviderRegistry;
use Grav\Plugin\EmailSmtp2go\Provider\Smtp2goApi;
use Grav\Plugin\EmailSmtp2go\Provider\Smtp2goProvider;
use Grav\Plugin\EmailSmtp2go\Provider\Smtp2goReports;
use Grav\Plugin\EmailSmtp2go\Provider\Smtp2goSetup;
use Grav\Plugin\EmailSmtp2go\Tests\Support\FakeHttp;
use PHPUnit\Framework\TestCase;

/**
 * What this plugin tells the Email plugin about SMTP2GO.
 *
 * The interesting half is `domain()`. The DNS facts are a table, and a table
 * with a wrong row in it is a deliverability screen telling a merchant their
 * perfectly good SPF record is wrong — so the three names are pinned here
 * rather than trusted. The lookup is the other half: it asks SMTP2GO what the
 * account actually has, and it must answer an empty pair rather than throw when
 * their API is slow, down or refusing the key.
 */
final class Smtp2goProviderTest extends TestCase
{
    public function testItAnswersForTheEngineItsPluginRegisters(): void
    {
        $provider = new Smtp2goProvider();

        self::assertInstanceOf(Provider::class, $provider);
        self::assertSame(['smtp2go'], $provider->engines());
        self::assertSame('smtp2go', $provider->key());
        self::assertSame('SMTP2GO', $provider->label());
    }

    /** And the registry finds it by either name. */
    public function testTheRegistryFindsItByEngineAndByKey(): void
    {
        $registry = new ProviderRegistry();
        $registry->add(new Smtp2goProvider());

        self::assertInstanceOf(Smtp2goProvider::class, $registry->forEngine('smtp2go'));
        self::assertInstanceOf(Smtp2goProvider::class, $registry->byKey('SMTP2GO'));
        self::assertNull($registry->forEngine('ses'));
    }

    /**
     * Both transports carry every header the caller set, which is what keeps
     * the unsubscribe button in Gmail.
     *
     * The SMTP one always did — there the headers are the first half of the
     * message. The API one has since 1.0.1, which is the release that stopped
     * dropping them.
     */
    public function testBothTransportsCarryHeadersAndTheEchoNoteSaysWhatIsNeeded(): void
    {
        $capabilities = (new Smtp2goProvider())->capabilities();

        self::assertTrue($capabilities->customHeaders);
        self::assertTrue($capabilities->unsubscribeHeaders);
        self::assertTrue($capabilities->echoesHeaders);
        self::assertStringContainsString(Smtp2goReports::SEND_HEADER, $capabilities->echoNote);
        self::assertStringContainsString('Headers list', $capabilities->echoNote);
    }

    public function testItReportsDeliveriesAndCanSetItselfUp(): void
    {
        $provider = new Smtp2goProvider(['api_key' => 'api-a-real-key']);

        self::assertInstanceOf(Smtp2goReports::class, $provider->reports());
        self::assertInstanceOf(Smtp2goSetup::class, $provider->setup());
        self::assertSame($provider->reports(), $provider->reports(), 'built once, not once per settings screen');
    }

    /** The three DNS names, pinned. */
    public function testTheDomainFactsAreSmtp2gosOwnZones(): void
    {
        $facts = (new Smtp2goProvider())->domain();

        self::assertSame('spf.smtp2go.com', $facts->spfInclude);
        self::assertSame('dkim.smtp2go.net', $facts->dkimZone);
        self::assertSame('return.smtp2go.net', $facts->returnPathZone);
    }

    /** With no key there is nobody to ask, and that is a null rather than a call that fails. */
    public function testThereIsNoLookupWithoutAKey(): void
    {
        self::assertNull((new Smtp2goProvider())->domain()->lookup);
        self::assertSame([], (new Smtp2goProvider())->domain()->ask('example.com'));
    }

    /**
     * The selector out of their explicit field, and the return path out of the
     * CNAME that points into their zone.
     */
    public function testTheLookupReadsTheSelectorsAndReturnPathsForOneDomain(): void
    {
        $http = (new FakeHttp())->willAnswer('/domain/view', 200, ['data' => ['domains' => [
            [
                'domain' => 'example.com',
                'dkim_selector' => 's1234',
                'cnames' => [
                    ['hostname' => 'em.example.com', 'value' => 'return.smtp2go.net'],
                    ['hostname' => 's9999._domainkey.example.com', 'value' => 'dkim.smtp2go.net'],
                ],
            ],
            ['domain' => 'someone-else.example.org', 'dkim_selector' => 'nope'],
        ]]]);

        $facts = self::provider($http)->domain()->ask('example.com');

        self::assertSame(['s1234', 's9999'], $facts['selectors']);
        self::assertSame(['em.example.com'], $facts['return_paths']);
        self::assertSame('api-a-real-key', $http->callTo('/domain/view')['headers'][Smtp2goApi::KEY_HEADER]);
    }

    /**
     * A store sending as a subdomain of the account's domain is the same
     * domain.
     *
     * Refusing to read a selector because `mail.example.com` is not the string
     * `example.com` would be a check that fails on every store using a sending
     * subdomain.
     */
    public function testASendingSubdomainIsTheSameOrganisation(): void
    {
        $http = (new FakeHttp())->willAnswer('/domain/view', 200, ['data' => ['domains' => [
            ['domain' => 'example.com', 'dkim_selector' => 's1234'],
        ]]]);

        self::assertSame(['s1234'], self::provider($http)->domain()->ask('mail.example.com')['selectors']);
    }

    /** Their API refusing the key is an unanswered question, not a broken screen. */
    public function testARefusedKeyAnswersNothingRatherThanThrowing(): void
    {
        $http = (new FakeHttp())->willAnswer('/domain/view', 401, ['data' => ['error' => 'Invalid API key']]);

        self::assertSame(['selectors' => [], 'return_paths' => []], self::provider($http)->domain()->ask('example.com'));
    }

    /** And so is their API not being reachable. */
    public function testAnUnreachableApiAnswersNothing(): void
    {
        $http = (new FakeHttp())->willFail('/domain/view', 'Operation timed out');

        self::assertSame(['selectors' => [], 'return_paths' => []], self::provider($http)->domain()->ask('example.com'));
    }

    /** An empty domain is not a question worth a round trip. */
    public function testAnEmptyDomainIsNotAsked(): void
    {
        $http = new FakeHttp();

        self::assertSame([], self::provider($http)->domain()->ask('   '));
        self::assertSame([], $http->calls);
    }

    /**
     * Two checks on one screen want the same answer, and neither should cost a
     * second round trip to somebody else's API.
     */
    public function testTheSameDomainIsOnlyAskedAboutOnce(): void
    {
        $http = (new FakeHttp())
            ->willAnswer('/domain/view', 200, ['data' => ['domains' => [
                ['domain' => 'example.com', 'dkim_selector' => 's1234'],
            ]]]);

        $facts = self::provider($http)->domain();
        $facts->ask('example.com');
        $facts->ask('example.com');

        self::assertSame(1, $http->countTo('/domain/view'));
    }

    /** The instructions name the screen, the boxes and the output format. */
    public function testTheInstructionsNameTheScreenAndTheBoxes(): void
    {
        $instructions = (new Smtp2goProvider())->instructions();

        self::assertStringContainsString('Webhooks', $instructions);
        self::assertStringContainsString('JSON', $instructions);
        self::assertStringContainsString(Smtp2goReports::SEND_HEADER, $instructions);
    }

    /**
     * The instructions in the language file are the same words as the English
     * fallback in the class.
     *
     * They drift the moment one of them is edited on its own, and the one a
     * merchant reads depends on whether their site has a language pack.
     */
    public function testTheLanguageFileSaysTheSameThingAsTheFallback(): void
    {
        $yaml = (string)file_get_contents(\dirname(__DIR__, 3) . '/languages.yaml');

        self::assertStringContainsString((new Smtp2goProvider())->instructions(), $yaml);
    }

    // ------------------------------------------------------------- internals

    private static function provider(FakeHttp $http): Smtp2goProvider
    {
        return new Smtp2goProvider(['api_key' => 'api-a-real-key'], $http);
    }
}

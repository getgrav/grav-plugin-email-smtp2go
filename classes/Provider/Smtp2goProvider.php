<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSmtp2go\Provider;

use Grav\Plugin\Email\Providers\Capabilities;
use Grav\Plugin\Email\Providers\DeliveryReports;
use Grav\Plugin\Email\Providers\DomainFacts;
use Grav\Plugin\Email\Providers\Provider;
use Grav\Plugin\Email\Providers\WebhookSetup;

/**
 * Everything SMTP2GO knows about itself, answered by SMTP2GO's own plugin.
 *
 * Registered on the Email plugin's `onEmailProviders` event, which is all any
 * of this needs: an add-on that wants to record bounces asks
 * `Email::providerFor('smtp2go')` and gets the reader, the setup button, the
 * DNS facts and the plain words for doing it by hand — none of which it has to
 * carry itself, and none of which goes stale in six other repositories when
 * SMTP2GO renames a field.
 *
 * All of this used to live in the KahunaCart Newsletter add-on. It is here now
 * because the plugin that already holds the API key and already talks to
 * SMTP2GO is the only thing that should have to know any of it.
 *
 * ## The cheap methods stay cheap
 *
 * {@see engines()}, {@see key()}, {@see label()}, {@see capabilities()},
 * {@see domain()} and {@see instructions()} are called every time a settings
 * screen is drawn. Nothing here touches the network: the API calls are behind
 * {@see setup()}'s button and behind the closure on {@see domain()}, which the
 * caller decides when to run.
 */
final class Smtp2goProvider implements Provider
{
    /** The engine this plugin registers on `onEmailEngines`. */
    public const ENGINE = 'smtp2go';

    /** The host an SPF record has to end up sending people to, at any depth. */
    public const SPF_INCLUDE = 'spf.smtp2go.com';

    /** The zone a DKIM selector CNAMEs into. */
    public const DKIM_ZONE = 'dkim.smtp2go.net';

    /** The zone a custom return path CNAMEs into. */
    public const RETURN_PATH_ZONE = 'return.smtp2go.net';

    /** The language key {@see instructions()} looks for before its English. */
    public const INSTRUCTIONS_KEY = 'PLUGIN_EMAIL_SMTP2GO.PROVIDER_INSTRUCTIONS';

    private ?Smtp2goReports $reports = null;

    private ?Smtp2goSetup $setup = null;

    private ?Smtp2goApi $api = null;

    /**
     * @param array<string, mixed> $config this plugin's own configuration, the
     *        `plugins.email-smtp2go` block
     * @param Http|null $http the HTTP client the API calls go through; null is
     *        the real one, and a test hands over a stand-in
     */
    public function __construct(
        private readonly array $config = [],
        private readonly ?Http $http = null,
    ) {
    }

    /** @return list<string> */
    public function engines(): array
    {
        return [self::ENGINE];
    }

    public function key(): string
    {
        return self::ENGINE;
    }

    public function label(): string
    {
        return 'SMTP2GO';
    }

    /**
     * What this transport does to a message on the way out.
     *
     * The SMTP transport hands the whole message to the server, so every header
     * on it arrives. The API transport rebuilds the message as JSON, and since
     * 1.0.1 it carries every header the caller set into `custom_headers` with
     * its original case — which is what keeps `List-Unsubscribe` and
     * `List-Unsubscribe-Post` alive, and with them the unsubscribe button Gmail
     * shows at the top of a bulk message.
     *
     * `echoesHeaders` is true with a condition, which is what `echoNote` is
     * for: SMTP2GO echoes `Subject` and `Message-id` on its webhooks by default
     * and nothing else, so a custom header comes back only once it is named on
     * the webhook itself.
     */
    public function capabilities(): Capabilities
    {
        return new Capabilities(
            customHeaders: true,
            unsubscribeHeaders: true,
            echoesHeaders: true,
            echoNote: 'SMTP2GO only sends a custom header back on its webhooks when the header is named on the '
                . 'webhook itself. Pressing Set up adds it for you; if you made the webhook by hand, add '
                . Smtp2goReports::SEND_HEADER . ' to its Headers list.',
        );
    }

    public function reports(): ?DeliveryReports
    {
        return $this->reports ??= new Smtp2goReports();
    }

    public function setup(): ?WebhookSetup
    {
        return $this->setup ??= new Smtp2goSetup($this->api(), $this->config);
    }

    /**
     * What SMTP2GO needs a sending domain's DNS to say, and the way to ask
     * their API what it already says.
     *
     * The selector itself is not here and cannot be: SMTP2GO's is `s` plus the
     * account's own numeric id, so there is nothing to guess. The lookup is the
     * way out of that — the account's own key asking about the account's own
     * domains — and it answers an empty pair rather than throwing when the API
     * is slow, down, or refusing the key.
     */
    public function domain(): DomainFacts
    {
        $key = trim((string)($this->config['api_key'] ?? ''));

        return new DomainFacts(
            spfInclude: self::SPF_INCLUDE,
            dkimZone: self::DKIM_ZONE,
            returnPathZone: self::RETURN_PATH_ZONE,
            lookup: $key === ''
                ? null
                : fn (string $domain): array => $this->api()->domainFacts($key, $domain),
        );
    }

    /**
     * Doing it by hand, naming the screens and the boxes.
     *
     * "Configure a webhook" is not instructions, and SMTP2GO calls three
     * different things by names close enough to be picked wrongly. The output
     * format is in here because it is the one that fails silently: a webhook
     * left on their default posts form-encoded bodies, which look fine in the
     * dashboard and are unreadable at the other end.
     */
    public function instructions(): string
    {
        return self::say(
            self::INSTRUCTIONS_KEY,
            'In SMTP2GO, open Settings then Webhooks and add a webhook with this URL, output format JSON, and the '
            . 'delivered, bounce, spam complaint, open and click events ticked. Add ' . Smtp2goReports::SEND_HEADER
            . ' to the webhook\'s own Headers list. Or paste an API key into this plugin and press Set up, which '
            . 'does all of that for you.'
        );
    }

    // ------------------------------------------------------------- internals

    private function api(): Smtp2goApi
    {
        return $this->api ??= new Smtp2goApi($this->http ?? new CurlHttp());
    }

    /**
     * A translated string, or the English one.
     *
     * Grav's translator answers the key back when nothing has been written for
     * the site's language, so that is what "no translation" looks like here.
     * Everything is wrapped because this is called while a settings screen is
     * being drawn and a provider is not allowed to throw on one of those — and
     * because the same class is unit tested with no Grav anywhere.
     */
    private static function say(string $key, string $english): string
    {
        try {
            if (!class_exists(\Grav\Common\Grav::class)) {
                return $english;
            }

            $language = \Grav\Common\Grav::instance()['language'] ?? null;
            if ($language === null || !method_exists($language, 'translate')) {
                return $english;
            }

            $said = trim((string)$language->translate([$key]));

            return $said === '' || $said === $key ? $english : $said;
        } catch (\Throwable) {
            return $english;
        }
    }
}

<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSmtp2go\Provider;

use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\Email\Providers\SetupResult;
use Grav\Plugin\Email\Providers\WebhookSetup;

/**
 * "Set up in SMTP2GO", which is the one button a merchant should have to press.
 *
 * A driver sets itself up from the key that was already pasted in for sending,
 * with manual steps only as the fallback. SMTP2GO's API allows it:
 * `POST /v3/webhook/add` with a URL and a list of events creates the webhook,
 * registers the send header on it and sets the output format, and the merchant
 * does nothing but press a button.
 *
 * ## The key
 *
 * The one in this plugin's own configuration, because that is the one the
 * merchant pasted in and the whole point is not asking for it twice. A caller
 * that holds a different key for the same account can pass one in `$config`,
 * and that wins — which is what a store with a send-only sending key and a
 * second, narrower key for managing webhooks needs.
 *
 * ## Pressing it twice
 *
 * SMTP2GO's `webhook/add` creates a new webhook every time; there is no upsert
 * and no create-or-update anywhere in their API. Rather than deleting webhooks
 * this plugin did not create, {@see create()} asks for the account's existing
 * webhooks first and stops when one is already pointing at the same address. A
 * merchant who presses the button twice ends up with one webhook and a sentence
 * saying it was already there.
 */
final class Smtp2goSetup implements WebhookSetup
{
    /**
     * What a key must be allowed to do, in SMTP2GO's own vocabulary.
     *
     * A constant rather than a sentence built somewhere, because it is the same
     * every time and it is shown both before the button is pressed and after a
     * refusal that mentions a permission.
     */
    public const PERMISSIONS = 'The API key needs the webhooks permission. In SMTP2GO, open Settings, then API '
        . 'Keys, open the key you are using and tick Webhooks in its permissions. A send-only key sends mail '
        . 'perfectly well but cannot create the webhook that reports what happened to it.';

    /** The contract's event words to SMTP2GO's own. */
    private const EVENTS = [
        Event::DELIVERED => 'delivered',
        Event::BOUNCED => 'bounce',
        Event::COMPLAINED => 'spam',
        Event::OPENED => 'open',
        Event::CLICKED => 'click',
        Event::DROPPED => 'reject',
    ];

    /**
     * @param array<string, mixed> $config this plugin's own configuration
     */
    public function __construct(
        private readonly Smtp2goApi $api,
        private readonly array $config = [],
    ) {
    }

    public function create(string $url, array $events, array $config): SetupResult
    {
        $key = self::keyIn($config);
        $key = $key !== '' ? $key : self::keyIn($this->config);

        if ($key === '') {
            return SetupResult::failed(
                'There is no SMTP2GO API key to set the webhook up with. Paste one into the Email SMTP2GO '
                . 'plugin first — it is the same key the store sends with.'
            );
        }

        if (trim($url) === '') {
            return SetupResult::failed('There is no webhook address to register yet.');
        }

        $already = $this->api->webhookAt($key, $url);
        if ($already !== null) {
            return SetupResult::ok(
                'SMTP2GO already has a webhook at this address, so nothing was added.',
                $already > 0 ? (string)$already : null
            );
        }

        $answer = $this->api->createWebhook($key, $url, self::theirNames($events));

        if (!$answer['ok']) {
            return SetupResult::failed(self::sentence($answer['message']));
        }

        return SetupResult::ok($answer['message'], $answer['id'] === null ? null : (string)$answer['id']);
    }

    public function permissionsNeeded(): string
    {
        return self::PERMISSIONS;
    }

    // ------------------------------------------------------------- internals

    /**
     * SMTP2GO's names for the events the caller asked for.
     *
     * An event this provider cannot report is dropped rather than refused: the
     * contract says a provider maps what it can and ignores the rest, so a
     * caller asking for something SMTP2GO has no name for gets a webhook for
     * the ones it does have rather than an error. Asking for nothing at all
     * registers all six, because a webhook for no events is a webhook that
     * never fires.
     *
     * @param  list<string> $events
     * @return list<string>
     */
    private static function theirNames(array $events): array
    {
        $names = [];

        foreach ($events as $event) {
            $name = self::EVENTS[strtolower(trim((string)$event))] ?? null;
            if ($name !== null && !\in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names === [] ? Smtp2goApi::EVENTS : $names;
    }

    /** @param array<string, mixed> $config */
    private static function keyIn(array $config): string
    {
        return trim((string)($config['api_key'] ?? ''));
    }

    /**
     * SMTP2GO's own words, made into a sentence a merchant can act on.
     *
     * Their refusals arrive as a fragment — "API key does not have permission"
     * — and a fragment on its own tells nobody what to do next, so the
     * permission sentence follows any refusal that mentions one.
     */
    private static function sentence(string $message): string
    {
        $message = trim($message);
        $message = $message === '' ? 'SMTP2GO refused it.' : ucfirst($message);

        if (!str_ends_with($message, '.')) {
            $message .= '.';
        }

        return stripos($message, 'permission') !== false || stripos($message, 'not allowed') !== false
            ? $message . ' ' . self::PERMISSIONS
            : $message;
    }
}

<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSmtp2go\Provider;

use Grav\Plugin\Email\Providers\SendHeader;

/**
 * The two things this plugin asks SMTP2GO about itself.
 *
 * Creating the store's delivery webhook, and reading what a sending domain's
 * DNS actually says on the account. Both go through the same key the merchant
 * already pasted in for sending, and neither is stored here: the key is handed
 * over per call.
 *
 * Documentation: `developers.smtp2go.com/reference/add-webhook` and its
 * siblings, and `/reference/domain-view`, read 2026-09-04 from the OpenAPI
 * document their reference pages carry rather than from the rendered prose,
 * which is drawn by a script.
 *
 * ## What the webhook call registers
 *
 * The five events this contract can report — `delivered`, `bounce`, `spam`,
 * `open`, `click` — and **not** `processed`, `reject`, `unsubscribe` or
 * `resubscribe`. A store that ticked those in the dashboard gets a 200 and a
 * log line for each one; registering them here would be generating traffic that
 * has already been decided against.
 *
 * Two settings that are easy to miss and both matter:
 *
 * - **`output_format` must be `json`.** Their default is `form`, which posts
 *   `application/x-www-form-urlencoded`. {@see Smtp2goReports} reads a JSON
 *   object, so a webhook created without this would deliver nothing readable
 *   and would look, from the dashboard, like it was working.
 * - **`headers` must name the send header.** SMTP2GO echoes `Subject` and
 *   `Message-id` by default and nothing else; a custom header that is not
 *   registered on the webhook is not sent. The Message-ID is what correlation
 *   normally uses, so this is the belt — but it is one field and it costs
 *   nothing to ask for.
 *
 * ## What it does not do
 *
 * It does not delete a webhook. A merchant who wants one gone should remove it
 * in SMTP2GO's own dashboard, where they can see what else is pointed at it —
 * a plugin removing a webhook it did not create is exactly the sort of thing
 * that takes a store's other integration down at four on a Friday.
 */
final class Smtp2goApi
{
    /** Their regionless base. The regional ones are `us-`, `eu-` and `au-`. */
    public const BASE = 'https://api.smtp2go.com/v3';

    /** The header their OpenAPI document declares as the canonical auth. */
    public const KEY_HEADER = 'X-Smtp2go-Api-Key';

    /**
     * The events this provider reports, in SMTP2GO's spelling.
     *
     * @var list<string>
     */
    public const EVENTS = ['delivered', 'bounce', 'spam', 'open', 'click', 'reject'];

    /**
     * One answer per domain per request.
     *
     * Both the SPF check and the DKIM check on a deliverability screen want the
     * same answer in the same run, and neither should cost a second round trip
     * to somebody else's API.
     *
     * @var array<string, array{selectors: list<string>, return_paths: list<string>}>
     */
    private array $domains = [];

    public function __construct(private readonly Http $http)
    {
    }

    /**
     * Create the store's webhook, or say plainly why it could not be.
     *
     * @param  list<string> $events SMTP2GO's own event names
     * @return array{ok: bool, id: int|null, message: string, events: list<string>}
     */
    public function createWebhook(string $apiKey, string $url, array $events = self::EVENTS): array
    {
        $apiKey = trim($apiKey);
        $events = $events === [] ? self::EVENTS : $events;

        if ($apiKey === '') {
            return self::no('no SMTP2GO API key is configured', $events);
        }

        if (trim($url) === '') {
            return self::no('there is no webhook URL to register', $events);
        }

        $answer = $this->http->postJson(self::BASE . '/webhook/add', [
            'url' => $url,
            'events' => array_values($events),
            // See the class note: without this the webhook posts form-encoded
            // bodies and nothing downstream can read them.
            'output_format' => 'json',
            'headers' => [SendHeader::name()],
        ], [self::KEY_HEADER => $apiKey]);

        if ($answer['status'] === 0) {
            return self::no($answer['error'] !== ''
                ? 'SMTP2GO could not be reached: ' . $answer['error']
                : 'SMTP2GO could not be reached', $events);
        }

        $body = $answer['body'] ?? [];
        $data = \is_array($body['data'] ?? null) ? $body['data'] : [];

        // Their errors nest under `data` on these endpoints rather than sitting
        // at the top level the way the general API reference shows.
        $error = trim((string)($data['error'] ?? $body['error'] ?? ''));

        if ($answer['status'] < 200 || $answer['status'] >= 300 || $error !== '') {
            return self::no($error !== ''
                ? 'SMTP2GO refused it: ' . $error
                : sprintf('SMTP2GO answered %d', $answer['status']), $events);
        }

        $id = self::idIn($data);

        return [
            'ok' => true,
            'id' => $id,
            'message' => $id === null
                ? 'The webhook was created in SMTP2GO.'
                : sprintf('The webhook was created in SMTP2GO as number %d.', $id),
            'events' => $events,
        ];
    }

    /**
     * Point an existing webhook at a new address, keeping the format and the
     * send header it needs.
     *
     * This is what `Set up` does when the store's secret has changed since the
     * webhook was made: the old URL answers 404, SMTP2GO has no upsert, and an
     * account on the free plan has no room for a second webhook anyway. Editing
     * the one that is there is the only move that leaves one working webhook.
     *
     * @param  list<string> $events SMTP2GO's own event names
     * @return array{ok: bool, id: int|null, message: string, events: list<string>}
     */
    public function updateWebhook(string $apiKey, int $id, string $url, array $events = self::EVENTS): array
    {
        $apiKey = trim($apiKey);
        $events = $events === [] ? self::EVENTS : $events;

        if ($apiKey === '') {
            return self::no('no SMTP2GO API key is configured', $events);
        }
        if ($id <= 0) {
            return self::no('there is no webhook to update', $events);
        }
        if (trim($url) === '') {
            return self::no('there is no webhook URL to register', $events);
        }

        $answer = $this->http->postJson(self::BASE . '/webhook/edit', [
            'id' => $id,
            'url' => $url,
            'events' => array_values($events),
            'output_format' => 'json',
            'headers' => [SendHeader::name()],
        ], [self::KEY_HEADER => $apiKey]);

        $refusal = self::refusalIn($answer);
        if ($refusal !== null) {
            return self::no($refusal, $events);
        }

        return [
            'ok' => true,
            'id' => $id,
            'message' => sprintf('Webhook number %d in SMTP2GO now points at this address.', $id),
            'events' => $events,
        ];
    }

    /**
     * The id of a webhook pointing somewhere under this prefix, or null.
     *
     * A store's webhook address is its endpoint followed by a secret, so a
     * webhook whose URL starts with the endpoint but does not match the whole
     * address is this store's webhook registered against an older secret. That
     * is the one worth updating rather than adding beside.
     *
     * Only a numbered webhook is answered, because an edit needs the number.
     *
     * @param list<mixed> $webhooks what {@see webhooks()} answered
     */
    public static function idUnder(array $webhooks, string $prefix): ?int
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return null;
        }

        foreach ($webhooks as $webhook) {
            if (!\is_array($webhook)) {
                continue;
            }
            $url = trim((string)($webhook['url'] ?? ''));
            if ($url === '' || !str_starts_with($url, $prefix)) {
                continue;
            }
            $id = $webhook['id'] ?? null;
            if (\is_numeric($id) && (int)$id > 0) {
                return (int)$id;
            }
        }

        return null;
    }

    /**
     * The id of a webhook already pointing at this address, or null.
     *
     * This is what keeps a second press of the button from leaving a store with
     * two webhooks posting the same events at the same place, which is the one
     * thing the contract asks of an API that has no upsert.
     *
     * A key that cannot list webhooks answers null here as well, deliberately:
     * the create call that follows will fail with SMTP2GO's own words about the
     * permission, and that is the more useful of the two messages.
     *
     * Zero means one is there and their answer did not number it, which is
     * still "do not add a second one".
     */
    public function webhookAt(string $apiKey, string $url): ?int
    {
        $webhooks = $this->webhooks($apiKey);

        return $webhooks === null ? null : self::idAt($webhooks, $url);
    }

    /**
     * The id of the webhook in this list pointing at exactly this address, or
     * null; zero where one is there and their answer did not number it.
     *
     * @param list<mixed> $webhooks what {@see webhooks()} answered
     */
    public static function idAt(array $webhooks, string $url): ?int
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        foreach ($webhooks as $webhook) {
            if (!\is_array($webhook)) {
                continue;
            }

            if (trim((string)($webhook['url'] ?? '')) !== $url) {
                continue;
            }

            $id = $webhook['id'] ?? null;

            return \is_numeric($id) && (int)$id > 0 ? (int)$id : 0;
        }

        return null;
    }

    /**
     * The account's webhooks, or null when they could not be read at all.
     *
     * Why they could not be read is deliberately not answered: a key that
     * cannot list webhooks is about to be refused by the create call in
     * SMTP2GO's own words, and two messages about one permission is one message
     * too many. Read once and matched twice by the setup, against the exact
     * address and then against the endpoint, so SMTP2GO is asked one question.
     *
     * @return list<mixed>|null
     */
    public function webhooks(string $apiKey): ?array
    {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            return null;
        }

        $answer = $this->http->postJson(self::BASE . '/webhook/view', [], [self::KEY_HEADER => $apiKey]);

        if ($answer['status'] < 200 || $answer['status'] >= 300) {
            return null;
        }

        $body = \is_array($answer['body'] ?? null) ? $answer['body'] : [];
        $data = \is_array($body['data'] ?? null) ? $body['data'] : [];
        $webhooks = $data['webhooks'] ?? [];

        return \is_array($webhooks) ? array_values($webhooks) : [];
    }

    /**
     * What the account says about one sending domain: its DKIM selectors and
     * its custom return paths.
     *
     * `POST /v3/domain/view` answers every sender domain on the account with
     * the CNAMEs each one is meant to have, and the DKIM one is
     * `s<id>._domainkey.<domain>` — the `s<id>` half is the selector. So a
     * store that has set SMTP2GO up at all has already told SMTP2GO its
     * selector, and asking is better than asking the merchant.
     *
     * Three things keep this honest.
     *
     * **It never throws.** A key that has been revoked, an API that is down, a
     * network with no route out: all of them answer the empty pair, and the
     * caller falls back to the configured selector exactly as it would with no
     * key at all. A deliverability screen that 500s because a third party is
     * having an outage is worse than one that says it could not find out.
     *
     * **It is bounded.** Five seconds at the outside, once per domain per
     * request, through {@see CurlHttp}'s own timeouts.
     *
     * **It sends the key and nothing else.** No addresses, no campaign data, no
     * store name. The request is the account's own key asking about the
     * account's own domains.
     *
     * @return array{selectors: list<string>, return_paths: list<string>}
     */
    public function domainFacts(string $apiKey, string $domain): array
    {
        $empty = ['selectors' => [], 'return_paths' => []];

        $apiKey = trim($apiKey);
        $domain = self::normalise($domain);

        if ($apiKey === '' || $domain === '') {
            return $empty;
        }

        if (isset($this->domains[$domain])) {
            return $this->domains[$domain];
        }

        try {
            $answer = $this->http->postJson(self::BASE . '/domain/view', [], [self::KEY_HEADER => $apiKey]);
        } catch (\Throwable) {
            // The contract says a lookup never throws, and an HTTP client that
            // does anyway is still an unanswered question rather than a broken
            // screen. Remembered like any other answer, so a screen drawing two
            // checks does not wait for the same failure twice.
            return $this->domains[$domain] = $empty;
        }

        $body = $answer['body'] ?? null;

        if ($answer['status'] < 200 || $answer['status'] >= 300 || !\is_array($body)) {
            return $this->domains[$domain] = $empty;
        }

        return $this->domains[$domain] = self::factsIn($body, $domain);
    }

    /**
     * The two facts about one domain in SMTP2GO's answer.
     *
     * Their answer nests the sender domains under `data.domains`, each with a
     * `dkim_selector` where the account exposes one and a set of CNAME records
     * either way. Both are read: the explicit field first, then any hostname in
     * the record set that looks like `<selector>._domainkey.<domain>` for the
     * selector, and any that points into their return-path zone for the return
     * path. Reading the records as well as the field is what makes this work
     * across the several ways their API has answered over the years.
     *
     * @param  array<string, mixed> $answer
     * @return array{selectors: list<string>, return_paths: list<string>}
     */
    public static function factsIn(array $answer, string $domain): array
    {
        $domain = self::normalise($domain);
        $data = $answer['data'] ?? [];
        $domains = \is_array($data) ? ($data['domains'] ?? []) : [];

        if (!\is_array($domains)) {
            return ['selectors' => [], 'return_paths' => []];
        }

        $selectors = [];
        $returnPaths = [];

        foreach ($domains as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $name = self::normalise((string)($row['domain'] ?? $row['fulldomain'] ?? ''));
            if ($name !== '' && $name !== $domain && !self::aligns($name, $domain)) {
                continue;
            }

            $explicit = trim((string)($row['dkim_selector'] ?? ''));
            if ($explicit !== '') {
                $selectors[] = $explicit;
            }

            $path = self::normalise((string)($row['return_path'] ?? $row['rpath'] ?? ''));
            if ($path !== '') {
                $returnPaths[] = $path;
            }

            self::readRecords($row, $selectors, $returnPaths);
        }

        return [
            'selectors' => array_values(array_unique($selectors)),
            'return_paths' => array_values(array_unique($returnPaths)),
        ];
    }

    // ------------------------------------------------------------- internals

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $selectors
     * @param list<string>         $returnPaths
     */
    private static function readRecords(array $row, array &$selectors, array &$returnPaths): void
    {
        $records = $row['cnames'] ?? $row['records'] ?? [];
        if (!\is_array($records)) {
            return;
        }

        $zone = Smtp2goProvider::RETURN_PATH_ZONE;

        foreach ($records as $record) {
            $host = '';
            $target = '';

            if (\is_array($record)) {
                $host = (string)($record['hostname'] ?? $record['host'] ?? $record['name'] ?? '');
                $target = (string)($record['value'] ?? $record['target'] ?? $record['data'] ?? '');
            } elseif (is_scalar($record)) {
                $host = (string)$record;
            }

            $host = self::normalise($host);
            if ($host === '') {
                continue;
            }

            if (preg_match('/^([a-z0-9_-]+)\._domainkey\b/', $host, $matches) === 1) {
                $selectors[] = $matches[1];

                continue;
            }

            $target = self::normalise($target);
            if ($target !== '' && ($target === $zone || str_ends_with($target, '.' . $zone))) {
                $returnPaths[] = $host;
            }
        }
    }

    /**
     * Why an answer from one of the webhook endpoints is a refusal, or null
     * where it is not.
     *
     * Their errors nest under `data` on these endpoints rather than sitting at
     * the top level the way the general API reference shows, and a transport
     * failure arrives with status zero and the error on the side.
     *
     * @param array{status: int, body: mixed, error: string} $answer
     */
    private static function refusalIn(array $answer): ?string
    {
        if ($answer['status'] === 0) {
            return $answer['error'] !== ''
                ? 'SMTP2GO could not be reached: ' . $answer['error']
                : 'SMTP2GO could not be reached';
        }

        $body = \is_array($answer['body'] ?? null) ? $answer['body'] : [];
        $data = \is_array($body['data'] ?? null) ? $body['data'] : [];
        $error = trim((string)($data['error'] ?? $body['error'] ?? ''));
        if ($answer['status'] < 200 || $answer['status'] >= 300 || $error !== '') {
            return $error !== ''
                ? 'SMTP2GO refused it: ' . $error
                : sprintf('SMTP2GO answered %d', $answer['status']);
        }

        return null;
    }

    /**
     * @param  list<string> $events
     * @return array{ok: bool, id: null, message: string, events: list<string>}
     */
    private static function no(string $message, array $events): array
    {
        return ['ok' => false, 'id' => null, 'message' => $message, 'events' => $events];
    }

    /**
     * The new webhook's id, wherever their answer put it.
     *
     * Their documented success body echoes the webhook config with an integer
     * `id` on it; some of their endpoints answer a `webhooks` list instead. The
     * id is only used to tell the merchant which webhook was made, so a missing
     * one is a shorter sentence rather than a failure.
     *
     * @param array<string, mixed> $data
     */
    private static function idIn(array $data): ?int
    {
        $id = $data['id'] ?? null;

        if ($id === null && \is_array($data['webhooks'] ?? null)) {
            $first = $data['webhooks'][0] ?? null;
            $id = \is_array($first) ? ($first['id'] ?? null) : null;
        }

        return \is_numeric($id) && (int)$id > 0 ? (int)$id : null;
    }

    /** A host with its case, its trailing dot and its surrounding space taken off. */
    private static function normalise(string $domain): string
    {
        return strtolower(trim(trim($domain), '.'));
    }

    /**
     * Whether two names are the same organisation, in the relaxed sense: equal,
     * or one a subdomain of the other.
     *
     * A store sending as `news@mail.example.com` whose SMTP2GO account lists
     * `example.com` is the same domain for this purpose, and refusing to read
     * its selector because the strings differ would be a check that fails on
     * every store that uses a sending subdomain.
     */
    private static function aligns(string $a, string $b): bool
    {
        $a = self::normalise($a);
        $b = self::normalise($b);

        if ($a === '' || $b === '') {
            return false;
        }

        return $a === $b || str_ends_with($a, '.' . $b) || str_ends_with($b, '.' . $a);
    }
}

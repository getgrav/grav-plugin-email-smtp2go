<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSmtp2go\Provider;

use Grav\Plugin\Email\Providers\DeliveryReports;
use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\Email\Providers\Payload;
use Grav\Plugin\Email\Providers\Verdict;
use Grav\Plugin\Email\Providers\WebhookRequest;

/**
 * SMTP2GO's webhook, read.
 *
 * Documentation: `developers.smtp2go.com/docs/webhooks-overview` and
 * `/docs/setup-a-webhook`. Read 2026-09-04.
 *
 * ## The payload is flat and one event at a time
 *
 * No envelope, no `data` wrapper, no batching: the whole body is one event as a
 * JSON object with `event` naming what happened. Several of the keys are
 * hyphenated — `message-id`, `user-agent`, `read-secs` — which is why
 * everything here is array access rather than anything nicer.
 *
 * ## Hard and soft, in one word
 *
 * `bounce` is present only on a bounce event and is the literal string `hard`
 * or `soft`. SMTP2GO does the classification itself from the receiving server's
 * answer, so there is no code table to interpret and no subtype to fold in.
 * Anything other than those two words is treated as soft, which is the safe
 * direction: a soft bounce counts against the address and a store that keeps
 * count suppresses it soon enough anyway, so a misread classification costs a
 * delay rather than a lost customer.
 *
 * ## Correlation
 *
 * By `Message-ID`, and it needs nothing configured. SMTP2GO's own note on the
 * webhook's `headers` field says "Subject and Message-id headers are sent by
 * default", and their `message-id` is the sender's own header with the angle
 * brackets on. That is the whole correlation path for this provider, and it is
 * the reason SMTP2GO is the one with the nicest setup story.
 *
 * The send header is read as well, and is the fallback for a store whose mail
 * was sent before the id existed or whose message id was rewritten in transit.
 * It only arrives if the header is listed on the webhook's own `headers` field
 * — an unregistered header is not echoed — which is why {@see Smtp2goApi}
 * registers it when it creates the webhook. Their documentation does not say
 * whether an echoed header lands at the top level or under a `custom_headers`
 * object, so both are read; the one that is there wins.
 *
 * ## Nothing is signed
 *
 * SMTP2GO does not sign webhook payloads at all. The secret in the webhook URL
 * is the whole of the authentication, which is why {@see verify()} answers
 * {@see Verdict::unsigned()} rather than claiming a signature it never looked
 * at, and why the store that built that URL is expected to have made it long
 * and to compare it in constant time.
 *
 * Their optional `Authorization` header (`auth_header_type` plus
 * `auth_header_value` in their dashboard) is a second belt a merchant can add
 * by hand, and {@see verify()} checks it when the caller hands one over — but
 * it is not required, because a store that set the webhook up in the dashboard
 * rather than through the button will not have one.
 */
final class Smtp2goReports implements DeliveryReports
{
    /**
     * The header a store stamps its send id into.
     *
     * Named here rather than by the store so the two cannot disagree about it:
     * this is the header {@see Smtp2goSetup} registers on the webhook, and an
     * unregistered header is one SMTP2GO does not echo. It is spelled the way
     * KahunaCart's newsletter add-on has spelled it since it was the only thing
     * reading these webhooks; anything else that wants delivery reports asks
     * for the name rather than assuming one.
     */
    public const SEND_HEADER = 'X-KahunaCart-Send';

    /** The optional Authorization header a merchant may have set by hand. */
    public const AUTH_HEADER_KEY = 'auth_header';

    /** Their event names, in their spelling. */
    public const EVENT_DELIVERED = 'delivered';
    public const EVENT_BOUNCE = 'bounce';
    public const EVENT_SPAM = 'spam';
    public const EVENT_OPEN = 'open';
    public const EVENT_CLICK = 'click';

    /**
     * Their words to ours. Everything they send that is not here —
     * `processed`, `reject`, `unsubscribe`, `resubscribe`, and every `sms_*`
     * event — is a 200 and a log line rather than an error.
     *
     * `unsubscribe` is deliberately not mapped. SMTP2GO reports it when
     * somebody uses *their* unsubscribe link, and a store that carries its own
     * `List-Unsubscribe` is not using theirs: acting on both would be acting
     * twice on one press, and acting on theirs instead would leave the store's
     * own list untouched.
     *
     * `reject` is deliberately not mapped either, and that one is worth a
     * second look one day: SMTP2GO reports it when it refuses to send at all,
     * which is what {@see Event::DROPPED} was added for. Leaving it skipped is
     * what the newsletter's parser did before this moved here, and changing it
     * would start suppressing addresses that are not currently suppressed —
     * a decision for whoever owns the store's suppression rules rather than for
     * a refactor.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        self::EVENT_DELIVERED => Event::DELIVERED,
        self::EVENT_BOUNCE => Event::BOUNCED,
        self::EVENT_SPAM => Event::COMPLAINED,
        self::EVENT_OPEN => Event::OPENED,
        self::EVENT_CLICK => Event::CLICKED,
    ];

    /** @return list<string> */
    public function events(): array
    {
        return array_values(self::TYPES);
    }

    /**
     * None. SMTP2GO signs nothing, so the secret in the URL is the whole of the
     * protection and there is no credential of theirs to keep.
     *
     * @return list<string>
     */
    public function verificationKeys(): array
    {
        return [];
    }

    public function verify(WebhookRequest $request, array $config): Verdict
    {
        $expected = trim((string)($config[self::AUTH_HEADER_KEY] ?? ''));

        // Nothing configured is the normal case: the URL secret already matched
        // and SMTP2GO has nothing else to offer. A store that has set an
        // Authorization header on the webhook by hand gets it checked.
        if ($expected === '') {
            return Verdict::unsigned();
        }

        $given = $request->header('authorization');

        return \strlen($given) === \strlen($expected) && hash_equals($expected, $given)
            ? Verdict::verified()
            : Verdict::refused('the Authorization header did not match');
    }

    public function parse(WebhookRequest $request): Payload
    {
        // `WebhookRequest::json()` deliberately answers a list as well as an
        // object, because SendGrid posts one. SMTP2GO never does, and a list
        // here is somebody else's payload at this address rather than an event
        // with nothing in it — so an empty object passes and a list does not.
        $body = $request->json();
        if ($body === null || ($body !== [] && array_is_list($body))) {
            return Payload::unreadable('the body was not a JSON object');
        }

        $event = strtolower(trim((string)($body['event'] ?? '')));
        $type = self::TYPES[$event] ?? null;

        if ($type === null) {
            return Payload::nothing(sprintf('SMTP2GO reported "%s", which this store does not act on', $event));
        }

        $hard = null;
        if ($type === Event::BOUNCED) {
            $hard = strtolower(trim((string)($body['bounce'] ?? ''))) === 'hard';
        }

        return Payload::of([Event::of(
            $type,
            $hard,
            self::recipient($body),
            (string)($body['message-id'] ?? ''),
            (string)($body['email_id'] ?? ''),
            self::moment($body),
            self::reason($body, $type),
            self::sendId($body),
        )]);
    }

    public function sendHeader(): string
    {
        return self::SEND_HEADER;
    }

    // ------------------------------------------------------------- internals

    /**
     * Who it was about.
     *
     * `rcpt` is the documented single recipient; `recipients` is the list form.
     * A campaign sends one message per person, so the list is one long — but
     * reading it is two lines and it is the difference between a bounce that
     * suppresses an address and one that is logged and dropped.
     *
     * @param array<string, mixed> $body
     */
    private static function recipient(array $body): ?string
    {
        $rcpt = trim((string)($body['rcpt'] ?? ''));
        if ($rcpt !== '') {
            return $rcpt;
        }

        $list = $body['recipients'] ?? null;

        return \is_array($list) && isset($list[0]) ? trim((string)$list[0]) : null;
    }

    /**
     * When it happened.
     *
     * `time` is an ISO 8601 moment. Their documentation shows it with a `Z`,
     * and a payload whose time cannot be read is stamped by the caller with the
     * moment it arrived rather than being refused: the timestamp is for a chart
     * and the fact is for a suppression list, and only one of those two is
     * worth losing an event over.
     *
     * @param array<string, mixed> $body
     */
    private static function moment(array $body): int
    {
        return Moment::parse($body['time'] ?? null) ?? 0;
    }

    /**
     * The provider's own words about why.
     *
     * `message` is the receiving server's answer — "550 5.1.1 … User unknown"
     * — which is exactly what a merchant looking at a suppressed address wants
     * to read. On a complaint there is no such line, so the event says what it
     * is instead.
     *
     * @param array<string, mixed> $body
     */
    private static function reason(array $body, string $type): ?string
    {
        $message = trim((string)($body['message'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        return $type === Event::COMPLAINED ? 'marked as spam' : null;
    }

    /**
     * The send id named by the store's own header, where SMTP2GO echoed it.
     *
     * Two places, because their documentation lists an echoed header inline
     * with the top-level parameters and their activity API returns the same
     * headers nested under `custom_headers`. Whichever is right, this reads it.
     *
     * @param array<string, mixed> $body
     */
    private static function sendId(array $body): ?string
    {
        $header = self::SEND_HEADER;
        $custom = $body['custom_headers'] ?? null;

        $value = $body[$header]
            ?? $body[strtolower($header)]
            ?? (\is_array($custom) ? ($custom[$header] ?? $custom[strtolower($header)] ?? null) : null);

        if (\is_int($value) || \is_float($value)) {
            $value = (string)$value;
        }

        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}

<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSmtp2go\Provider;

use Grav\Plugin\Email\Providers\DeliveryReports;
use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\Email\Providers\Payload;
use Grav\Plugin\Email\Providers\SendHeader;
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
    /** The optional Authorization header a merchant may have set by hand. */
    public const AUTH_HEADER_KEY = 'auth_header';

    /** Their event names, in their spelling. */
    public const EVENT_DELIVERED = 'delivered';
    public const EVENT_BOUNCE = 'bounce';
    public const EVENT_SPAM = 'spam';
    public const EVENT_OPEN = 'open';
    public const EVENT_CLICK = 'click';
    public const EVENT_REJECT = 'reject';

    /**
     * Their words to ours. Everything they send that is not here —
     * `processed`, `unsubscribe`, `resubscribe`, and every `sms_*` event — is a
     * 200 and a log line rather than an error.
     *
     * `reject` is SMTP2GO refusing to send at all, and their own sample message
     * for it is "Recipient address is on the account suppression list". Nothing
     * was handed to a receiving server, so it is not a bounce; it is
     * {@see Event::DROPPED}, which is the word the contract has for exactly
     * this. Whether it was the address or the message that was refused is on
     * the event's `hard`; see {@see refusedAddress()}.
     *
     * `unsubscribe` is deliberately not mapped. SMTP2GO reports it when
     * somebody uses *their* unsubscribe link, and a store that carries its own
     * `List-Unsubscribe` is not using theirs: acting on both would be acting
     * twice on one press, and acting on theirs instead would leave the store's
     * own list untouched.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        self::EVENT_DELIVERED => Event::DELIVERED,
        self::EVENT_BOUNCE => Event::BOUNCED,
        self::EVENT_SPAM => Event::COMPLAINED,
        self::EVENT_OPEN => Event::OPENED,
        self::EVENT_CLICK => Event::CLICKED,
        self::EVENT_REJECT => Event::DROPPED,
    ];

    /**
     * Words in a reject's `message` that name the recipient's address.
     *
     * SMTP2GO's documentation says a reject "happens if you attempt to send an
     * email to a recipient that has previously hard-bounced, made a spam
     * complaint, or unsubscribed", and their own sample message is "Recipient
     * address is on the account suppression list". Every one of those is the
     * address: SMTP2GO will refuse the next message to it too, whatever the
     * next message says.
     *
     * Matched on the words rather than on the whole sentence, because the
     * sentence is theirs to reword and the words are the fact in it.
     *
     * @var list<string>
     */
    public const ADDRESS_WORDS = [
        'suppression list',
        'suppressed',
        'unsubscrib',
        'spam complaint',
        'complained',
        'bounced',
        'hard-bounce',
        'hard bounce',
    ];

    /**
     * Words that name the message or the sender instead, and are checked first.
     *
     * The one SMTP2GO documents is the other half of the same sentence: a
     * reject "is also encountered if sending is attempted from an unverified
     * sender". Nothing there is the recipient's doing, and a store that took
     * somebody off its list for it would be punishing them for the merchant
     * having not finished setting the account up.
     *
     * @var list<string>
     */
    public const MESSAGE_WORDS = [
        'unverified',
        'not verified',
        'sender address',
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

        if ($type === Event::DROPPED) {
            $hard = self::refusedAddress($body);
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
        return SendHeader::name();
    }

    // ------------------------------------------------------------- internals

    /**
     * Whether a reject was SMTP2GO refusing the address or refusing the message.
     *
     * True is the address and false is the message, which is what the contract
     * puts on {@see Event::$hard} for a drop. Their `message` is the only thing
     * a reject carries that says which, so it is read for the words in
     * {@see MESSAGE_WORDS} first and {@see ADDRESS_WORDS} second.
     *
     * Anything that matches neither is false. That is deliberate and it is the
     * asymmetry worth having: a false costs a store one message it will be
     * refused for again, and a wrong true costs it a subscriber for good.
     *
     * @param array<string, mixed> $body
     */
    private static function refusedAddress(array $body): bool
    {
        $why = mb_strtolower(trim((string)($body['message'] ?? '')));
        if ($why === '') {
            return false;
        }

        foreach (self::MESSAGE_WORDS as $word) {
            if (str_contains($why, $word)) {
                return false;
            }
        }

        foreach (self::ADDRESS_WORDS as $word) {
            if (str_contains($why, $word)) {
                return true;
            }
        }

        return false;
    }

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
        return SendHeader::idIn($body) ?? SendHeader::idIn($body['custom_headers'] ?? null);
    }
}

<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSmtp2go\Provider;

/**
 * "When did this happen", out of whatever SMTP2GO felt like sending.
 *
 * Their `time` field is an ISO 8601 moment and their documentation shows it
 * with a `Z` on the end, which `strtotime` reads. Unix seconds are read as well
 * because their activity API has answered with them, and because a moment that
 * arrives as a number and is thrown away is a chart with a gap in it that
 * nobody can explain.
 *
 * Answering null for a payload with no moment at all, rather than `time()`, is
 * the point: the caller stamps a null with the moment the request arrived, and
 * it is the one that has a clock. A parser that reached for `time()` would be a
 * parser a test could not pin.
 *
 * ## The sanity window
 *
 * A moment before 2000 or more than a day in the future is treated as no moment
 * at all. Both turn up in the wild — a zero timestamp from a provider's own
 * placeholder, and a clock skewed forward on a sending host — and both would
 * otherwise put a point at the wrong end of a campaign's chart forever.
 */
final class Moment
{
    /** Nothing before this is a real event. 2000-01-01. */
    public const FLOOR = 946684800;

    /** How far ahead of now a provider's clock may be. */
    public const FUTURE_TOLERANCE = 86400;

    private function __construct()
    {
    }

    /**
     * A moment in seconds, or null when there is not one to be had.
     *
     * @param mixed    $value the raw field, whatever type it arrived as
     * @param int|null $now   the receiver's clock, for the future check; null
     *        skips that check, which is what a parser unit test wants
     */
    public static function parse(mixed $value, ?int $now = null): ?int
    {
        $at = self::read($value);

        if ($at === null || $at < self::FLOOR) {
            return null;
        }

        if ($now !== null && $at > $now + self::FUTURE_TOLERANCE) {
            return null;
        }

        return $at;
    }

    private static function read(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_float($value)) {
            return (int)$value;
        }

        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Unix seconds as a string, with or without a fractional part. Checked
        // before `strtotime`, which reads a bare `1739187601` as a date in the
        // year 1739 on some builds and as nothing at all on others.
        if (preg_match('/^\d{9,11}(\.\d+)?$/', $value) === 1) {
            return (int)$value;
        }

        $at = strtotime($value);

        return $at === false ? null : $at;
    }
}

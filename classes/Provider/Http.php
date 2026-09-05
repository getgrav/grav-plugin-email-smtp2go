<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSmtp2go\Provider;

/**
 * The outbound requests this plugin makes to SMTP2GO's own API, behind one
 * seam.
 *
 * Two of them: creating the store's webhook, and reading a sending domain's
 * DKIM selectors and return paths. Both are the sort of thing a test must be
 * able to answer for itself — a suite that reached the network would be a suite
 * that failed on a train — so the calls go through this and a test hands over a
 * stand-in.
 *
 * Deliberately tiny. There is no redirect policy, no streaming and no header
 * manipulation here, because neither call needs any of it and every one of them
 * would be another thing to get wrong in a class that talks to the outside.
 */
interface Http
{
    /**
     * POST a JSON body and read a JSON answer.
     *
     * A status of 0 means the request never got as far as an answer — no route
     * out, a refused connection, a certificate that did not check out — and
     * `error` says which, in cURL's words, for a merchant reading a failure
     * message.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string> $headers
     * @return array{status: int, body: array<string, mixed>|null, error: string}
     */
    public function postJson(string $url, array $body, array $headers = []): array;
}

<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSmtp2go\Provider;

/**
 * {@see Http} over cURL, with every switch that matters set explicitly.
 *
 * The settings are not defaults and are not decoration:
 *
 * - **HTTPS only.** `CURLPROTO_HTTPS` on both the request and its redirects.
 *   An API key goes out in a header on every one of these calls, and sending
 *   one over plain HTTP because a URL was mistyped would hand it to whoever was
 *   listening.
 * - **Peer and host verification on.** Off by default in nobody's build, and
 *   worth stating anyway: this is the class where turning it off would be quiet
 *   and catastrophic.
 * - **Two redirects.** Enough for an endpoint that moved, not enough to be
 *   walked around a network.
 * - **A response cap.** An API answer here is a few kilobytes. Ten megabytes of
 *   anything is somebody pointing this at a file server, and the write callback
 *   stops reading rather than filling memory.
 * - **Short timeouts.** The domain lookup runs while a settings screen is being
 *   drawn, so the failure mode of a slow API has to be a quick "could not say"
 *   rather than a screen that hangs. Five seconds is generous for a JSON API
 *   that normally answers in well under one.
 */
final class CurlHttp implements Http
{
    /** Seconds to connect. */
    public const CONNECT_TIMEOUT = 3;

    /** Seconds for the whole call. */
    public const TIMEOUT = 5;

    /** Bytes of response body kept before the transfer is abandoned. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly int $timeout = self::TIMEOUT,
        private readonly int $connectTimeout = self::CONNECT_TIMEOUT,
    ) {
    }

    public function postJson(string $url, array $body, array $headers = []): array
    {
        $json = json_encode($body, \JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['status' => 0, 'body' => null, 'error' => 'the request body could not be encoded'];
        }

        $answer = $this->run($url, $json, $headers + [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]);

        $decoded = null;
        if ($answer['raw'] !== null && trim($answer['raw']) !== '') {
            try {
                $parsed = json_decode($answer['raw'], true, 32, \JSON_THROW_ON_ERROR);
                $decoded = \is_array($parsed) ? $parsed : null;
            } catch (\JsonException) {
                $decoded = null;
            }
        }

        return ['status' => $answer['status'], 'body' => $decoded, 'error' => $answer['error']];
    }

    /**
     * @param  array<string, string> $headers
     * @return array{status: int, raw: string|null, error: string}
     */
    private function run(string $url, string $post, array $headers): array
    {
        if (!\function_exists('curl_init')) {
            return ['status' => 0, 'raw' => null, 'error' => 'this installation has no cURL'];
        }

        if (!str_starts_with(strtolower(trim($url)), 'https://')) {
            return ['status' => 0, 'raw' => null, 'error' => 'only https addresses are fetched'];
        }

        $body = '';
        $handle = curl_init($url);
        if ($handle === false) {
            return ['status' => 0, 'raw' => null, 'error' => 'the request could not be started'];
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            \CURLOPT_RETURNTRANSFER => false,
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $post,
            \CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            \CURLOPT_TIMEOUT => $this->timeout,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS => 2,
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_SSL_VERIFYHOST => 2,
            \CURLOPT_PROTOCOLS => \CURLPROTO_HTTPS,
            \CURLOPT_REDIR_PROTOCOLS => \CURLPROTO_HTTPS,
            \CURLOPT_HTTPHEADER => $lines,
            \CURLOPT_WRITEFUNCTION => static function ($_, string $chunk) use (&$body): int {
                $body .= $chunk;

                // Returning fewer bytes than were handed over is how cURL is
                // told to stop, which is the point: a body over the cap is
                // abandoned rather than assembled and then thrown away.
                return \strlen($body) > self::MAX_BYTES ? 0 : \strlen($chunk);
            },
        ]);

        $ok = curl_exec($handle);
        $status = (int)curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        $error = $ok === false ? (string)curl_error($handle) : '';
        curl_close($handle);

        return [
            'status' => $status,
            'raw' => $error === '' || $body !== '' ? $body : null,
            'error' => $error,
        ];
    }
}

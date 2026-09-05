<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSmtp2go\Tests\Support;

use Grav\Plugin\EmailSmtp2go\Provider\Http;

/**
 * {@see Http} with the answers written down in advance.
 *
 * Every call is recorded so a test can say what was sent as well as what came
 * back, which is where the two settings that matter live: a webhook created
 * without `output_format` set to json posts bodies nothing can read, and one
 * created without the send header named echoes no header back. Neither failure
 * shows up anywhere except in the request.
 */
final class FakeHttp implements Http
{
    /** @var list<array{url: string, body: array<string, mixed>, headers: array<string, string>}> */
    public array $calls = [];

    /** @var array<string, list<array{status: int, body: array<string, mixed>|null, error: string}>> */
    private array $answers = [];

    /** @var array{status: int, body: array<string, mixed>|null, error: string} */
    private array $fallback = ['status' => 200, 'body' => ['data' => []], 'error' => ''];

    /**
     * Queue an answer for the next call whose URL ends with this path.
     *
     * @param array<string, mixed>|null $body
     */
    public function willAnswer(string $path, int $status, ?array $body, string $error = ''): self
    {
        $this->answers[$path][] = ['status' => $status, 'body' => $body, 'error' => $error];

        return $this;
    }

    /** Nothing got out at all, which is what a network failure looks like here. */
    public function willFail(string $path, string $error): self
    {
        return $this->willAnswer($path, 0, null, $error);
    }

    public function postJson(string $url, array $body, array $headers = []): array
    {
        $this->calls[] = ['url' => $url, 'body' => $body, 'headers' => $headers];

        foreach ($this->answers as $path => $queued) {
            if ($queued !== [] && str_ends_with($url, $path)) {
                $answer = array_shift($this->answers[$path]);

                return $answer;
            }
        }

        return $this->fallback;
    }

    /** @return array{url: string, body: array<string, mixed>, headers: array<string, string>}|null */
    public function callTo(string $path): ?array
    {
        foreach ($this->calls as $call) {
            if (str_ends_with($call['url'], $path)) {
                return $call;
            }
        }

        return null;
    }

    public function countTo(string $path): int
    {
        $n = 0;
        foreach ($this->calls as $call) {
            if (str_ends_with($call['url'], $path)) {
                $n++;
            }
        }

        return $n;
    }
}

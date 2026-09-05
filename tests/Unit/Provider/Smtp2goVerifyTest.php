<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSmtp2go\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\WebhookRequest;
use Grav\Plugin\EmailSmtp2go\Provider\Smtp2goReports;
use PHPUnit\Framework\TestCase;

/**
 * What SMTP2GO's webhook is protected by, which is not a signature.
 *
 * They sign nothing, anywhere, and there is no setting that changes it. So the
 * secret in the webhook URL is the whole of the authentication, and the honest
 * answer to "was this signed" is `unsigned` rather than `verified` — claiming a
 * signature was checked when none exists is how a store ends up trusting an
 * address anybody could post to.
 *
 * Their dashboard does offer an optional `Authorization` header, which a
 * merchant can set by hand. When one is configured it is checked, and it is
 * checked with `hash_equals` over a length comparison, so a wrong one costs the
 * same as a right one however many characters it got.
 */
final class Smtp2goVerifyTest extends TestCase
{
    /**
     * Accepted: no header configured, which is what almost every store looks
     * like.
     */
    public function testNothingConfiguredIsAPassThatSaysItCheckedNoSignature(): void
    {
        $verdict = (new Smtp2goReports())->verify(self::request(), []);

        self::assertTrue($verdict->ok);
        self::assertFalse($verdict->signed, 'saying this was verified would be claiming a check that never happened');
        self::assertSame('', $verdict->reason);
        self::assertNull($verdict->confirmUrl);
    }

    /** Accepted: a header configured, and the one that arrived matches it. */
    public function testAConfiguredHeaderThatMatchesIsAccepted(): void
    {
        $verdict = (new Smtp2goReports())->verify(
            self::request(['authorization' => 'Bearer a-token-the-merchant-set']),
            ['auth_header' => 'Bearer a-token-the-merchant-set']
        );

        self::assertTrue($verdict->ok);
        self::assertTrue($verdict->signed);
    }

    /** Refused: the wrong key. */
    public function testAConfiguredHeaderThatDoesNotMatchIsRefused(): void
    {
        $verdict = (new Smtp2goReports())->verify(
            self::request(['authorization' => 'Bearer something-else-entirely']),
            ['auth_header' => 'Bearer a-token-the-merchant-set']
        );

        self::assertFalse($verdict->ok);
        self::assertNotSame('', $verdict->reason, 'the store\'s log needs to know which check failed');
    }

    /**
     * Refused: no key at all where one was expected.
     *
     * A request with no `Authorization` header against a store that configured
     * one is exactly the forged request this option exists to stop, so an
     * absent header is a refusal rather than a pass.
     */
    public function testAMissingHeaderIsRefusedWhenOneWasConfigured(): void
    {
        $verdict = (new Smtp2goReports())->verify(self::request(), ['auth_header' => 'Bearer a-token']);

        self::assertFalse($verdict->ok);

        $empty = (new Smtp2goReports())->verify(
            self::request(['authorization' => '']),
            ['auth_header' => 'Bearer a-token']
        );

        self::assertFalse($empty->ok, 'a header that arrived empty is not the token either');
    }

    /**
     * A header configured with nothing but space in it is not a header
     * configured.
     *
     * A merchant who cleared the field should get the ordinary unsigned pass
     * rather than a webhook that silently refuses everything.
     */
    public function testAnEmptyConfiguredValueIsTheSameAsNoneAtAll(): void
    {
        $verdict = (new Smtp2goReports())->verify(self::request(), ['auth_header' => '   ']);

        self::assertTrue($verdict->ok);
        self::assertFalse($verdict->signed);
    }

    /**
     * The comparison is constant time.
     *
     * Asserted on the source rather than by timing, because timing a PHP
     * function on a shared machine is a flaky test that tells you about the
     * machine. What is worth pinning is that nobody ever replaces this with
     * `===`.
     */
    public function testTheHeaderIsComparedInConstantTime(): void
    {
        $source = (string)file_get_contents(
            \dirname(__DIR__, 3) . '/classes/Provider/Smtp2goReports.php'
        );

        self::assertStringContainsString('hash_equals(', $source);
    }

    // ------------------------------------------------------------- internals

    /** @param array<string, string> $headers */
    private static function request(array $headers = []): WebhookRequest
    {
        return new WebhookRequest(
            headers: $headers,
            body: '{}',
            remoteAddress: '203.0.113.7',
        );
    }
}

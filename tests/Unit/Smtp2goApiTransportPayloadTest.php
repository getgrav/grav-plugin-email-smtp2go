<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSmtp2go\Tests\Unit;

use Grav\Plugin\EmailSmtp2go\Transport\Smtp2goApiTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The SMTP transport hands the whole message to the server, so anything set on
 * it arrives. The API transport rebuilds the message as JSON, so a header only
 * arrives if getPayload() puts it in custom_headers - and that is what these
 * tests hold in place.
 */
final class Smtp2goApiTransportPayloadTest extends TestCase
{
    public function testCustomHeadersCarryEveryHeaderTheCallerAddedExactlyOnce(): void
    {
        $email = (new Email())
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->replyTo('replies@example.com')
            ->subject('Campaign')
            ->text('Body');

        $headers = $email->getHeaders();
        $headers->addTextHeader('List-Unsubscribe', '<https://example.com/unsubscribe?t=abc>, <mailto:unsubscribe@example.com>');
        $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        $headers->addTextHeader('X-Test', 'test-value');

        $custom = $this->customHeaders($email);

        self::assertSame(
            [
                'List-Unsubscribe' => 1,
                'List-Unsubscribe-Post' => 1,
                'Reply-To' => 1,
                'X-Test' => 1,
            ],
            $this->counts($custom),
            'Each header should appear once, with its original case, and Reply-To should not be added twice.'
        );

        self::assertSame(['<https://example.com/unsubscribe?t=abc>, <mailto:unsubscribe@example.com>'], $custom['List-Unsubscribe']);
        self::assertSame(['List-Unsubscribe=One-Click'], $custom['List-Unsubscribe-Post']);
        self::assertSame(['test-value'], $custom['X-Test']);
        self::assertSame(['replies@example.com'], $custom['Reply-To']);
    }

    /**
     * Headers the payload already expresses in a field of its own must not come
     * back a second time as custom headers. Return-Path is the envelope's
     * rather than the message's: SMTP2GO writes its own so that bounces reach
     * them, and ours would only duplicate it on the delivered mail.
     */
    public function testHeadersThePayloadAlreadyExpressesAreNotRepeatedAsCustomHeaders(): void
    {
        $email = (new Email())
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->cc('copy@example.com')
            ->bcc('blind@example.com')
            ->returnPath('bounces@example.com')
            ->subject('Campaign')
            ->text('Body');

        $email->getHeaders()->addTextHeader('X-Test', 'test-value');

        self::assertSame(['X-Test' => 1], $this->counts($this->customHeaders($email)));
    }

    public function testAMessageWithNoExtraHeadersSendsNoCustomHeaders(): void
    {
        $email = (new Email())
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->subject('Campaign')
            ->text('Body');

        self::assertArrayNotHasKey('custom_headers', $this->payload($email));
    }

    /**
     * Every custom header in the payload, grouped by name so that a header sent
     * twice shows up as two values rather than quietly overwriting the first.
     *
     * @return array<string, list<string>>
     */
    private function customHeaders(Email $email): array
    {
        $custom = [];
        foreach ($this->payload($email)['custom_headers'] ?? [] as $entry) {
            $custom[$entry['header']][] = $entry['value'];
        }

        return $custom;
    }

    /**
     * @param array<string, list<string>> $custom
     * @return array<string, int>
     */
    private function counts(array $custom): array
    {
        $counts = array_map('count', $custom);
        ksort($counts);

        return $counts;
    }

    /**
     * getPayload() is protected, so it is reached through a subclass rather
     * than by making a real request.
     *
     * @return array<string, mixed>
     */
    private function payload(Email $email): array
    {
        $envelope = new Envelope(new Address('sender@example.com'), [new Address('recipient@example.com')]);

        return (new class ('api-test-key') extends Smtp2goApiTransport {
            /**
             * @return array<string, mixed>
             */
            public function payloadFor(Email $email, Envelope $envelope): array
            {
                return $this->getPayload($email, $envelope);
            }
        })->payloadFor($email, $envelope);
    }
}

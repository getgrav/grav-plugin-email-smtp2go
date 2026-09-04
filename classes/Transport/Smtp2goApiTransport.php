<?php

namespace Grav\Plugin\EmailSmtp2go\Transport;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class Smtp2goApiTransport extends AbstractApiTransport
{
    private const HOST = 'api.smtp2go.com';
    private const ENDPOINT = '/v3/email/send';

    private $key;

    public function __construct(string $key, ?HttpClientInterface $client = null, ?EventDispatcherInterface $dispatcher = null, ?LoggerInterface $logger = null)
    {
        $this->key = $key;

        parent::__construct($client, $dispatcher, $logger);
    }

    public function __toString(): string
    {
        return sprintf('smtp2go+api://%s', $this->getEndpoint());
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        $response = $this->client->request('POST', sprintf('https://%s%s', $this->getEndpoint(), self::ENDPOINT), [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Smtp2go-Api-Key' => $this->key,
            ],
            'json' => $this->getPayload($email, $envelope),
        ]);

        try {
            $statusCode = $response->getStatusCode();
            $result = $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            throw new HttpTransportException('Could not reach the remote SMTP2GO server.', $response, 0, $e);
        } catch (DecodingExceptionInterface $e) {
            throw new HttpTransportException('Unable to decode SMTP2GO response: '.$response->getContent(false).sprintf(' (code %d).', $statusCode), $response, 0, $e);
        }

        if (200 !== $statusCode || !empty($result['data']['error']) || !empty($result['data']['error_code'])) {
            $error = $result['data']['error'] ?? $result['data']['error_code'] ?? 'Unknown error';
            $fieldValidationErrors = $result['data']['field_validation_errors'] ?? null;
            if (is_array($fieldValidationErrors)) {
                $error .= ' ('.json_encode($fieldValidationErrors).')';
            }

            throw new HttpTransportException(sprintf('Unable to send an email via SMTP2GO: %s (code %d).', $error, $statusCode), $response);
        }

        if (!empty($result['data']['email_id'])) {
            $sentMessage->setMessageId($result['data']['email_id']);
        } elseif (!empty($result['request_id'])) {
            $sentMessage->setMessageId($result['request_id']);
        }

        return $response;
    }

    protected function getPayload(Email $email, Envelope $envelope): array
    {
        $addressStringifier = static function (Address $address): string {
            $name = $address->getName();
            return $name !== '' ? sprintf('"%s" <%s>', str_replace('"', '', $name), $address->getAddress()) : $address->getAddress();
        };

        $payload = [
            'sender' => $addressStringifier($envelope->getSender()),
            'to' => array_map($addressStringifier, $this->getRecipients($email, $envelope)),
            'subject' => $email->getSubject(),
        ];

        if ($email->getTextBody() !== null && $email->getTextBody() !== '') {
            $payload['text_body'] = $email->getTextBody();
        }
        if ($email->getHtmlBody() !== null && $email->getHtmlBody() !== '') {
            $payload['html_body'] = $email->getHtmlBody();
        }

        // CC / BCC come from headers when not handled by envelope
        if ($emails = array_map($addressStringifier, $email->getCc())) {
            $payload['cc'] = $emails;
        }
        if ($emails = array_map($addressStringifier, $email->getBcc())) {
            $payload['bcc'] = $emails;
        }

        if ($replyTo = $email->getReplyTo()) {
            // SMTP2GO accepts a single reply-to inside custom_headers
            $payload['custom_headers'][] = [
                'header' => 'Reply-To',
                'value' => $addressStringifier($replyTo[0]),
            ];
        }

        $attachments = [];
        $inlines = [];
        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $filename = $headers->getHeaderParameter('Content-Disposition', 'filename') ?: 'file';
            $disposition = $headers->getHeaderBody('Content-Disposition');
            $contentType = $headers->get('Content-Type') ? $headers->get('Content-Type')->getBody() : 'application/octet-stream';

            $part = [
                'filename' => $filename,
                'fileblob' => base64_encode($attachment->getBody()),
                'mimetype' => $contentType,
            ];

            if ('inline' === $disposition) {
                $inlines[] = $part;
            } else {
                $attachments[] = $part;
            }
        }
        if ($attachments) {
            $payload['attachments'] = $attachments;
        }
        if ($inlines) {
            $payload['inlines'] = $inlines;
        }

        $tags = [];

        // Headers the payload already expresses in a field of its own, plus the
        // ones SMTP2GO writes itself. Everything else on the message is carried
        // through verbatim, with its original case, so that headers a caller
        // added by hand - List-Unsubscribe and List-Unsubscribe-Post on a bulk
        // send, Precedence, any X- header - reach the recipient on the API path
        // exactly as they would over SMTP.
        //
        // Return-Path belongs to the envelope rather than the message: SMTP2GO
        // sets its own so that bounces come back to them, and sending ours in
        // custom_headers only risks a duplicate on the delivered mail.
        $skipHeaders = ['from', 'to', 'cc', 'bcc', 'subject', 'reply-to', 'sender', 'date', 'message-id', 'mime-version', 'content-type', 'content-transfer-encoding', 'return-path'];
        foreach ($email->getHeaders()->all() as $header) {
            if ($header instanceof TagHeader) {
                $tags[] = mb_substr($header->getValue(), 0, 255);
                continue;
            }
            if (in_array(strtolower($header->getName()), $skipHeaders, true)) {
                continue;
            }
            $payload['custom_headers'][] = [
                'header' => $header->getName(),
                'value' => $header->getBodyAsString(),
            ];
        }

        if ($tags) {
            // SMTP2GO does not have native tags, expose via custom header so they show up in raw message
            $payload['custom_headers'][] = [
                'header' => 'X-Smtp2go-Tags',
                'value' => implode(',', $tags),
            ];
        }

        return $payload;
    }

    private function getEndpoint(): string
    {
        return ($this->host ?: self::HOST).($this->port ? ':'.$this->port : '');
    }
}

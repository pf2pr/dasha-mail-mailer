<?php

declare(strict_types=1);

namespace Pf2Pr\DashaMailMailer\Transport;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function in_array;

/**
 * @see https://docs.dashamail.ru
 */
final class DashaMailApiTransport extends AbstractApiTransport
{
    public function __construct(
        #[SensitiveParameter] private readonly string $key,
        private readonly bool $noTrackOpens = false,
        private readonly bool $noTrackClicks = false,
        HttpClientInterface $client = null,
        EventDispatcherInterface $dispatcher = null,
        LoggerInterface $logger = null
    ) {
        parent::__construct($client, $dispatcher, $logger);
    }

    public function __toString(): string
    {
        return sprintf('dashamail+api://%s', $this->getEndpoint());
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        $body = new FormDataPart($this->getPayload($email, $envelope));

        $headers = [];
        foreach ($body->getPreparedHeaders()->all() as $header) {
            $headers[] = $header->toString();
        }

        $statusCode = null;
        $response   = $this->client->request(
            'POST',
            'https://' . $this->getEndpoint() . '/?method=transactional.send&api_key=' . $this->key,
            [
                'headers' => $headers,
                'body'    => $body->bodyToIterable(),
            ]
        );

        try {
            $statusCode = $response->getStatusCode();
            $result     = $response->toArray(false);
        } catch (DecodingExceptionInterface) {
            throw new HttpTransportException(
                'Unable to send an email: ' . $response->getContent(false) . sprintf(' (code %d).', $statusCode),
                $response
            );
        } catch (TransportExceptionInterface $e) {
            throw new HttpTransportException('Could not reach the remote Mailgun server.', $response, 0, $e);
        }

        // todo check response err_code
        if (200 !== $statusCode) {
            throw new HttpTransportException(
                'Unable to send an email: ' . $result['message'] . sprintf(' (code %d).', $statusCode),
                $response
            );
        }

        if (!isset($result['response']['data']['transaction_id'])) {
            throw new HttpTransportException(
                'Unable to get message id',
                $response
            );
        }

        // todo check if isset
        $sentMessage->setMessageId($result['response']['data']['transaction_id']);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function getPayload(Email $email, Envelope $envelope): array
    {
        [$attachments, $inlines] = $this->prepareAttachments($email);

        $payload = [
            'format'          => 'json',
            'no_track_opens'  => $this->noTrackOpens ? 'true' : 'false',
            'no_track_clicks' => $this->noTrackClicks ? 'true' : 'false',
            'from_email'      => $envelope->getSender()->getAddress(),
            'to'              => implode(',', $this->stringifyAddresses($this->getRecipients($email, $envelope))),
            'subject'         => $email->getSubject(),
            'attachments'     => $attachments,
            'inline'          => $inlines,
        ];

        if ('' !== $envelope->getSender()->getName()) {
            $payload['from_name'] = $envelope->getSender()->getName();
        }

        if ($cc = $email->getCc()) {
            $payload['cc'] = implode(',', $this->stringifyAddresses($cc));
        }

        if ($bcc = $email->getBcc()) {
            $payload['bcc'] = implode(',', $this->stringifyAddresses($bcc));
        }

        if ($email->getTextBody()) {
            $payload['plain_text'] = $email->getTextBody();
        }

        if ($email->getHtmlBody()) {
            $payload['message'] = $email->getHtmlBody();
        }

        if ($headers = $this->prepareHeaders($email->getHeaders())) {
            $payload = array_merge($payload, $headers);
        }

        return $payload;
    }

    /**
     * @return array<mixed>
     */
    private function prepareAttachments(Email $email): array
    {
        $attachments = $inlines = [];
        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            if ('inline' === $headers->getHeaderBody('Content-Disposition')) {
                $inlines[] = [
                    'mime_type' => $attachment->getMediaType() . '/' . $attachment->getMediaSubtype(),
                    'filename'  => $attachment->getFilename(),
                    'body'      => $attachment->bodyToString(),
                    'cid'       => $attachment->getFilename(),
                ];
            } else {
                $attachments[] = $attachment;
            }
        }

        return [
            $attachments,
            json_encode($inlines),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareHeaders(Headers $headers): array
    {
        $headersAndTags  = [];
        $headersToBypass = [
            'from',
            'sender',
            'to',
            'cc',
            'bcc',
            'subject',
            'reply-to',
            'content-type',
            'accept',
            'api-key',
        ];
        foreach ($headers->all() as $name => $header) {
            if (in_array($name, $headersToBypass, true)) {
                continue;
            }
            if ($header instanceof TagHeader) {
                $headersAndTags['headers'][] = $header->getValue();

                continue;
            }
            if ($header instanceof MetadataHeader) {
                $headersAndTags['headers'][$header->getKey()] = $header->getValue();

                continue;
            }

            $headersAndTags['headers'][$header->getName()] = $header->getBodyAsString();
        }

        return $headersAndTags;
    }

    private function getEndpoint(): string
    {
        return ($this->host ?: 'api.dashamail.com') . ($this->port ? ':' . $this->port : '');
    }
}

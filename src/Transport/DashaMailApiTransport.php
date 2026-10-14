<?php

declare(strict_types=1);

namespace Pf2Pr\DashaMailMailer\Transport;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function in_array;

/**
 * @see https://dashamail.ru/api/transactional/
 */
final class DashaMailApiTransport extends AbstractApiTransport
{
    private const API_OPTION_HEADERS = [
        'dm-message-id'         => 'message_id',
        'dm-campaign-id'        => 'campaign_id',
        'dm-delivery-timestamp' => 'delivery_time',
        'dm-domain'             => 'domain',
    ];

    private const API_FLAG_HEADERS = [
        'dm-ignore-delivery-policy' => 'ignore_delivery_policy',
        'dm-no-track-opens'         => 'no_track_opens',
        'dm-no-track-clicks'        => 'no_track_clicks',
    ];


    public function __construct(
        #[\SensitiveParameter] private readonly string $key,
        private readonly bool $noTrackOpens = false,
        private readonly bool $noTrackClicks = false,
        ?HttpClientInterface $client = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct($client, $dispatcher, $logger);
    }

    public function __toString(): string
    {
        return sprintf('dashamail+api://%s', $this->getEndpoint());
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        $statusCode = null;
        $response   = $this->client->request(
            'POST',
            'https://' . $this->getEndpoint() . '/v2/transactional/messages',
            [
                'auth_bearer' => $this->key,
                'json'        => $this->getPayload($email, $envelope),
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
            throw new HttpTransportException('Could not reach the remote DashaMail server.', $response, 0, $e);
        }

        if (201 !== $statusCode) {
            $error = $result['error']['message'] ?? $result['message'] ?? $result['error'] ?? $response->getContent(false);

            throw new HttpTransportException(
                'Unable to send an email: ' . (is_scalar($error) ? (string) $error : $response->getContent(false)) . sprintf(' (code %d).', $statusCode),
                $response
            );
        }

        if (isset($result['response']['data']['transaction_id'])) {
            $sentMessage->setMessageId($result['response']['data']['transaction_id']);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function getPayload(Email $email, Envelope $envelope): array
    {
        [$attachments, $inlines] = $this->prepareAttachments($email);

        $payload = [
            'no_track_opens'  => $this->noTrackOpens,
            'no_track_clicks' => $this->noTrackClicks,
            'from_email'      => $envelope->getSender()->getEncodedAddress(),
            'to'              => implode(',', $this->stringifyAddresses($this->getRecipients($email, $envelope))),
            'subject'         => $email->getSubject(),
        ];

        $payload = array_replace($payload, $this->prepareDashaMailOptions($email->getHeaders()));

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
            $payload['headers'] = json_encode($headers, JSON_THROW_ON_ERROR);
        }

        if ($attachments) {
            $payload['attachments'] = json_encode($attachments, JSON_THROW_ON_ERROR);
        }

        if ($inlines) {
            $payload['inline'] = json_encode($inlines, JSON_THROW_ON_ERROR);
        }

        return $payload;
    }

    /**
     * @return array{
     *     list<array{name: string, filebody: string}>,
     *     list<array{mime_type: string, filename: string, body: string, cid: string}>
     * }
     */
    private function prepareAttachments(Email $email): array
    {
        $attachments = $inlines = [];
        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            if ('inline' === $headers->getHeaderBody('Content-Disposition')) {
                $inlines[] = [
                    'mime_type' => $attachment->getMediaType() . '/' . $attachment->getMediaSubtype(),
                    'filename'  => $attachment->getFilename() ?? 'inline',
                    'body'      => base64_encode($attachment->getBody()),
                    'cid'       => $attachment->getContentId(),
                ];
            } else {
                $attachments[] = [
                    'name'     => $attachment->getFilename() ?? 'attachment',
                    'filebody' => base64_encode($attachment->getBody()),
                ];
            }
        }

        return [$attachments, $inlines];
    }

    /**
     * @return array<string, bool|string>
     */
    private function prepareDashaMailOptions(Headers $headers): array
    {
        $options = [];
        foreach (self::API_OPTION_HEADERS as $headerName => $parameterName) {
            if (null !== $header = $headers->get($headerName)) {
                $options[$parameterName] = $header->getBodyAsString();
            }
        }

        foreach (self::API_FLAG_HEADERS as $headerName => $parameterName) {
            if (null !== $headers->get($headerName)) {
                $options[$parameterName] = true;
            }
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareHeaders(Headers $headers): array
    {
        $result          = [];
        $headersToBypass = [
            'from',
            'sender',
            'to',
            'cc',
            'bcc',
            'subject',
            'content-type',
            'accept',
            'dm-message-id',
            'dm-campaign-id',
            'dm-delivery-timestamp',
            'dm-domain',
            'dm-ignore-delivery-policy',
            'dm-no-track-opens',
            'dm-no-track-clicks',
        ];
        foreach ($headers->all() as $name => $header) {
            if (in_array($name, $headersToBypass, true)) {
                continue;
            }
            if ($header instanceof TagHeader) {
                $result['X-Tag'][] = $header->getValue();

                continue;
            }
            if ($header instanceof MetadataHeader) {
                $result['X-Metadata-' . $header->getKey()] = $header->getValue();

                continue;
            }

            $result[$header->getName()] = $header->getBodyAsString();
        }

        return $result;
    }

    private function getEndpoint(): string
    {
        return ($this->host ?: 'api.dashamail.com') . ($this->port ? ':' . $this->port : '');
    }
}

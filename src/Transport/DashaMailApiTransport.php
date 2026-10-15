<?php

declare(strict_types=1);

namespace Pf2Pr\DashaMailMailer\Transport;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Part\AbstractPart;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\Multipart\MixedPart;
use Symfony\Component\Mime\Part\Multipart\RelatedPart;
use Symfony\Component\Mime\Part\TextPart;
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
        $response = null;

        try {
            $response = $this->client->request(
                'POST',
                'https://' . $this->getEndpoint() . '/v2/transactional/messages',
                [
                    'auth_bearer' => $this->key,
                    'buffer'      => true,
                    'json'        => $this->getPayload($this->normalizeEmail($email), $envelope),
                ]
            );
            $statusCode = $response->getStatusCode();
            $result     = $response->toArray(false);
        } catch (DecodingExceptionInterface) {
            throw new HttpTransportException(
                'Unable to send an email: ' . $response->getContent(false) . sprintf(' (code %d).', $statusCode),
                $response
            );
        } catch (TransportExceptionInterface $e) {
            if (null === $response) {
                throw new TransportException('Could not reach the remote DashaMail server.', 0, $e);
            }

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
        $text = $this->normalizeBody($email->getTextBody(), $email->getTextCharset());
        $html = $this->normalizeBody($email->getHtmlBody(), $email->getHtmlCharset());

        if (null !== $html) {
            $html = $this->resolveInlineReferences($html, $email->getAttachments());
        }

        [$attachments, $inlines] = $this->prepareAttachments($email);
        [$to, $cc, $bcc] = $this->prepareRecipients($email, $envelope);
        $from = $email->getFrom()[0] ?? $email->getSender() ?? $envelope->getSender();

        $payload = [
            'no_track_opens'  => $this->noTrackOpens,
            'no_track_clicks' => $this->noTrackClicks,
            'from_email'      => $from->getEncodedAddress(),
            'to'              => implode(',', $this->stringifyAddresses($to)),
            'subject'         => $email->getSubject(),
        ];

        $payload = array_replace($payload, $this->prepareDashaMailOptions($email->getHeaders()));

        if ('' !== $from->getName()) {
            $payload['from_name'] = $from->getName();
        }

        if ($cc) {
            $payload['cc'] = implode(',', $this->stringifyAddresses($cc));
        }

        if ($bcc) {
            $payload['bcc'] = implode(',', $this->stringifyAddresses($bcc));
        }

        if (null !== $text) {
            $payload['plain_text'] = $text;
        }

        if (null !== $html) {
            $payload['message'] = $html;
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

    private function normalizeEmail(Email $email): Email
    {
        $email = clone $email;
        $text = $this->normalizeBody($email->getTextBody(), $email->getTextCharset());
        $html = $this->normalizeBody($email->getHtmlBody(), $email->getHtmlCharset());
        if (null !== $html) {
            // Resolve whole URLs before Mime can replace a filename inside another CID.
            $html = $this->resolveInlineReferences($html, $email->getAttachments());
        }
        $email->text($text)->html($html);

        $normalized = new Email($email->getHeaders());
        $this->normalizePart($this->getMimeBody($email), $normalized);

        return $normalized;
    }

    private function getMimeBody(Email $email): AbstractPart
    {
        $attachmentState = [];
        foreach ($email->getAttachments() as $attachment) {
            $attachmentState[] = [$attachment, $attachment->getName(), $attachment->getDisposition()];
            if (null !== $attachment->getName()) {
                $attachment->setName('');
            }
        }

        try {
            // URLs are already resolved. Do not let Mime resolve filename aliases again.
            return $email->getBody();
        } finally {
            foreach ($attachmentState as [$attachment, $name, $disposition]) {
                if (null !== $name) {
                    $attachment->setName($name);
                }
                if (null !== $disposition) {
                    $attachment->setDisposition($disposition);
                }
            }
        }
    }

    private function normalizePart(AbstractPart $part, Email $email): void
    {
        if ($part instanceof DataPart) {
            $email->addPart(clone $part);

            return;
        }

        if ($part instanceof MixedPart || $part instanceof RelatedPart || $part instanceof AlternativePart) {
            foreach ($part->getParts() as $child) {
                $this->normalizePart($child, $email);
            }

            return;
        }

        if (!$part instanceof TextPart || !in_array($part->getMediaSubtype(), ['plain', 'html'], true)) {
            throw new TransportException('Unable to send an email with an unsupported MIME body.');
        }

        $html = 'html' === $part->getMediaSubtype();
        if (null !== ($html ? $email->getHtmlBody() : $email->getTextBody())) {
            throw new TransportException('Unable to send an email with multiple MIME bodies of the same type.');
        }

        // Capture the body before getBody() can consume a non-seekable "0" stream.
        $encodedBody = '';
        foreach ($part->bodyToIterable() as $chunk) {
            $encodedBody .= $chunk;
        }

        $body = $part->getBody();
        $headers = $part->getPreparedHeaders();
        if ('' === $body) {
            $body = match ($headers->getHeaderBody('Content-Transfer-Encoding')) {
                'base64' => base64_decode($encodedBody, true),
                'quoted-printable' => quoted_printable_decode($encodedBody),
                default => $encodedBody,
            };
            if (false === $body) {
                throw new TransportException('Unable to decode the MIME body.');
            }
        }
        $charset = $headers->getHeaderParameter('Content-Type', 'charset') ?? 'utf-8';
        if ($html) {
            $email->html($body, $charset);
        } else {
            $email->text($body, $charset);
        }
    }

    /**
     * @param list<DataPart> $attachments
     */
    private function resolveInlineReferences(string $html, array $attachments): string
    {
        $references = [];
        foreach ($attachments as $attachment) {
            if ($attachment->hasContentId()) {
                $references[$attachment->getContentId()] ??= $attachment;
            }
        }
        foreach ($attachments as $attachment) {
            if (null !== $name = $attachment->getName()) {
                $references[$name] ??= $attachment;
            }
        }

        if (!$references) {
            return $html;
        }

        // Keep text, comments and unrelated attribute values out of URL rewriting.
        return preg_replace_callback(
            <<<'REGEX'
            ~<!--.*?-->
            |<(?<raw>script|textarea|title)(?=[\s/>])(?:[^>"']|"[^"]*"|'[^']*')*>.*?</\k<raw>\s*>
            |(?<style><style(?=[\s/>])(?:[^>"']|"[^"]*"|'[^']*')*>)(?<css>.*?)(?<styleEnd></style\s*>)
            |<(?<tag>[a-z][a-z0-9:-]*)(?<attributes>(?:[^>"']|"[^"]*"|'[^']*')*)>
            ~isx
            REGEX,
            function (array $match) use ($references): string {
                if ('' !== ($match['style'] ?? '')) {
                    return $match['style'] . $this->resolveInlineCss($match['css'], $references) . $match['styleEnd'];
                }
                if ('' === ($match['tag'] ?? '')) {
                    return $match[0];
                }

                $attributes = preg_replace_callback(
                    <<<'REGEX'
                    ~(?<prefix>\s+(?<name>[^\s=/>]+)\s*=\s*)(?:(?<quote>["'])(?<quoted>.*?)\k<quote>|(?<unquoted>[^\s>]+))~s
                    REGEX,
                    function (array $attribute) use ($match, $references): string {
                        $name = strtolower($attribute['name']);
                        $quote = $attribute['quote'];
                        $value = '' !== $quote ? $attribute['quoted'] : $attribute['unquoted'];

                        if ('style' === $name) {
                            $value = $this->resolveInlineCss($value, $references);
                        } elseif ('background' === $name || ('src' === $name && 'img' === strtolower($match['tag']))) {
                            $value = $this->resolveInlineUrl($value, $references);
                        } else {
                            return $attribute[0];
                        }

                        return $attribute['prefix'] . $quote . $value . $quote;
                    },
                    $match['attributes']
                );

                return '<' . $match['tag'] . $attributes . '>';
            },
            $html
        );
    }

    /**
     * @param array<string|int, DataPart> $references
     */
    private function resolveInlineCss(string $css, array $references): string
    {
        return preg_replace_callback(
            <<<'REGEX'
            ~(?<prefix>\burl\(\s*)(?:(?<quote>["'])(?<quoted>.*?)\k<quote>|(?<unquoted>[^\s"'()]*))(?<suffix>\s*\))
            |/\*.*?\*/
            |"(?:\\.|[^"\\])*"
            |'(?:\\.|[^'\\])*'
            ~isx
            REGEX,
            function (array $match) use ($references): string {
                if ('' === ($match['prefix'] ?? '')) {
                    return $match[0];
                }
                $quote = $match['quote'];
                $url = '' !== $quote ? $match['quoted'] : $match['unquoted'];

                return $match['prefix'] . $quote . $this->resolveInlineUrl($url, $references) . $quote . $match['suffix'];
            },
            $css
        );
    }

    /**
     * @param array<string|int, DataPart> $references
     */
    private function resolveInlineUrl(string $url, array $references): string
    {
        if (0 !== strncasecmp($url, 'cid:', 4) || !isset($references[substr($url, 4)])) {
            return $url;
        }

        $attachment = $references[substr($url, 4)];
        $attachment->asInline();

        return 'cid:' . $attachment->getContentId();
    }

    /**
     * @param resource|string|null $body
     */
    private function normalizeBody(mixed $body, ?string $charset): ?string
    {
        if (null === $body) {
            return null;
        }

        if (is_resource($body)) {
            if (stream_get_meta_data($body)['seekable']) {
                rewind($body);
            }
            $body = stream_get_contents($body);
            if (false === $body) {
                throw new TransportException('Unable to read the email body.');
            }
        }

        return null === $charset || 0 === strcasecmp($charset, 'utf-8')
            ? $body
            : mb_convert_encoding($body, 'UTF-8', $charset);
    }

    /**
     * @return array{array<string, Address>, array<string, Address>, array<string, Address>}
     */
    private function prepareRecipients(Email $email, Envelope $envelope): array
    {
        $recipients = [];
        foreach ($envelope->getRecipients() as $recipient) {
            $recipients[$recipient->getEncodedAddress()] = $recipient;
        }

        $cc = $bcc = [];
        foreach ($email->getCc() as $recipient) {
            $address = $recipient->getEncodedAddress();
            if (isset($recipients[$address])) {
                $cc[$address] = $recipient;
            }
        }
        foreach ($email->getBcc() as $recipient) {
            $address = $recipient->getEncodedAddress();
            if (isset($recipients[$address])) {
                $bcc[$address] = $recipient;
            }
        }

        return [array_diff_key($recipients, $cc, $bcc), $cc, $bcc];
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
            $body = $this->prepareAttachmentBody($attachment);
            if ('inline' === $headers->getHeaderBody('Content-Disposition')) {
                $inlines[] = [
                    'mime_type' => $attachment->getMediaType() . '/' . $attachment->getMediaSubtype(),
                    'filename'  => $attachment->getFilename() ?? 'inline',
                    'body'      => $body,
                    'cid'       => $attachment->getContentId(),
                ];
            } else {
                $attachments[] = [
                    'name'     => $attachment->getFilename() ?? 'attachment',
                    'filebody' => $body,
                ];
            }
        }

        return [$attachments, $inlines];
    }

    private function prepareAttachmentBody(DataPart $attachment): string
    {
        if (DataPart::class !== $attachment::class && TextPart::class !== (new \ReflectionMethod($attachment, 'getBody'))->getDeclaringClass()->getName()) {
            return base64_encode($attachment->getBody());
        }

        // Symfony's getBody() loses streamed "0"; MIME encoders can change line endings.
        // No public raw-stream getter exists, so keep this upstream workaround isolated.
        static $bodyProperty = null;
        $bodyProperty ??= new \ReflectionProperty(TextPart::class, 'body');
        $body = $bodyProperty->getValue($attachment);
        if (!is_resource($body)) {
            return base64_encode($attachment->getBody());
        }

        if (stream_get_meta_data($body)['seekable'] && 0 === fseek($body, 0, SEEK_CUR)) {
            rewind($body);
        }
        $body = stream_get_contents($body);
        if (false === $body) {
            throw new TransportException('Unable to read the attachment body.');
        }

        return base64_encode($body);
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

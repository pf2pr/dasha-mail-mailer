<?php

declare(strict_types=1);

namespace Pf2Pr\DashaMailMailer\Tests\Transport;

use DateTimeImmutable;
use DateTimeInterface;
use Pf2Pr\DashaMailMailer\Transport\DashaMailApiTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\Exception\TransportException as HttpClientTransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\EventListener\EnvelopeListener;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\Transport\FailoverTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\AbstractPart;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\Multipart\MixedPart;
use Symfony\Component\Mime\Part\Multipart\RelatedPart;
use Symfony\Component\Mime\Part\TextPart;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @internal
 *
 * @coversNothing
 */
final class DashaMailApiTransportTest extends TestCase
{
    /**
     * @dataProvider provideToStringCases
     */
    public function testToString(DashaMailApiTransport $transport, string $expected): void
    {
        self::assertSame($expected, (string) $transport);
    }

    /**
     * @return iterable<array<mixed>>
     */
    public static function provideToStringCases(): iterable
    {
        yield [
            new DashaMailApiTransport('ACCESS_KEY'),
            'dashamail+api://api.dashamail.com',
        ];

        yield [
            (new DashaMailApiTransport('ACCESS_KEY'))->setHost('example.com'),
            'dashamail+api://example.com',
        ];

        yield [
            (new DashaMailApiTransport('ACCESS_KEY'))->setHost('example.com')->setPort(99),
            'dashamail+api://example.com:99',
        ];
    }

    public function testCustomHeader(): void
    {
        $json           = json_encode(['foo' => 'bar']);
        $dateTimeHeader = new DateTimeImmutable();

        $email    = new Email();
        $envelope = new Envelope(new Address('from@example.com'), [new Address('to@example.com')]);
        $email->replyTo(new Address('reply@example.com'));
        $email->getHeaders()->addTextHeader('X-Dashamail-Unsub-Ignore', '1');
        $email->getHeaders()->addTextHeader('X-Dashamail-Variables', $json);
        $email->getHeaders()->addTextHeader('text-header', 'text-value');
        $email->getHeaders()->addDateHeader('date-header', $dateTimeHeader);
        $email->getHeaders()->addIdHeader('id-string-header', 'id@value');
        $email->getHeaders()->addIdHeader('id-array-header', ['id1@value', 'id2@value']);

        $transport = new DashaMailApiTransport('ACCESS_KEY');
        $method    = new ReflectionMethod(DashaMailApiTransport::class, 'getPayload');
        $payload   = $method->invoke($transport, $email, $envelope);
        $headers   = json_decode($payload['headers'], true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('X-Dashamail-Unsub-Ignore', $headers);
        self::assertSame('1', $headers['X-Dashamail-Unsub-Ignore']);

        self::assertArrayHasKey('X-Dashamail-Variables', $headers);
        self::assertSame($json, $headers['X-Dashamail-Variables']);

        self::assertArrayHasKey('Reply-To', $headers);
        self::assertSame('reply@example.com', $headers['Reply-To']);

        self::assertArrayHasKey('text-header', $headers);
        self::assertSame('text-value', $headers['text-header']);

        self::assertArrayHasKey('date-header', $headers);
        self::assertSame($dateTimeHeader->format(DateTimeInterface::RFC2822), $headers['date-header']);

        self::assertArrayHasKey('id-string-header', $headers);
        self::assertSame('<id@value>', $headers['id-string-header']);

        self::assertArrayHasKey('id-array-header', $headers);
        self::assertSame('<id1@value> <id2@value>', $headers['id-array-header']);
    }

    public function testDashaMailHeadersBecomeApiOptions(): void
    {
        $email = new Email();
        $email->getHeaders()->addTextHeader('DM-Message-ID', 'order-123');
        $email->getHeaders()->addTextHeader('DM-Campaign-ID', 'order-paid');
        $email->getHeaders()->addTextHeader('DM-Delivery-Timestamp', '1790000000');
        $email->getHeaders()->addTextHeader('DM-Domain', 'example.com');
        $email->getHeaders()->addTextHeader('DM-Ignore-Delivery-Policy', '1');
        $email->getHeaders()->addTextHeader('DM-No-Track-Opens', '1');
        $email->getHeaders()->addTextHeader('DM-No-Track-Clicks', '1');
        $envelope = new Envelope(new Address('from@example.com'), [new Address('to@example.com')]);

        $transport = new DashaMailApiTransport('ACCESS_KEY');
        $method    = new ReflectionMethod(DashaMailApiTransport::class, 'getPayload');
        $payload   = $method->invoke($transport, $email, $envelope);

        self::assertSame('order-123', $payload['message_id']);
        self::assertSame('order-paid', $payload['campaign_id']);
        self::assertSame('1790000000', $payload['delivery_time']);
        self::assertSame('example.com', $payload['domain']);
        self::assertTrue($payload['ignore_delivery_policy']);
        self::assertTrue($payload['no_track_opens']);
        self::assertTrue($payload['no_track_clicks']);
        self::assertArrayNotHasKey('headers', $payload);
    }

    public function testNoTrackOpensTrue(): void
    {
        $email    = new Email();
        $envelope = new Envelope(new Address('from@example.com'), [new Address('to@example.com')]);

        $transport = new DashaMailApiTransport('ACCESS_KEY', true);
        $method    = new ReflectionMethod(DashaMailApiTransport::class, 'getPayload');
        $payload   = $method->invoke($transport, $email, $envelope);

        self::assertArrayHasKey('no_track_opens', $payload);
        self::assertTrue($payload['no_track_opens']);
    }

    public function testNoTrackClicksTrue(): void
    {
        $email    = new Email();
        $envelope = new Envelope(new Address('from@example.com'), [new Address('to@example.com')]);

        $transport = new DashaMailApiTransport('ACCESS_KEY', false, true);
        $method    = new ReflectionMethod(DashaMailApiTransport::class, 'getPayload');
        $payload   = $method->invoke($transport, $email, $envelope);

        self::assertArrayHasKey('no_track_clicks', $payload);
        self::assertTrue($payload['no_track_clicks']);
    }

    public function testSend(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            self::assertSame('POST', $method);
            self::assertSame('https://api.dashamail.com:7831/v2/transactional/messages', $url);
            self::assertContains('Authorization: Bearer ACCESS_KEY', $options['headers']);

            $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('Test Email', $payload['subject']);
            self::assertSame('"To Name" <to@example.com>', $payload['to']);
            self::assertSame('From Name', $payload['from_name']);
            self::assertSame('from@example.com', $payload['from_email']);
            self::assertSame('Test email text', $payload['plain_text']);

            self::assertSame(
                [
                    [
                        'name'     => 'report.txt',
                        'filebody' => base64_encode('attachment contents'),
                    ],
                ],
                json_decode($payload['attachments'], true, 512, JSON_THROW_ON_ERROR)
            );

            $inlines = json_decode($payload['inline'], true, 512, JSON_THROW_ON_ERROR);
            self::assertCount(1, $inlines);
            self::assertSame('image/png', $inlines[0]['mime_type']);
            self::assertSame('logo.png', $inlines[0]['filename']);
            self::assertSame(base64_encode('inline contents'), $inlines[0]['body']);
            self::assertSame('<img src="cid:' . $inlines[0]['cid'] . '">', $payload['message']);

            return new MockResponse(
                json_encode(['response' => ['data' => ['transaction_id' => 'foobar']]]),
                [
                    'http_code' => 201,
                ]
            );
        });
        $transport = new DashaMailApiTransport('ACCESS_KEY', false, false, $client);
        $transport->setPort(7831);

        $mail = new Email();
        $mail->subject('Test Email')
            ->to(new Address('to@example.com', 'To Name'))
            ->from(new Address('from@example.com', 'From Name'))
            ->text('Test email text')
            ->html('<img src="cid:logo.png">')
            ->attach('attachment contents', 'report.txt', 'text/plain')
            ->embed('inline contents', 'logo.png', 'image/png')
        ;

        $message = $transport->send($mail);

        self::assertSame('foobar', $message->getMessageId());
    }

    public function testSendRedirectsAllEnvelopeRecipients(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new EnvelopeListener(null, ['sandbox@example.com']));
        $mail = (new Email())
            ->from('from@example.com')
            ->to('to@example.com')
            ->cc('cc@example.com')
            ->bcc('hidden@example.com')
            ->text('Test email text')
        ;

        $payload = $this->sendPayload($mail, null, $dispatcher);

        self::assertSame('sandbox@example.com', $payload['to']);
        self::assertSame('', $payload['cc'] ?? '');
        self::assertSame('', $payload['bcc'] ?? '');
    }

    public function testSendMatchesExplicitEnvelopeRecipientsByAddress(): void
    {
        $mail = (new Email())
            ->from('from@example.com')
            ->to('to@example.com')
            ->cc('cc@example.com')
            ->bcc('hidden@example.com')
            ->text('Test email text')
        ;
        $envelope = new Envelope(new Address('from@example.com'), [
            new Address('to@example.com'),
            new Address('cc@example.com'),
            new Address('hidden@example.com'),
        ]);

        $payload = $this->sendPayload($mail, $envelope);

        self::assertSame('to@example.com', $payload['to']);
        self::assertSame('cc@example.com', $payload['cc']);
        self::assertSame('hidden@example.com', $payload['bcc']);
    }

    /**
     * @dataProvider provideVisibleSenders
     */
    public function testSendPreservesVisibleSender(bool $hasFrom, string $address, string $name): void
    {
        $mail = (new Email())
            ->sender(new Address('sender@example.com', 'Message Sender'))
            ->to('to@example.com')
            ->text('Test email text')
        ;
        if ($hasFrom) {
            $mail->from(new Address('author@example.com', 'Original Author'));
        }
        $envelope = new Envelope(new Address('bounce@example.com', 'Envelope Sender'), [new Address('to@example.com')]);

        $payload = $this->sendPayload($mail, $envelope);

        self::assertSame($address, $payload['from_email']);
        self::assertSame($name, $payload['from_name']);
    }

    /**
     * @return iterable<string, array{bool, string, string}>
     */
    public static function provideVisibleSenders(): iterable
    {
        yield 'From takes precedence over Sender and envelope' => [true, 'author@example.com', 'Original Author'];
        yield 'Sender supplies missing From' => [false, 'sender@example.com', 'Message Sender'];
    }

    public function testSendPreservesCustomCidAndDistinctPrefixedInlineNames(): void
    {
        $custom = (new DataPart('custom image', 'custom.png', 'image/png'))
            ->asInline()
            ->setContentId('custom@example.com')
        ;
        $mail = (new Email())
            ->from('from@example.com')
            ->to('to@example.com')
            ->html('<img src="cid:logo.png"><img src="cid:logo.png.large"><img src="cid:custom@example.com">')
            ->embed('small image', 'logo.png', 'image/png')
            ->embed('large image', 'logo.png.large', 'image/png')
            ->addPart($custom)
        ;

        $payload = $this->sendPayload($mail);
        $inlines = json_decode($payload['inline'], true, 512, JSON_THROW_ON_ERROR);
        $cids = array_column($inlines, 'cid', 'filename');

        self::assertCount(3, $inlines);
        self::assertSame('custom@example.com', $cids['custom.png']);
        self::assertSame(
            '<img src="cid:' . $cids['logo.png'] . '"><img src="cid:' . $cids['logo.png.large'] . '"><img src="cid:custom@example.com">',
            $payload['message']
        );
    }

    public function testSendPreservesCompleteCidReferencesAcrossHtmlAndCss(): void
    {
        $mail = (new Email())
            ->from('from@example.com')
            ->to('to@example.com')
            ->html('<img src="cid:logo.png"><table background=cid:logo.png.large></table>'
                . '<style>.hero{background:url(cid:logo.png@example.com)}'
                . '.other{background:url("cid:logo.png.large")}</style>'
                . '<img src="cid:logo.png.missing">')
            ->embed('logo', 'logo.png', 'image/png')
            ->embed('large logo', 'logo.png.large', 'image/png')
            ->addPart((new DataPart('background', 'background.png', 'image/png'))
                ->asInline()->setContentId('logo.png@example.com'))
        ;

        $payload = $this->sendPayload($mail);
        $inlines = json_decode($payload['inline'], true, 512, JSON_THROW_ON_ERROR);
        $cids = array_column($inlines, 'cid', 'filename');

        self::assertCount(3, $inlines);
        self::assertSame('logo.png@example.com', $cids['background.png']);
        self::assertSame(
            '<img src="cid:' . $cids['logo.png'] . '"><table background=cid:' . $cids['logo.png.large'] . '></table>'
                . '<style>.hero{background:url(cid:logo.png@example.com)}'
                . '.other{background:url("cid:' . $cids['logo.png.large'] . '")}</style>'
                . '<img src="cid:logo.png.missing">',
            $payload['message']
        );
    }

    public function testSendPreservesLiteralCidTextAndRegularAttachments(): void
    {
        $html = '<p data-code="cid:receipt.pdf" title="src=\'cid:receipt.pdf\'">'
            . 'Use cid:receipt.pdf as an identifier.</p>'
            . '<img title="src=\'cid:receipt.pdf\'" src="https://example.com/logo.png">'
            . '<!-- <img src="cid:receipt.pdf"> url(cid:receipt.pdf) -->'
            . '<style>p::after{content:"url(cid:receipt.pdf)"}/* url(cid:receipt.pdf) */</style>'
            . '<div style="--label: \'cid:receipt.pdf\'"></div>';
        $mail = (new Email())
            ->from('from@example.com')
            ->to('to@example.com')
            ->html($html)
            ->attach('receipt', 'receipt.pdf', 'application/pdf')
        ;

        $payload = $this->sendPayload($mail);

        self::assertSame($html, $payload['message']);
        self::assertArrayNotHasKey('inline', $payload);
        self::assertSame(
            [['name' => 'receipt.pdf', 'filebody' => base64_encode('receipt')]],
            json_decode($payload['attachments'], true, 512, JSON_THROW_ON_ERROR)
        );
    }

    public function testSendResolvesCidUrlsWithoutChangingLiteralReferencesToTheSameImage(): void
    {
        $mail = (new Email())
            ->from('from@example.com')
            ->to('to@example.com')
            ->html('<img title="cid:logo.png" src="cid:logo.png"><p>cid:logo.png</p>'
                . '<div style="background: url(\'cid:logo.png\'); --label: \'cid:logo.png\'"></div>'
                . '<style>p{background:url(cid:logo.png)}p::after{content:"url(cid:logo.png)"}</style>')
            ->addPart((new DataPart('logo', 'logo.png', 'image/png'))->setContentId('logo@example.com'))
        ;

        $payload = $this->sendPayload($mail);

        self::assertSame(
            '<img title="cid:logo.png" src="cid:logo@example.com"><p>cid:logo.png</p>'
                . '<div style="background: url(\'cid:logo@example.com\'); --label: \'cid:logo.png\'"></div>'
                . '<style>p{background:url(cid:logo@example.com)}p::after{content:"url(cid:logo.png)"}</style>',
            $payload['message']
        );
        self::assertArrayNotHasKey('attachments', $payload);
        self::assertSame(
            [['mime_type' => 'image/png', 'filename' => 'logo.png', 'body' => base64_encode('logo'), 'cid' => 'logo@example.com']],
            json_decode($payload['inline'], true, 512, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @param 'plain'|'html'|'alternative' $type
     *
     * @dataProvider provideExplicitMimeBodies
     */
    public function testSendUsesExplicitMimeBody(string $type, bool $staleBuilder): void
    {
        $mail = (new Email())->from('author@example.com')->to('to@example.com')->subject('Explicit body');
        $mail->getHeaders()->addTextHeader('DM-Campaign-ID', 'explicit-body');
        $mail->getHeaders()->addTextHeader('X-Body-Test', 'preserved');
        if ($staleBuilder) {
            $mail->text('stale text')->html('<p>stale HTML</p>')->attach('stale attachment', 'stale.txt');
        }
        $text = new TextPart('explicit MIME body');
        $html = new TextPart('<p>explicit HTML</p>', 'utf-8', 'html');
        $mail->setBody(match ($type) {
            'plain' => $text,
            'html' => $html,
            'alternative' => new AlternativePart($text, $html),
        });
        $envelope = new Envelope(new Address('bounce@example.com'), [new Address('sandbox@example.com')]);

        $payload = $this->sendPayload($mail, $envelope);

        if ('html' === $type) {
            self::assertArrayNotHasKey('plain_text', $payload);
        } else {
            self::assertSame('explicit MIME body', $payload['plain_text']);
        }
        if ('plain' === $type) {
            self::assertArrayNotHasKey('message', $payload);
        } else {
            self::assertSame('<p>explicit HTML</p>', $payload['message']);
        }
        self::assertArrayNotHasKey('attachments', $payload);
        self::assertSame('author@example.com', $payload['from_email']);
        self::assertSame('sandbox@example.com', $payload['to']);
        self::assertSame('Explicit body', $payload['subject']);
        self::assertSame('explicit-body', $payload['campaign_id']);
        self::assertSame('preserved', json_decode($payload['headers'], true, 512, JSON_THROW_ON_ERROR)['X-Body-Test']);
    }

    /**
     * @return iterable<string, array{'plain'|'html'|'alternative', bool}>
     */
    public static function provideExplicitMimeBodies(): iterable
    {
        foreach (['plain', 'html', 'alternative'] as $type) {
            yield $type => [$type, false];
            yield $type . ' overrides builder' => [$type, true];
        }
    }

    public function testSendUsesExplicitRelatedAndMixedMimeParts(): void
    {
        $mail = (new Email())
            ->from('from@example.com')
            ->to('to@example.com')
            ->text('stale text')
            ->html('<p>stale HTML</p>')
            ->attach('stale attachment', 'stale.txt')
            ->setBody(new MixedPart(
                new RelatedPart(
                    new AlternativePart(
                        new TextPart('explicit text'),
                        new TextPart('<img src="cid:logo.png"><style>p{background:url(cid:logo.png@example.com)}</style>', 'utf-8', 'html')
                    ),
                    (new DataPart('logo', 'logo.png', 'image/png'))->asInline(),
                    (new DataPart('background', 'background.png', 'image/png'))->asInline()->setContentId('logo.png@example.com')
                ),
                new DataPart('explicit attachment', 'report.txt', 'text/plain')
            ))
        ;

        $payload = $this->sendPayload($mail);
        $inlines = json_decode($payload['inline'], true, 512, JSON_THROW_ON_ERROR);
        $cids = array_column($inlines, 'cid', 'filename');

        self::assertSame('explicit text', $payload['plain_text']);
        self::assertCount(2, $inlines);
        self::assertSame('logo.png@example.com', $cids['background.png']);
        self::assertSame(
            '<img src="cid:' . $cids['logo.png'] . '"><style>p{background:url(cid:logo.png@example.com)}</style>',
            $payload['message']
        );
        self::assertSame(
            [['name' => 'report.txt', 'filebody' => base64_encode('explicit attachment')]],
            json_decode($payload['attachments'], true, 512, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @dataProvider provideBodies
     */
    public function testSendPreservesExplicitMimeBodyContent(string $text, string $html, string $charset, string $expectedText, string $expectedHtml, bool $stream): void
    {
        $textStream = null;
        $htmlStream = null;
        try {
            if ($stream) {
                $textStream = fopen('php://temp', 'w+');
                $htmlStream = fopen('php://temp', 'w+');
                fwrite($textStream, $text);
                fwrite($htmlStream, $html);
            }
            $mail = (new Email())
                ->from('from@example.com')
                ->to('to@example.com')
                ->setBody(new AlternativePart(
                    new TextPart($stream ? $textStream : $text, $charset),
                    new TextPart($stream ? $htmlStream : $html, $charset, 'html')
                ))
            ;

            $payload = $this->sendPayload($mail);
            self::assertSame($expectedText, $payload['plain_text']);
            self::assertSame($expectedHtml, $payload['message']);
            if ($stream) {
                $payload = $this->sendPayload($mail);
                self::assertSame($expectedText, $payload['plain_text']);
                self::assertSame($expectedHtml, $payload['message']);
            }
        } finally {
            if (is_resource($textStream)) {
                fclose($textStream);
            }
            if (is_resource($htmlStream)) {
                fclose($htmlStream);
            }
        }
    }

    public function testSendPreservesAttachmentOnlyMimeBodies(): void
    {
        foreach ([false, true] as $explicit) {
            $attachment = new DataPart('attachment only', 'report.txt', 'text/plain');
            $mail = (new Email())->from('from@example.com')->to('to@example.com');
            if ($explicit) {
                $mail->text('stale text')->setBody(new MixedPart($attachment));
            } else {
                $mail->addPart($attachment);
            }

            $payload = $this->sendPayload($mail);

            self::assertArrayNotHasKey('plain_text', $payload);
            self::assertArrayNotHasKey('message', $payload);
            self::assertSame(
                [['name' => 'report.txt', 'filebody' => base64_encode('attachment only')]],
                json_decode($payload['attachments'], true, 512, JSON_THROW_ON_ERROR)
            );
        }
    }

    #[DataProvider('provideAttachmentBytes')]
    public function testSendPreservesAttachmentBytes(string $content, string $source, bool $inline, ?string $encoding): void
    {
        $stream = null;
        try {
            $body = $content;
            if ('seekable' === $source || 'file' === $source) {
                $stream = tmpfile();
                fwrite($stream, $content);
                $body = 'file' === $source ? new File(stream_get_meta_data($stream)['uri']) : $stream;
            } elseif ('nonseekable' === $source) {
                [$stream, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                try {
                    fwrite($writer, $content);
                } finally {
                    fclose($writer);
                }
                self::assertFalse(stream_get_meta_data($stream)['seekable']);
                $body = $stream;
            }

            $attachment = (new DataPart($body, 'bytes.bin', 'application/octet-stream', $encoding))
                ->setContentId('bytes@example.com')
            ;
            if ($inline) {
                $attachment->asInline();
            }
            $attachment->getHeaders()->addTextHeader('X-Attachment', 'unchanged');
            $headers = $attachment->getPreparedHeaders()->toString();
            $mail = (new Email())
                ->from('from@example.com')
                ->to('to@example.com')
                ->text('Attachment bytes')
                ->addPart($attachment)
            ;

            $payload = $this->sendPayload($mail);

            if ($inline) {
                self::assertSame(
                    [['mime_type' => 'application/octet-stream', 'filename' => 'bytes.bin', 'body' => base64_encode($content), 'cid' => 'bytes@example.com']],
                    json_decode($payload['inline'], true, 512, JSON_THROW_ON_ERROR)
                );
                self::assertArrayNotHasKey('attachments', $payload);
            } else {
                self::assertSame(
                    [['name' => 'bytes.bin', 'filebody' => base64_encode($content)]],
                    json_decode($payload['attachments'], true, 512, JSON_THROW_ON_ERROR)
                );
                self::assertArrayNotHasKey('inline', $payload);
            }
            self::assertSame($attachment, $mail->getAttachments()[0]);
            self::assertSame($headers, $attachment->getPreparedHeaders()->toString());
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function testSendPreservesStreamAttachmentBodyOverride(): void
    {
        $stream = tmpfile();
        try {
            fwrite($stream, 'consumer attachment');
            $attachment = new class ($stream, 'custom.txt', 'text/plain') extends DataPart {
                public function getBody(): string
                {
                    return strtoupper(parent::getBody());
                }
            };
            $mail = (new Email())
                ->from('from@example.com')
                ->to('to@example.com')
                ->text('Custom attachment')
                ->addPart($attachment)
            ;

            $payload = $this->sendPayload($mail);

            self::assertSame(
                [['name' => 'custom.txt', 'filebody' => base64_encode('CONSUMER ATTACHMENT')]],
                json_decode($payload['attachments'], true, 512, JSON_THROW_ON_ERROR)
            );
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return iterable<string, array{string, string, bool, ?string}>
     */
    public static function provideAttachmentBytes(): iterable
    {
        foreach (['string', 'seekable', 'nonseekable'] as $source) {
            foreach ([false, true] as $inline) {
                yield $source . ($inline ? ' inline' : ' regular') . ' zero' => ['0', $source, $inline, null];
            }
        }

        yield 'quoted-printable nonseekable regular zero' => ['0', 'nonseekable', false, 'quoted-printable'];
        yield 'quoted-printable nonseekable inline zero' => ['0', 'nonseekable', true, 'quoted-printable'];
        yield 'quoted-printable seekable zero' => ['0', 'seekable', false, 'quoted-printable'];
        yield '8bit seekable zero' => ['0', 'seekable', true, '8bit'];
        yield 'empty string' => ['', 'string', false, null];
        yield 'empty nonseekable inline' => ['', 'nonseekable', true, null];
        yield 'file zero' => ['0', 'file', false, null];

        $binary = implode('', array_map(chr(...), range(0, 255))) . str_repeat('long line ', 40);
        yield 'binary string' => [$binary, 'string', false, null];
        yield 'binary seekable inline' => [$binary, 'seekable', true, '8bit'];
        yield 'binary nonseekable' => [$binary, 'nonseekable', false, null];
        yield 'binary file inline' => [$binary, 'file', true, null];

        $lineEndings = "LF\nCR\rCRLF\r\ntrailing whitespace\t \nunterminated";
        yield 'quoted-printable string line endings' => [$lineEndings, 'string', false, 'quoted-printable'];
        yield 'quoted-printable nonseekable inline line endings' => [$lineEndings, 'nonseekable', true, 'quoted-printable'];
    }

    /**
     * @dataProvider provideUnsupportedMimeBodies
     */
    public function testSendRejectsMimeBodiesThatCannotBeRepresented(AbstractPart $body): void
    {
        $mail = (new Email())->from('from@example.com')->to('to@example.com')->setBody($body);

        $this->expectException(TransportException::class);
        $this->sendPayload($mail);
    }

    /**
     * @return iterable<string, array{AbstractPart}>
     */
    public static function provideUnsupportedMimeBodies(): iterable
    {
        yield 'unsupported text subtype' => [new TextPart('calendar body', 'utf-8', 'calendar')];
        yield 'multiple plain bodies' => [new MixedPart(new TextPart('first body'), new TextPart('second body'))];
    }

    /**
     * @dataProvider provideBodies
     */
    public function testSendPreservesBodyContent(string $text, string $html, string $charset, string $expectedText, string $expectedHtml, bool $stream): void
    {
        $textStream = null;
        $htmlStream = null;
        try {
            if ($stream) {
                $textStream = fopen('php://temp', 'w+');
                $htmlStream = fopen('php://temp', 'w+');
                fwrite($textStream, $text);
                fwrite($htmlStream, $html);
                rewind($textStream);
                rewind($htmlStream);
            }
            $mail = (new Email())
                ->from('from@example.com')
                ->to('to@example.com')
                ->text($stream ? $textStream : $text, $charset)
                ->html($stream ? $htmlStream : $html, $charset)
            ;

            $payload = $this->sendPayload($mail);
            self::assertSame($expectedText, $payload['plain_text']);
            self::assertSame($expectedHtml, $payload['message']);

            if ($stream) {
                $payload = $this->sendPayload($mail);
                self::assertSame($expectedText, $payload['plain_text']);
                self::assertSame($expectedHtml, $payload['message']);
            }
        } finally {
            if (is_resource($textStream)) {
                fclose($textStream);
            }
            if (is_resource($htmlStream)) {
                fclose($htmlStream);
            }
        }
    }

    /**
     * @return iterable<string, array{string, string, string, string, string, bool}>
     */
    public static function provideBodies(): iterable
    {
        yield 'UTF-8 streams' => ['Plain café', '<p>HTML café</p>', 'utf-8', 'Plain café', '<p>HTML café</p>', true];
        yield 'ISO-8859-1 strings' => ["Plain caf\xe9", "<p>HTML caf\xe9</p>", 'iso-8859-1', 'Plain café', '<p>HTML café</p>', false];
        yield 'ISO-8859-1 streams' => ["Plain caf\xe9", "<p>HTML caf\xe9</p>", 'iso-8859-1', 'Plain café', '<p>HTML café</p>', true];
        yield 'literal zero strings' => ['0', '0', 'utf-8', '0', '0', false];
        yield 'literal zero streams' => ['0', '0', 'utf-8', '0', '0', true];
        yield 'empty strings' => ['', '', 'utf-8', '', '', false];
        yield 'empty streams' => ['', '', 'utf-8', '', '', true];
        yield 'line endings' => ["line one\nline two\r\n", "<p>one</p>\n<p>two</p>\r\n", 'utf-8', "line one\nline two\r\n", "<p>one</p>\n<p>two</p>\r\n", true];
    }

    public function testSynchronousHttpClientFailureTriggersFailover(): void
    {
        $primaryRequests = 0;
        $fallbackRequests = 0;
        $primaryClient = new MockHttpClient(function () use (&$primaryRequests): ResponseInterface {
            ++$primaryRequests;
            throw new HttpClientTransportException('Connection refused');
        });
        $fallbackClient = new MockHttpClient(function () use (&$fallbackRequests): ResponseInterface {
            ++$fallbackRequests;

            return new MockResponse('{"response":{"data":{"transaction_id":"delivered-by-fallback"}}}', ['http_code' => 201]);
        });
        $transport = new FailoverTransport([
            new DashaMailApiTransport('PRIMARY_KEY', false, false, $primaryClient),
            new DashaMailApiTransport('FALLBACK_KEY', false, false, $fallbackClient),
        ]);
        $mail = (new Email())->from('from@example.com')->to('to@example.com')->text('Test email text');

        self::assertSame('delivered-by-fallback', $transport->send($mail)->getMessageId());
        self::assertSame(1, $primaryRequests);
        self::assertSame(1, $fallbackRequests);
    }

    /**
     * @dataProvider provideUnbufferedErrorBodies
     */
    public function testUnbufferedHttpErrorTriggersFailover(string $body): void
    {
        $primaryRequests = 0;
        $fallbackRequests = 0;
        $primaryClient = new MockHttpClient(function () use ($body, &$primaryRequests): ResponseInterface {
            ++$primaryRequests;

            return new MockResponse($body, ['http_code' => 503]);
        });
        $primaryClient = $primaryClient->withOptions(['buffer' => false]);
        $fallbackClient = new MockHttpClient(function () use (&$fallbackRequests): ResponseInterface {
            ++$fallbackRequests;

            return new MockResponse('{"response":{"data":{"transaction_id":"delivered-by-fallback"}}}', ['http_code' => 201]);
        });
        $transport = new FailoverTransport([
            new DashaMailApiTransport('PRIMARY_KEY', false, false, $primaryClient),
            new DashaMailApiTransport('FALLBACK_KEY', false, false, $fallbackClient),
        ]);
        $mail = (new Email())->from('from@example.com')->to('to@example.com')->text('Test email text');

        self::assertSame('delivered-by-fallback', $transport->send($mail)->getMessageId());
        self::assertSame(1, $primaryRequests);
        self::assertSame(1, $fallbackRequests);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnbufferedErrorBodies(): iterable
    {
        yield 'invalid JSON' => ['Forbidden'];
        yield 'JSON without an error message' => ['{}'];
    }

    public function testSendWithMultipleTagHeaders(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
            $headers = json_decode($payload['headers'], true, 512, JSON_THROW_ON_ERROR);

            self::assertSame(['test-tag-foo', 'test-tag-bar'], $headers['X-Tag']);

            return new MockResponse(
                json_encode(['response' => ['data' => ['transaction_id' => 'test-message-id']]]),
                [
                    'http_code' => 201,
                ]
            );
        });
        $transport = new DashaMailApiTransport('ACCESS_KEY', false, false, $client);

        $mail = new Email();
        $mail->subject('Test Email')
            ->to(new Address('to@example.com', 'To Name'))
            ->from(new Address('from@example.com', 'From Name'))
            ->text('Test email text')
        ;

        $mail->getHeaders()
            ->add(new TagHeader('test-tag-foo'))
            ->add(new TagHeader('test-tag-bar'))
        ;

        $message = $transport->send($mail);

        self::assertSame('test-message-id', $message->getMessageId());
    }

    public function testSendThrowsForErrorResponse(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): ResponseInterface {
            $this->assertSame('POST', $method);
            self::assertSame('https://api.dashamail.com:7831/v2/transactional/messages', $url);

            return new MockResponse(json_encode(['error' => ['code' => 1, 'message' => 'i\'m a teapot']]), [
                'http_code'        => 418,
                'response_headers' => [
                    'content-type' => 'application/json',
                ],
            ]);
        });
        $transport = new DashaMailApiTransport('ACCESS_KEY', false, false, $client);
        $transport->setPort(7831);

        $mail = new Email();
        $mail->subject('Test Email')
            ->to(new Address('to@example.com', 'To Name'))
            ->from(new Address('from@example.com', 'From Name'))
            ->text('Test email text')
        ;

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('Unable to send an email: i\'m a teapot (code 418).');
        $transport->send($mail);
    }

    public function testSendUsesApiErrorMessage(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['error' => ['code' => 1, 'message' => 'denied']]), [
            'http_code'        => 500,
            'response_headers' => [
                'content-type' => 'application/json',
            ],
        ]));
        $transport = new DashaMailApiTransport('ACCESS_KEY', false, false, $client);

        $mail = new Email();
        $mail->subject('Test Email')
            ->to(new Address('to@example.com'))
            ->from(new Address('from@example.com'))
            ->text('Test email text')
        ;

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('Unable to send an email: denied (code 500).');
        $transport->send($mail);
    }

    public function testSendThrowsForErrorResponseWithContentTypeTextHtml(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $this->assertSame('POST', $method);
            self::assertSame('https://api.dashamail.com:7831/v2/transactional/messages', $url);
            return new MockResponse('Forbidden', [
                'http_code'        => 401,
                'response_headers' => [
                    'content-type' => 'text/html',
                ],
            ]);
        });
        $transport = new DashaMailApiTransport('ACCESS_KEY', false, false, $client);
        $transport->setPort(7831);

        $mail = new Email();
        $mail->subject('Test Email')
            ->to(new Address('to@example.com', 'To Name'))
            ->from(new Address('from@example.com', 'From Name'))
            ->text('Test email text')
        ;

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('Unable to send an email: Forbidden (code 401).');
        $transport->send($mail);
    }

    public function testTagAndMetadataHeaders(): void
    {
        $json  = json_encode(['foo' => 'bar']);
        $email = new Email();
        $email->getHeaders()->addTextHeader('X-Dashamail-Unsub-Ignore', '1');
        $email->getHeaders()->addTextHeader('X-Dashamail-Variables', $json);
        $email->getHeaders()->add(new TagHeader('test-tag-foo'));
        $email->getHeaders()->add(new TagHeader('test-tag-bar'));
        $email->getHeaders()->add(new MetadataHeader('Color', 'blue'));
        $email->getHeaders()->add(new MetadataHeader('Client-ID', '12345'));
        $envelope = new Envelope(new Address('from@example.com'), [new Address('to@example.com')]);

        $transport = new DashaMailApiTransport('ACCESS_KEY');
        $method    = new ReflectionMethod(DashaMailApiTransport::class, 'getPayload');
        $payload   = $method->invoke($transport, $email, $envelope);
        $headers   = json_decode($payload['headers'], true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('X-Dashamail-Unsub-Ignore', $headers);
        self::assertSame('1', $headers['X-Dashamail-Unsub-Ignore']);

        self::assertArrayHasKey('X-Dashamail-Variables', $headers);
        self::assertSame($json, $headers['X-Dashamail-Variables']);

        self::assertArrayHasKey('X-Tag', $headers);
        self::assertSame(['test-tag-foo', 'test-tag-bar'], $headers['X-Tag']);

        self::assertArrayHasKey('X-Metadata-Color', $headers);
        self::assertSame('blue', $headers['X-Metadata-Color']);

        self::assertArrayHasKey('X-Metadata-Client-ID', $headers);
        self::assertSame('12345', $headers['X-Metadata-Client-ID']);
    }

    /**
     * @return array<string, mixed>
     */
    private function sendPayload(Email $mail, ?Envelope $envelope = null, ?EventDispatcher $dispatcher = null): array
    {
        $payload = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$payload): ResponseInterface {
            $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);

            return new MockResponse('{"response":{"data":{"transaction_id":"message-id"}}}', ['http_code' => 201]);
        });
        $transport = new DashaMailApiTransport('ACCESS_KEY', false, false, $client, $dispatcher);
        $transport->send($mail, $envelope);

        return $payload;
    }
}

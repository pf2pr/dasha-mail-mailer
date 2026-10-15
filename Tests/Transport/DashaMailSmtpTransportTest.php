<?php

declare(strict_types=1);

namespace Pf2Pr\DashaMailMailer\Tests\Transport;

use DateTimeImmutable;
use Pf2Pr\DashaMailMailer\Transport\DashaMailSmtpTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * @internal
 *
 * @coversNothing
 */
final class DashaMailSmtpTransportTest extends TestCase
{
    #[DataProvider('provideToStringCases')]
    public function testToString(DashaMailSmtpTransport $transport, string $expected): void
    {
        self::assertSame($expected, (string) $transport);
    }

    /**
     * @return iterable<array<mixed>>
     */
    public static function provideToStringCases(): iterable
    {
        yield [new DashaMailSmtpTransport('user', 'password'), 'smtp://smtps.dashasender.ru:2525'];
        yield [new DashaMailSmtpTransport('user', 'password', true), 'smtps://smtps.dashasender.ru'];
    }

    #[DataProvider('provideRawMessageCases')]
    public function testRawMessageTrackingHeadersPreserveMimeAndInput(string $source, bool $opens, bool $clicks): void
    {
        $headers = "From: author@example.com\r\nTo: reader@example.com\r\n"
            ."Subject: raw message\r\nX-Unrelated: first\r\n\tfolded value\r\n"
            ."X-Unrelated: second\r\nMIME-Version: 1.0\r\n"
            ."Content-Type: multipart/mixed; boundary=original-boundary\r\n";
        $body = "--original-boundary\r\nContent-Type: text/plain\r\n\r\n"
            ."0\r\n.leading dot\r\n\r\nDM-No-Track-Opens: body text\r\n"
            ."--original-boundary\r\nContent-Type: application/octet-stream\r\n"
            ."Content-Transfer-Encoding: binary\r\n\r\n0\x00\xff\r\n"
            ."--original-boundary--\r\n";
        $original = $headers."\r\n".$body;
        $message = $this->rawMessage($original, $source);

        [$mime, $envelope] = $this->sendMessage($message, $opens, $clicks);

        $expected = $headers
            .($opens ? "DM-No-Track-Opens: true\r\n" : '')
            .($clicks ? "DM-No-Track-Clicks: true\r\n" : '')
            ."\r\n".$body;
        self::assertSame($expected, $mime);
        self::assertSame($original, $message->toString());
        self::assertSame('bounce@example.com', $envelope->getSender()->getAddress());
        self::assertSame('envelope-recipient@example.com', $envelope->getRecipients()[0]->getAddress());
    }

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function provideRawMessageCases(): iterable
    {
        yield 'disabled string' => ['string', false, false];
        yield 'enabled string' => ['string', true, true];
        yield 'chunked iterable opens only' => ['iterable', true, false];
        yield 'chunked generator clicks only' => ['generator', false, true];
        yield 'stream both enabled' => ['stream', true, true];
    }

    #[DataProvider('provideTrackingOptions')]
    public function testEmailTrackingHeadersPreserveInput(bool $opens, bool $clicks): void
    {
        $email = $this->email();
        $original = $email->toString();

        [$mime] = $this->sendMessage($email, $opens, $clicks);

        [$headers, $body] = explode("\r\n\r\n", $mime, 2);
        self::assertSame($opens ? 1 : 0, substr_count($headers, 'DM-No-Track-Opens: true'));
        self::assertSame($clicks ? 1 : 0, substr_count($headers, 'DM-No-Track-Clicks: true'));
        self::assertStringContainsString('X-Custom: untouched', $headers);
        self::assertSame(explode("\r\n\r\n", $original, 2)[1], $body);
        self::assertSame($original, $email->toString());
    }

    /**
     * @return iterable<array{bool, bool}>
     */
    public static function provideTrackingOptions(): iterable
    {
        yield [false, false];
        yield [true, false];
        yield [false, true];
        yield [true, true];
    }

    #[DataProvider('provideExistingHeaderCases')]
    public function testExistingTrackingHeadersKeepTypedMessagePolicy(bool $raw, bool $enabled, string $existing): void
    {
        $email = $this->email();
        $email->getHeaders()->addTextHeader('DM-No-Track-Opens', $existing);
        $email->getHeaders()->addTextHeader('DM-No-Track-Clicks', $existing);
        $original = $email->toString();
        $message = $raw ? new RawMessage($original) : $email;

        [$mime] = $this->sendMessage($message, $enabled, $enabled);

        $headers = explode("\r\n\r\n", $mime, 2)[0];
        foreach (['Opens', 'Clicks'] as $kind) {
            preg_match_all('/^DM-No-Track-'.$kind.': ([^\r\n]+)\r?$/m', $headers, $matches);
            self::assertSame($enabled ? [$existing, 'true'] : [$existing], $matches[1]);
        }
        self::assertSame($original, $message->toString());
    }

    /**
     * @return iterable<string, array{bool, bool, string}>
     */
    public static function provideExistingHeaderCases(): iterable
    {
        yield 'raw conflicting value' => [true, true, 'false'];
        yield 'typed conflicting value' => [false, true, 'false'];
        yield 'raw existing true' => [true, true, 'true'];
        yield 'typed existing true' => [false, true, 'true'];
        yield 'raw disabled options' => [true, false, 'true'];
        yield 'typed disabled options' => [false, false, 'true'];
    }

    private function email(): Email
    {
        $email = (new Email())
            ->from('author@example.com')
            ->to('reader@example.com')
            ->date(new DateTimeImmutable('2026-01-01T00:00:00+00:00'))
            ->subject('Tracking')
            ->text('Unchanged body');
        $email->getHeaders()->addIdHeader('Message-ID', 'original@example.com');
        $email->getHeaders()->addTextHeader('X-Custom', 'untouched');

        return $email;
    }

    private function rawMessage(string $mime, string $source): RawMessage
    {
        if ('stream' === $source) {
            $stream = fopen('php://temp', 'w+');
            fwrite($stream, $mime);
            rewind($stream);

            return new RawMessage($stream);
        }

        // Single-byte chunks split the CRLFCRLF separator across four chunks.
        if ('generator' === $source) {
            return new RawMessage((static function () use ($mime): iterable {
                yield from str_split($mime);
            })());
        }

        return new RawMessage('iterable' === $source ? str_split($mime) : $mime);
    }

    /**
     * @return array{string, Envelope}
     */
    private function sendMessage(RawMessage $message, bool $opens, bool $clicks): array
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(MessageEvent::class, static function (MessageEvent $event) use (&$captured): void {
            $captured = [$event->getMessage()->toString(), $event->getEnvelope()];
            $event->reject();
        });
        $transport = new DashaMailSmtpTransport('user', 'password', false, $opens, $clicks, $dispatcher);
        $envelope = new Envelope(new Address('bounce@example.com'), [new Address('envelope-recipient@example.com')]);

        self::assertNull($transport->send($message, $envelope));
        self::assertNotNull($captured);

        return $captured;
    }
}

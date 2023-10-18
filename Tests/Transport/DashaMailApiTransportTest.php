<?php

declare(strict_types=1);

namespace Pf2Pr\DashaMailMailer\Tests\Transport;

use DateTimeImmutable;
use DateTimeInterface;
use Pf2Pr\DashaMailMailer\Transport\DashaMailApiTransport;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
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
        $email->getHeaders()->addTextHeader('X-Dashamail-Unsub-Ignore', '1');
        $email->getHeaders()->addTextHeader('X-Dashamail-Variables', $json);
        $email->getHeaders()->addTextHeader('text-header', 'text-value');
        $email->getHeaders()->addDateHeader('date-header', $dateTimeHeader);
        $email->getHeaders()->addIdHeader('id-string-header', 'id@value');
        $email->getHeaders()->addIdHeader('id-array-header', ['id1@value', 'id2@value']);

        $transport = new DashaMailApiTransport('ACCESS_KEY');
        $method    = new ReflectionMethod(DashaMailApiTransport::class, 'getPayload');
        $payload   = $method->invoke($transport, $email, $envelope);
        $headers   = $payload['headers'];

        self::assertArrayHasKey('X-Dashamail-Unsub-Ignore', $headers);
        self::assertSame('1', $headers['X-Dashamail-Unsub-Ignore']);

        self::assertArrayHasKey('X-Dashamail-Variables', $headers);
        self::assertSame($json, $headers['X-Dashamail-Variables']);

        self::assertArrayHasKey('text-header', $headers);
        self::assertSame('text-value', $headers['text-header']);

        self::assertArrayHasKey('date-header', $headers);
        self::assertSame($dateTimeHeader->format(DateTimeInterface::RFC2822), $headers['date-header']);

        self::assertArrayHasKey('id-string-header', $headers);
        self::assertSame('<id@value>', $headers['id-string-header']);

        self::assertArrayHasKey('id-array-header', $headers);
        self::assertSame('<id1@value> <id2@value>', $headers['id-array-header']);
    }

    public function testNoTrackOpensTrue(): void
    {
        $email    = new Email();
        $envelope = new Envelope(new Address('from@example.com'), [new Address('to@example.com')]);

        $transport = new DashaMailApiTransport('ACCESS_KEY', true);
        $method    = new ReflectionMethod(DashaMailApiTransport::class, 'getPayload');
        $payload   = $method->invoke($transport, $email, $envelope);

        self::assertArrayHasKey('no_track_opens', $payload);
        self::assertSame('true', $payload['no_track_opens']);
    }

    public function testNoTrackClicksTrue(): void
    {
        $email    = new Email();
        $envelope = new Envelope(new Address('from@example.com'), [new Address('to@example.com')]);

        $transport = new DashaMailApiTransport('ACCESS_KEY', false, true);
        $method    = new ReflectionMethod(DashaMailApiTransport::class, 'getPayload');
        $payload   = $method->invoke($transport, $email, $envelope);

        self::assertArrayHasKey('no_track_clicks', $payload);
        self::assertSame('true', $payload['no_track_clicks']);
    }

    public function testSend(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $this->assertSame('POST', $method);
            $this->assertSame('https://api.dashamail.com:7831/?method=transactional.send&api_key=ACCESS_KEY', $url);

            $content = '';
            while ($chunk = $options['body']()) {
                $content .= $chunk;
            }

            $this->assertStringContainsString('Test Email', $content);
            $this->assertStringContainsString('"To Name" <to@example.com>', $content);
            $this->assertStringContainsString("Content-Disposition: form-data; name=\"from_name\"\r\n\r\nFrom Name\r\n", $content);
            $this->assertStringContainsString("Content-Disposition: form-data; name=\"from_email\"\r\n\r\nfrom@example.com\r\n", $content);
            $this->assertStringContainsString('Test email text', $content);

            return new MockResponse(
                json_encode(['response' => ['data' => ['transaction_id' => 'foobar']]]),
                [
                    'http_code' => 200,
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
        ;

        $message = $transport->send($mail);

        self::assertSame('foobar', $message->getMessageId());
    }

    public function testSendWithMultipleTagHeaders(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $content = '';
            while ($chunk = $options['body']()) {
                $content .= $chunk;
            }

            $this->assertStringContainsString("Content-Disposition: form-data; name=\"headers[X-Tag][0]\"\r\n\r\ntest-tag-foo\r\n", $content);
            $this->assertStringContainsString("Content-Disposition: form-data; name=\"headers[X-Tag][1]\"\r\n\r\ntest-tag-bar\r\n", $content);

            return new MockResponse(
                json_encode(['response' => ['data' => ['transaction_id' => 'test-message-id']]]),
                [
                    'http_code' => 200,
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
            $this->assertSame('https://api.dashamail.com:7831/?method=transactional.send&api_key=ACCESS_KEY', $url);

            return new MockResponse(json_encode(['message' => 'i\'m a teapot']), [
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

    public function testSendThrowsForErrorResponseWithContentTypeTextHtml(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $this->assertSame('POST', $method);
            $this->assertSame('https://api.dashamail.com:7831/?method=transactional.send&api_key=ACCESS_KEY', $url);
            // NOTE: Mailgun API does this even if "Accept" request header value is "application/json".
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
        $headers   = $payload['headers'];

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
}

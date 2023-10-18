<?php

declare(strict_types=1);

namespace Pf2Pr\DashaMailMailer\Tests\Transport;

use Pf2Pr\DashaMailMailer\Transport\DashaMailSmtpTransport;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Email;

/**
 * @internal
 *
 * @coversNothing
 */
final class DashaMailSmtpTransportTest extends TestCase
{
    /**
     * @dataProvider provideToStringCases
     */
    public function testToString(DashaMailSmtpTransport $transport, string $expected): void
    {
        self::assertSame($expected, (string) $transport);
    }

    /**
     * @return iterable<array<mixed>>
     */
    public static function provideToStringCases(): iterable
    {
        yield [
            new DashaMailSmtpTransport('user', 'password'),
            'smtp://smtps.dashasender.ru:2525',
        ];

        yield [
            new DashaMailSmtpTransport('user', 'password', true),
            'smtps://smtps.dashasender.ru',
        ];
    }

    public function testTagAndMetadataHeaders(): void
    {
        $email = new Email();
        $email->getHeaders()->addTextHeader('foo', 'bar');
        $email->getHeaders()->add(new TagHeader('test-tag-foo'));
        $email->getHeaders()->add(new MetadataHeader('Color', 'blue'));
        $email->getHeaders()->add(new MetadataHeader('Client-ID', '12345'));

        $transport = new DashaMailSmtpTransport('user', 'password');
        $method    = new ReflectionMethod(DashaMailSmtpTransport::class, 'addDashaMailHeaders');
        $method->invoke($transport, $email);

        self::assertCount(4, $email->getHeaders()->toArray());
        self::assertSame('foo: bar', $email->getHeaders()->get('foo')->toString());
        self::assertSame('X-Tag: test-tag-foo', $email->getHeaders()->get('X-Tag')->toString());
        self::assertSame('X-Metadata-Color: blue', $email->getHeaders()->get('X-Metadata-Color')->toString());
        self::assertSame('X-Metadata-Client-ID: 12345', $email->getHeaders()->get('X-Metadata-Client-ID')->toString());
    }

    public function testNoTrackOpensHeaderTrue(): void
    {
        $email = new Email();

        $transport = new DashaMailSmtpTransport('user', 'password', false, true);
        $method    = new ReflectionMethod(DashaMailSmtpTransport::class, 'addDashaMailHeaders');
        $method->invoke($transport, $email);

        self::assertCount(1, $email->getHeaders()->toArray());
        self::assertSame('DM-No-Track-Opens: true', $email->getHeaders()->get('DM-No-Track-Opens')->toString());
    }

    public function testNoTrackOpensHeaderFalse(): void
    {
        $email = new Email();

        $transport = new DashaMailSmtpTransport('user', 'password');
        $method    = new ReflectionMethod(DashaMailSmtpTransport::class, 'addDashaMailHeaders');
        $method->invoke($transport, $email);

        self::assertCount(0, $email->getHeaders()->toArray());
    }

    public function testNoTrackClicksHeaderTrue(): void
    {
        $email = new Email();

        $transport = new DashaMailSmtpTransport('user', 'password', false, false, true);
        $method    = new ReflectionMethod(DashaMailSmtpTransport::class, 'addDashaMailHeaders');
        $method->invoke($transport, $email);

        self::assertCount(1, $email->getHeaders()->toArray());
        self::assertSame('DM-No-Track-Clicks: true', $email->getHeaders()->get('DM-No-Track-Clicks')->toString());
    }

    public function testNoTrackClicksHeaderFalse(): void
    {
        $email = new Email();

        $transport = new DashaMailSmtpTransport('user', 'password');
        $method    = new ReflectionMethod(DashaMailSmtpTransport::class, 'addDashaMailHeaders');
        $method->invoke($transport, $email);

        self::assertCount(0, $email->getHeaders()->toArray());
    }
}

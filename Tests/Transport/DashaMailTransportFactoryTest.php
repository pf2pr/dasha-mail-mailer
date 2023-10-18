<?php

declare(strict_types=1);

namespace Pf2Pr\DashaMailMailer\Tests\Transport;

use Pf2Pr\DashaMailMailer\Transport\DashaMailApiTransport;
use Pf2Pr\DashaMailMailer\Transport\DashaMailSmtpTransport;
use Pf2Pr\DashaMailMailer\Transport\DashaMailTransportFactory;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mailer\Test\TransportFactoryTestCase;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;

/**
 * @internal
 *
 * @coversNothing
 */
final class DashaMailTransportFactoryTest extends TransportFactoryTestCase
{
    public function getFactory(): TransportFactoryInterface
    {
        return new DashaMailTransportFactory(null, new MockHttpClient(), new NullLogger());
    }

    /**
     * @return iterable<array<mixed>>
     */
    public static function supportsProvider(): iterable
    {
        yield [
            new Dsn('dashamail+api', 'default'),
            true,
        ];

        yield [
            new Dsn('dashamail', 'default'),
            true,
        ];

        yield [
            new Dsn('dashamail+smtp', 'default'),
            true,
        ];

        yield [
            new Dsn('dashamail+smtps', 'default'),
            true,
        ];

        yield [
            new Dsn('dashamail+smtp', 'example.com'),
            true,
        ];
    }

    /**
     * @return iterable<array<mixed>>
     */
    public static function createProvider(): iterable
    {
        $client = new MockHttpClient();
        $logger = new NullLogger();

        yield [
            new Dsn('dashamail+api', 'default', self::USER),
            new DashaMailApiTransport(self::USER, false, false, $client, null, $logger),
        ];

        yield [
            new Dsn('dashamail+api', 'default', self::USER, null, null, ['no_track_opens' => false, 'no_track_clicks' => false]),
            new DashaMailApiTransport(self::USER, false, false, $client, null, $logger),
        ];

        yield [
            new Dsn('dashamail+api', 'default', self::USER, null, null, ['no_track_opens' => true]),
            new DashaMailApiTransport(self::USER, true, false, $client, null, $logger),
        ];

        yield [
            new Dsn('dashamail+api', 'default', self::USER, null, null, ['no_track_clicks' => true]),
            new DashaMailApiTransport(self::USER, false, true, $client, null, $logger),
        ];

        yield [
            new Dsn('dashamail+api', 'default', self::USER, null, null, ['no_track_opens' => true, 'no_track_clicks' => true]),
            new DashaMailApiTransport(self::USER, true, true, $client, null, $logger),
        ];

        yield [
            new Dsn('dashamail+api', 'example.com', self::USER, null, 8080),
            (new DashaMailApiTransport(self::USER, false, false, $client, null, $logger))->setHost('example.com')->setPort(8080),
        ];

        yield [
            new Dsn('dashamail+smtp', 'default', self::USER, self::PASSWORD),
            new DashaMailSmtpTransport(self::USER, self::PASSWORD, false, false, false, null, $logger),
        ];

        yield [
            new Dsn('dashamail+smtps', 'default', self::USER, self::PASSWORD),
            new DashaMailSmtpTransport(self::USER, self::PASSWORD, true, false, false, null, $logger),
        ];
    }

    /**
     * @return iterable<array<mixed>>
     */
    public static function unsupportedSchemeProvider(): iterable
    {
        yield [
            new Dsn('dashamail+unsupported', 'default', self::USER, self::PASSWORD),
            'The "dashamail+unsupported" scheme is not supported; supported schemes for mailer "dashamail" are: "dashamail", "dashamail+api", "dashamail+smtp", "dashamail+smtps".',
        ];
    }

    /**
     * @return iterable<array<mixed>>
     */
    public static function incompleteDsnProvider(): iterable
    {
        yield [new Dsn('dashamail+api', 'default', null)];

        yield [new Dsn('dashamail+smtp', 'default', null, self::PASSWORD)];

        yield [new Dsn('dashamail+smtps', 'default', null, self::PASSWORD)];
    }
}

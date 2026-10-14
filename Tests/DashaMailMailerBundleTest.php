<?php

declare(strict_types=1);

namespace Pf2Pr\DashaMailMailer\Tests;

use Pf2Pr\DashaMailMailer\DashaMailMailerBundle;
use Pf2Pr\DashaMailMailer\Transport\DashaMailApiTransport;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mailer\Transport;

/**
 * @internal
 *
 * @coversNothing
 */
final class DashaMailMailerBundleTest extends TestCase
{
    public function testRegisteredFactoryResolvesDashaMailDsn(): void
    {
        $container = new ContainerBuilder();
        $container->register('event_dispatcher', EventDispatcher::class);
        $container->register('http_client', MockHttpClient::class);
        $container->register('logger', NullLogger::class);

        (new DashaMailMailerBundle())->build($container);

        $container
            ->register('mailer.transport', Transport::class)
            ->setArguments([new TaggedIteratorArgument('mailer.transport_factory')])
            ->setPublic(true)
        ;
        $container->compile();

        $transport = $container->get('mailer.transport')->fromString('dashamail+api://key@default');

        self::assertInstanceOf(DashaMailApiTransport::class, $transport);
    }
}

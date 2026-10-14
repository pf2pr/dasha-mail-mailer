<?php

declare(strict_types=1);

namespace Pf2Pr\DashaMailMailer;

use Pf2Pr\DashaMailMailer\Transport\DashaMailTransportFactory;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class DashaMailMailerBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container
            ->register(DashaMailTransportFactory::class, DashaMailTransportFactory::class)
            ->setArguments(
                [
                    new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    new Reference('http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                ]
            )
            ->addTag('mailer.transport_factory')
        ;
    }
}

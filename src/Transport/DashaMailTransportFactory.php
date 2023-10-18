<?php

declare(strict_types=1);

namespace Pf2Pr\DashaMailMailer\Transport;

use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

final class DashaMailTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): TransportInterface
    {
        $noTrackOpens  = filter_var($dsn->getOption('no_track_opens', false), FILTER_VALIDATE_BOOL);
        $noTrackClicks = filter_var($dsn->getOption('no_track_clicks', false), FILTER_VALIDATE_BOOL);

        return match ($dsn->getScheme()) {
            'dashamail+api' => (new DashaMailApiTransport(
                $this->getUser($dsn),
                $noTrackOpens,
                $noTrackClicks,
                $this->client,
                $this->dispatcher,
                $this->logger
            ))
                ->setHost('default' === $dsn->getHost() ? null : $dsn->getHost())
                ->setPort($dsn->getPort()),

            'dashamail', 'dashamail+smtps' => new DashaMailSmtpTransport(
                $this->getUser($dsn),
                $this->getPassword($dsn),
                true,
                $noTrackOpens,
                $noTrackClicks,
                $this->dispatcher,
                $this->logger
            ),

            'dashamail+smtp' => new DashaMailSmtpTransport(
                $this->getUser($dsn),
                $this->getPassword($dsn),
                false,
                $noTrackOpens,
                $noTrackClicks,
                $this->dispatcher,
                $this->logger
            ),

            default => throw new UnsupportedSchemeException($dsn, 'dashamail', $this->getSupportedSchemes())
        };
    }

    /**
     * @return string[]
     */
    protected function getSupportedSchemes(): array
    {
        return ['dashamail', 'dashamail+api', 'dashamail+smtp', 'dashamail+smtps'];
    }
}

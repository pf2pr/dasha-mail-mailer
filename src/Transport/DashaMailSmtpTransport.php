<?php

declare(strict_types=1);

namespace Pf2Pr\DashaMailMailer\Transport;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

final class DashaMailSmtpTransport extends EsmtpTransport
{
    public function __construct(
        string $username,
        #[SensitiveParameter] string $password,
        bool $isTls = false,
        private readonly bool $noTrackOpens = false,
        private readonly bool $noTrackClicks = false,
        EventDispatcherInterface $dispatcher = null,
        LoggerInterface $logger = null
    ) {
        parent::__construct('smtps.dashasender.ru', $isTls ? 465 : 2525, $isTls, $dispatcher, $logger);

        $this->setUsername($username);
        $this->setPassword($password);
    }

    public function send(RawMessage $message, Envelope $envelope = null): ?SentMessage
    {
        if ($message instanceof Message) {
            $this->addDashaMailHeaders($message);
        }

        return parent::send($message, $envelope);
    }

    private function addDashaMailHeaders(Message $message): void
    {
        if (true === $this->noTrackOpens) {
            $message->getHeaders()->addTextHeader('DM-No-Track-Opens', 'true');
        }

        if (true === $this->noTrackClicks) {
            $message->getHeaders()->addTextHeader('DM-No-Track-Clicks', 'true');
        }
    }
}

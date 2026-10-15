<?php

declare(strict_types=1);

namespace Pf2Pr\DashaMailMailer\Transport;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

final class DashaMailSmtpTransport extends EsmtpTransport
{
    public function __construct(
        string $username,
        #[\SensitiveParameter] string $password,
        bool $isTls = false,
        private readonly bool $noTrackOpens = false,
        private readonly bool $noTrackClicks = false,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct('smtps.dashasender.ru', $isTls ? 465 : 2525, $isTls, $dispatcher, $logger);

        $this->setUsername($username);
        $this->setPassword($password);
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($message instanceof Message) {
            $message = clone $message;
            $this->addDashaMailHeaders($message->getHeaders());
        } elseif ($this->noTrackOpens || $this->noTrackClicks) {
            $headers = new Headers();
            $this->addDashaMailHeaders($headers);
            $message = new RawMessage($this->appendRawHeaders($message, $headers->toString()));
        }

        return parent::send($message, $envelope);
    }

    private function addDashaMailHeaders(Headers $headers): void
    {
        if (true === $this->noTrackOpens) {
            $headers->addTextHeader('DM-No-Track-Opens', 'true');
        }

        if (true === $this->noTrackClicks) {
            $headers->addTextHeader('DM-No-Track-Clicks', 'true');
        }
    }

    /**
     * @return iterable<string>
     */
    private function appendRawHeaders(RawMessage $message, string $headers): iterable
    {
        $buffer = '';
        $added = false;
        foreach ($message->toIterable() as $chunk) {
            if ($added) {
                yield $chunk;
                continue;
            }

            $buffer .= $chunk;
            if (false !== $separator = strpos($buffer, "\r\n\r\n")) {
                // Append, like the typed-message path, without parsing or rewriting existing headers.
                yield substr($buffer, 0, $separator + 2);
                yield $headers;
                yield substr($buffer, $separator + 2);
                $buffer = '';
                $added = true;
            } elseif (\strlen($buffer) > 3) {
                // Retain only a possible separator split across chunks.
                yield substr($buffer, 0, -3);
                $buffer = substr($buffer, -3);
            }
        }

        if ('' !== $buffer) {
            yield $buffer;
        }
    }
}

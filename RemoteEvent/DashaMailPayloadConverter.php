<?php

declare(strict_types=1);

namespace Pf2Pr\DashaMailMailer\RemoteEvent;

use DateTimeImmutable;
use Symfony\Component\RemoteEvent\Event\Mailer\AbstractMailerEvent;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerDeliveryEvent;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerEngagementEvent;
use Symfony\Component\RemoteEvent\Exception\ParseException;
use Symfony\Component\RemoteEvent\PayloadConverterInterface;

use function in_array;

final class DashaMailPayloadConverter implements PayloadConverterInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function convert(array $payload): AbstractMailerEvent
    {
        if (in_array($payload['event'], ['delivered', 'bounced', 'dropped'], true)) {
            $name = match ($payload['event']) {
                'delivered' => MailerDeliveryEvent::DELIVERED,
                'bounced'   => MailerDeliveryEvent::BOUNCE,
                'dropped'   => MailerDeliveryEvent::DROPPED
            };

            $event = new MailerDeliveryEvent($name, $payload['message_id'], $payload);
        } else {
            $name = match ($payload['event']) {
                'clicked'      => MailerEngagementEvent::CLICK,
                'unsubscribed' => MailerEngagementEvent::UNSUBSCRIBE,
                'opened'       => MailerEngagementEvent::OPEN,
                'complained'   => MailerEngagementEvent::SPAM,
                default        => throw new ParseException(sprintf('Unsupported event "%s".', $payload['event'])),
            };
            $event = new MailerEngagementEvent($name, $payload['message_id'], $payload);
        }

        // todo check format
        if (!$date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $payload['event_time'])) {
            throw new ParseException(sprintf('Invalid date "%s".', $payload['event_time']));
        }

        if (
            $event instanceof MailerDeliveryEvent
            && in_array($payload['event'], ['bounced', 'dropped'], true)
            && (isset($payload['reason']) || isset($payload['error']) || isset($payload['description']))
        ) {
            $event->setReason(
                implode(
                    '|',
                    [
                        $payload['description'],
                        $payload['error'],
                        $payload['reason'],
                    ]
                )
            );
        }

        $event->setDate($date);
        $event->setRecipientEmail($payload['email']);
        $event->setMetadata($payload['custom_vars']);

        return $event;
    }
}

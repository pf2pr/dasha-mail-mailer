<?php

declare(strict_types=1);

namespace Pf2Pr\DashaMailMailer\RemoteEvent;

use DateTimeImmutable;
use JsonException;
use Symfony\Component\RemoteEvent\Event\Mailer\AbstractMailerEvent;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerDeliveryEvent;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerEngagementEvent;
use Symfony\Component\RemoteEvent\Exception\ParseException;
use Symfony\Component\RemoteEvent\PayloadConverterInterface;
use ValueError;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_string;

final class DashaMailPayloadConverter implements PayloadConverterInterface
{
    /**
     * @param array<string, mixed> $payload
     *
     * @throws ParseException
     */
    public function convert(array $payload): AbstractMailerEvent
    {
        foreach (['event', 'email', 'message_id', 'event_time'] as $field) {
            if (!is_string($payload[$field] ?? null)) {
                throw new ParseException(sprintf('Invalid or missing field "%s".', $field));
            }
        }

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

        $eventTime = $payload['event_time'];
        try {
            $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $eventTime);
        } catch (ValueError $e) {
            throw new ParseException('Invalid date.', 0, $e);
        }

        if (false === $date || $date->format('Y-m-d H:i:s') !== $eventTime) {
            throw new ParseException(sprintf('Invalid date "%s".', $eventTime));
        }

        if (
            $event instanceof MailerDeliveryEvent
            && in_array($payload['event'], ['bounced', 'dropped'], true)
        ) {
            $reasons = [];
            foreach (['description', 'error', 'reason'] as $field) {
                if (!array_key_exists($field, $payload)) {
                    continue;
                }

                if (!is_string($payload[$field])) {
                    throw new ParseException(sprintf('Invalid field "%s".', $field));
                }

                if ('' !== $payload[$field]) {
                    $reasons[] = $payload[$field];
                }
            }

            $event->setReason(implode('|', $reasons));
        }

        $event->setDate($date);
        $event->setRecipientEmail($payload['email']);

        if (array_key_exists('custom_vars', $payload) && !is_string($payload['custom_vars'])) {
            throw new ParseException('Invalid field "custom_vars".');
        }

        try {
            if (isset($payload['custom_vars']) && '' !== $payload['custom_vars']) {
                $metadata = json_decode($payload['custom_vars'], true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                if (!is_array($metadata)) {
                    throw new ParseException('Custom vars must decode to an array.');
                }

                $event->setMetadata($metadata);
            }
        } catch (JsonException $e) {
            throw new ParseException('Json decode custom vars exception: ' . $e->getMessage());
        }

        return $event;
    }
}

<?php

declare(strict_types=1);

use Symfony\Component\RemoteEvent\Event\Mailer\MailerEngagementEvent;

$wh = new MailerEngagementEvent(
    MailerEngagementEvent::UNSUBSCRIBE,
    'a1e1c93a7e29cdee08a589f02c095542',
    json_decode(
        file_get_contents(str_replace('.php', '.json', __FILE__)),
        true,
        flags: JSON_THROW_ON_ERROR
    )
);

$wh->setRecipientEmail('to_unsubscribed@example.com');
$wh->setTags([]);
$wh->setMetadata([]);
$wh->setDate(DateTimeImmutable::createFromFormat('Y-m-d H:i:s', '2023-01-01 15:14:00'));

return $wh;

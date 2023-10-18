<?php

declare(strict_types=1);

use Symfony\Component\RemoteEvent\Event\Mailer\MailerEngagementEvent;

$wh = new MailerEngagementEvent(
    MailerEngagementEvent::SPAM,
    '4cf311bd836e30fa0ee3e228b49460f2',
    json_decode(
        file_get_contents(str_replace('.php', '.json', __FILE__)),
        true,
        flags: JSON_THROW_ON_ERROR
    )
);

$wh->setRecipientEmail('to_complained@example.com');
$wh->setTags([]);
$wh->setMetadata([]);
$wh->setDate(DateTimeImmutable::createFromFormat('Y-m-d H:i:s', '2023-01-01 08:07:55'));

return $wh;

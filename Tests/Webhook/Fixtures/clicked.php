<?php

declare(strict_types=1);

use Symfony\Component\RemoteEvent\Event\Mailer\MailerEngagementEvent;

$wh = new MailerEngagementEvent(
    MailerEngagementEvent::CLICK,
    'e3b710151264b65943bcc6bbdcab9e6a',
    json_decode(
        file_get_contents(str_replace('.php', '.json', __FILE__)),
        true,
        flags: JSON_THROW_ON_ERROR
    )
);

$wh->setRecipientEmail('to_clicked@example.com');
$wh->setTags([]);
$wh->setMetadata([]);
$wh->setDate(DateTimeImmutable::createFromFormat('Y-m-d H:i:s', '2023-01-01 11:00:07'));

return $wh;

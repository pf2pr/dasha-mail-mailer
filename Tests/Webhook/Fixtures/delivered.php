<?php

declare(strict_types=1);

use Symfony\Component\RemoteEvent\Event\Mailer\MailerDeliveryEvent;

$wh = new MailerDeliveryEvent(
    MailerDeliveryEvent::DELIVERED,
    'c5e1218affbba34a773e6c448631c892',
    json_decode(
        file_get_contents(str_replace('.php', '.json', __FILE__)),
        true,
        flags: JSON_THROW_ON_ERROR
    )
);

$wh->setRecipientEmail('to_delivered@example.com');
$wh->setTags([]);
$wh->setMetadata([]);
$wh->setDate(DateTimeImmutable::createFromFormat('Y-m-d H:i:s', '2023-01-01 01:42:02'));

return $wh;

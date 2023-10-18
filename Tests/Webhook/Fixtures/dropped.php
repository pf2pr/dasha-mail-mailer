<?php

declare(strict_types=1);

use Symfony\Component\RemoteEvent\Event\Mailer\MailerDeliveryEvent;

$wh = new MailerDeliveryEvent(
    MailerDeliveryEvent::DROPPED,
    '5a54eb2ca111d8e6de55ac4ef641f484',
    json_decode(
        file_get_contents(str_replace('.php', '.json', __FILE__)),
        true,
        flags: JSON_THROW_ON_ERROR
    )
);

$wh->setRecipientEmail('to_dropped@example.com');
$wh->setTags([]);
$wh->setMetadata([]);
$wh->setDate(DateTimeImmutable::createFromFormat('Y-m-d H:i:s', '2023-01-01 06:04:00'));
$wh->setReason('пользователь уже отписан|пользователь уже отписан');

return $wh;

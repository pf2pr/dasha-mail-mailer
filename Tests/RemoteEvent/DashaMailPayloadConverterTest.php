<?php

declare(strict_types=1);

namespace Pf2Pr\DashaMailMailer\Tests\RemoteEvent;

use Pf2Pr\DashaMailMailer\RemoteEvent\DashaMailPayloadConverter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RemoteEvent\Exception\ParseException;

/**
 * @internal
 *
 * @coversNothing
 */
final class DashaMailPayloadConverterTest extends TestCase
{
    public function testRejectsImpossibleDate(): void
    {
        $payload = [
            'event'       => 'delivered',
            'message_id'  => 'message-id',
            'event_time'  => '2023-02-31 10:00:00',
            'email'       => 'recipient@example.com',
            'custom_vars' => '',
        ];

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Invalid date "2023-02-31 10:00:00".');

        (new DashaMailPayloadConverter())->convert($payload);
    }
}

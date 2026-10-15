<?php

declare(strict_types=1);

namespace Pf2Pr\DashaMailMailer\Tests\RemoteEvent;

use Pf2Pr\DashaMailMailer\RemoteEvent\DashaMailPayloadConverter;
use PHPUnit\Framework\Attributes\DataProvider;
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

        (new DashaMailPayloadConverter())->convert($payload);
    }

    #[DataProvider('invalidRequiredFieldsProvider')]
    public function testRejectsInvalidRequiredFields(string $field, mixed $value, bool $missing): void
    {
        $payload = $this->createPayload();
        if ($missing) {
            unset($payload[$field]);
        } else {
            $payload[$field] = $value;
        }

        $this->expectException(ParseException::class);

        (new DashaMailPayloadConverter())->convert($payload);
    }

    /**
     * @return iterable<string, array{string, mixed, bool}>
     */
    public static function invalidRequiredFieldsProvider(): iterable
    {
        foreach (['event' => ['delivered'], 'email' => [], 'message_id' => 42, 'event_time' => 42] as $field => $value) {
            yield $field . ' missing' => [$field, null, true];
            yield $field . ' invalid type' => [$field, $value, false];
        }
    }

    public function testRejectsDateContainingNullByte(): void
    {
        $payload               = $this->createPayload();
        $payload['event_time'] = "2023-02-28 10:00:00\0";

        $this->expectException(ParseException::class);

        (new DashaMailPayloadConverter())->convert($payload);
    }

    #[DataProvider('scalarMetadataProvider')]
    public function testRejectsScalarMetadata(string $metadata): void
    {
        $payload                = $this->createPayload();
        $payload['custom_vars'] = $metadata;

        $this->expectException(ParseException::class);

        (new DashaMailPayloadConverter())->convert($payload);
    }

    /**
     * @return iterable<array{string}>
     */
    public static function scalarMetadataProvider(): iterable
    {
        foreach (['null', '0'] as $metadata) {
            yield [$metadata];
        }
    }

    /**
     * @param array<mixed> $expected
     */
    #[DataProvider('acceptedMetadataProvider')]
    public function testPreservesMetadata(string $metadata, array $expected): void
    {
        $payload                = $this->createPayload();
        $payload['custom_vars'] = $metadata;

        $event = (new DashaMailPayloadConverter())->convert($payload);

        self::assertSame($expected, $event->getMetadata());
    }

    /**
     * @return iterable<string, array{string, array<mixed>}>
     */
    public static function acceptedMetadataProvider(): iterable
    {
        yield 'object' => ['{"campaign":"welcome","nested":{"id":42}}', ['campaign' => 'welcome', 'nested' => ['id' => 42]]];
        yield 'array' => ['["welcome",{"id":42}]', ['welcome', ['id' => 42]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function createPayload(): array
    {
        return [
            'event'      => 'delivered',
            'message_id' => 'message-id',
            'event_time' => '2023-02-28 10:00:00',
            'email'      => 'recipient@example.com',
        ];
    }
}

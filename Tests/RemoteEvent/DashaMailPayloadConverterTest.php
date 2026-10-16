<?php

declare(strict_types=1);

namespace Pf2Pr\DashaMailMailer\Tests\RemoteEvent;

use Pf2Pr\DashaMailMailer\RemoteEvent\DashaMailPayloadConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerDeliveryEvent;
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

    #[DataProvider('invalidReasonFieldsProvider')]
    public function testRejectsInvalidReasonFields(string $event, string $field, mixed $value): void
    {
        $payload           = $this->createPayload();
        $payload['event']   = $event;
        $payload[$field]    = $value;

        $this->expectException(ParseException::class);

        (new DashaMailPayloadConverter())->convert($payload);
    }

    /**
     * @return iterable<string, array{string, string, mixed}>
     */
    public static function invalidReasonFieldsProvider(): iterable
    {
        yield 'bounced description array' => ['bounced', 'description', ['invalid']];
        yield 'bounced error object' => ['bounced', 'error', (object) ['message' => 'invalid']];
        yield 'bounced reason integer' => ['bounced', 'reason', 42];
        yield 'dropped description null' => ['dropped', 'description', null];
        yield 'dropped error boolean' => ['dropped', 'error', false];
        yield 'dropped reason empty array' => ['dropped', 'reason', []];
    }

    /**
     * @param array<string, string> $fields
     */
    #[DataProvider('acceptedReasonFieldsProvider')]
    public function testPreservesReasonContent(string $eventName, array $fields, string $expected): void
    {
        $payload          = $this->createPayload();
        $payload['event'] = $eventName;
        $payload          = array_merge($payload, $fields);

        $event = (new DashaMailPayloadConverter())->convert($payload);

        self::assertInstanceOf(MailerDeliveryEvent::class, $event);
        self::assertSame($expected, $event->getReason());
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function acceptedReasonFieldsProvider(): iterable
    {
        yield 'joining order and content' => [
            'bounced',
            ['description' => ' description ', 'error' => 'error', 'reason' => 'reason|detail'],
            ' description |error|reason|detail',
        ];
        yield 'zero strings' => [
            'dropped',
            ['description' => '0', 'error' => '0', 'reason' => '0'],
            '0|0|0',
        ];
        yield 'missing and empty fields' => [
            'bounced',
            ['error' => '', 'reason' => 'reason'],
            'reason',
        ];
    }

    #[DataProvider('invalidMetadataTypesProvider')]
    public function testRejectsInvalidMetadataTypes(mixed $metadata): void
    {
        $payload                = $this->createPayload();
        $payload['custom_vars'] = $metadata;

        $this->expectException(ParseException::class);

        (new DashaMailPayloadConverter())->convert($payload);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidMetadataTypesProvider(): iterable
    {
        yield 'object' => [(object) ['campaign' => 'welcome']];
        yield 'array' => [['campaign' => 'welcome']];
        yield 'integer' => [42];
        yield 'false' => [false];
        yield 'null' => [null];
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
        yield 'large integer identifiers' => [
            '{"ids":[18446744073709551615,18446744073709551614],"negative":-18446744073709551616}',
            ['ids' => ['18446744073709551615', '18446744073709551614'], 'negative' => '-18446744073709551616'],
        ];
        yield 'native integer boundaries' => [
            '{"max":' . PHP_INT_MAX . ',"min":' . PHP_INT_MIN . ',"fraction":1.5}',
            ['max' => PHP_INT_MAX, 'min' => PHP_INT_MIN, 'fraction' => 1.5],
        ];
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

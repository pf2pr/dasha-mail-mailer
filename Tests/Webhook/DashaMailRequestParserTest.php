<?php

declare(strict_types=1);

namespace Pf2Pr\DashaMailMailer\Tests\Webhook;

use Pf2Pr\DashaMailMailer\RemoteEvent\DashaMailPayloadConverter;
use Pf2Pr\DashaMailMailer\Webhook\DashaMailRequestParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Webhook\Client\RequestParserInterface;
use Symfony\Component\Webhook\Exception\RejectWebhookException;
use Symfony\Component\Webhook\Test\AbstractRequestParserTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class DashaMailRequestParserTest extends AbstractRequestParserTestCase
{
    /**
     * @param array<string, mixed> $changes
     */
    #[DataProvider('invalidPayloadProvider')]
    public function testRejectsMalformedPayload(array $changes): void
    {
        $payload = array_replace($this->createPayload(), $changes);
        if (!array_key_exists('secret', $payload)) {
            $payload['secret'] = md5($payload['email'] . $payload['message_id'] . $this->getSecret());
        }
        $request = Request::create('/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload, JSON_THROW_ON_ERROR));

        try {
            $this->createRequestParser()->parse($request, $this->getSecret());
            self::fail('Malformed payload was accepted.');
        } catch (RejectWebhookException $e) {
            self::assertSame(406, $e->getStatusCode());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidPayloadProvider(): iterable
    {
        yield 'array email before authentication' => [['email' => [], 'secret' => 'invalid']];
        yield 'numeric message id with valid signature' => [['message_id' => 42]];
        yield 'converter error becomes HTTP rejection' => [['custom_vars' => 'null']];
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

    protected function createRequestParser(): RequestParserInterface
    {
        return new DashaMailRequestParser(new DashaMailPayloadConverter());
    }

    protected function getSecret(): string
    {
        return 'secret-hKEz2QK38UqofI69RGuJ9w3TIiWh9dL5';
    }
}

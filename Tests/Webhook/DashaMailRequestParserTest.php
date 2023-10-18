<?php

declare(strict_types=1);

namespace Pf2Pr\DashaMailMailer\Tests\Webhook;

use Pf2Pr\DashaMailMailer\RemoteEvent\DashaMailPayloadConverter;
use Pf2Pr\DashaMailMailer\Webhook\DashaMailRequestParser;
use Symfony\Component\Webhook\Client\RequestParserInterface;
use Symfony\Component\Webhook\Test\AbstractRequestParserTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class DashaMailRequestParserTest extends AbstractRequestParserTestCase
{
    protected function createRequestParser(): RequestParserInterface
    {
        return new DashaMailRequestParser(new DashaMailPayloadConverter());
    }

    protected function getSecret(): string
    {
        return 'secret-hKEz2QK38UqofI69RGuJ9w3TIiWh9dL5';
    }
}

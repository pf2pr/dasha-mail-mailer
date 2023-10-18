<?php

declare(strict_types=1);

namespace Pf2Pr\DashaMailMailer\Webhook;

use Pf2Pr\DashaMailMailer\RemoteEvent\DashaMailPayloadConverter;
use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\RemoteEvent\Event\Mailer\AbstractMailerEvent;
use Symfony\Component\RemoteEvent\Exception\ParseException;
use Symfony\Component\Webhook\Client\AbstractRequestParser;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

final class DashaMailRequestParser extends AbstractRequestParser
{
    public function __construct(
        private readonly DashaMailPayloadConverter $converter,
    ) {
    }

    protected function getRequestMatcher(): RequestMatcherInterface
    {
        return new ChainRequestMatcher(
            [
                new MethodRequestMatcher('POST'),
            ]
        );
    }

    protected function doParse(Request $request, string $secret): ?AbstractMailerEvent
    {
        $content = $request->getPayload()->all();
        if (
            !isset($content['event'])
            || !isset($content['email'])
            || !isset($content['message_id'])
            || !isset($content['event_time'])
            || !isset($content['secret'])
        ) {
            throw new RejectWebhookException(406, 'Payload is malformed.');
        }

        if ($content['secret'] !== md5($content['email'] . $content['message_id'] . $secret)) {
            throw new RejectWebhookException(406, 'Payload is malformed.');
        }

        try {
            return $this->converter->convert($content);
        } catch (ParseException $e) {
            throw new RejectWebhookException(406, $e->getMessage(), $e);
        }
    }
}

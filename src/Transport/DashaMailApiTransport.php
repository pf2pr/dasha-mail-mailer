<?php

declare(strict_types=1);

namespace Pf2Pr\DashaMailMailer\Transport;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function in_array;

/**
 * @see https://docs.dashamail.ru.
 */
final class DashaMailApiTransport extends AbstractApiTransport
{
    private const DASHA_MAIL_SPECIFIC_ERROR_CODES = [
        '2'  => 'ошибка при добавлении в базу',
        '3'  => 'заданы не все необходимые параметры',
        '4'  => 'нет данных при выводе',
        '5'  => 'у пользователя нет адресной базы с таким id',
        '6'  => 'некорректный email-адрес',
        '7'  => 'такой пользователь уже есть в этой адресной базе',
        '8'  => 'лимит по количеству активных подписчиков на тарифном плане клиента',
        '9'  => 'нет такого подписчика у клиента',
        '10' => 'пользователь уже отписан',
        '11' => 'нет данных для обновления подписчика',
        '12' => 'не заданы элементы списка',
        '13' => 'не задано время рассылки',
        '14' => 'Не задан заголовок письма',
        '15' => 'Не задано поле От Кого?',
        '16' => 'Не задан обратный адрес',
        '17' => 'Не задана ни html ни plain_text версия письма',
        '18' => 'Нет ссылки отписаться [ссылки с id="unsub_link"] в тексте рассылки. Пример ссылки: отписаться',
        '19' => 'Нет ссылки отписаться [%ОТПИСАТЬСЯ%] в тексте рассылки.',
        '20' => 'задан недопустимый статус рассылки',
        '21' => 'рассылка уже отправляется',
        '22' => 'у вас нет кампании с таким campaign_id',
        '23' => 'нет такого поля для сортировки',
        '24' => 'заданы недопустимые события для авторассылки',
        '25' => 'загружаемый файл уже существует',
        '26' => 'загружаемый файл больше 5 Мб',
        '27' => 'файл не найден',
        '28' => 'указанный шаблон не существует',
        '29' => 'определен одноразовый email-адрес',
        '30' => 'отправка рассылок заблокирована по подозрению в спаме',
        '31' => 'массив email-адресов пуст',
        '32' => 'нет корректных адресов для добавления',
        '33' => 'недопустимый формат файла',
        '34' => 'необходимо настроить собственный домен отправки',
        '35' => 'данный функционал недоступен на бесплатных тарифах и во время триального периода',
        '36' => 'ошибка при отправке письма',
        '37' => 'рассылка еще не прошла модерацию',
        '38' => 'недопустимый сегмент',
        '39' => 'нет папки с таким id',
        '40' => 'рассылка не находится в статусе PROCESSING или SENT',
        '41' => 'рассылка не отправляется в данный момент',
        '42' => 'у вас нет рассылки на паузе с таким campaign_id',
        '43' => 'Пользователь в черном списке (двойная отписка)',
        '44' => 'Пользователь в черном списке (нажатие «это спам»)',
        '45' => 'Пользователь в черном списке (ручное)',
        '46' => 'Несуществующий email-адрес (находится в глобальном списке возвратов)',
        '47' => 'Ваш ip-адрес не включен в список разрешенных',
        '48' => 'Не удалось отправить письмо подтверждения для обратного адреса.',
        '49' => 'Такой адрес уже подтвержден',
        '50' => 'Нельзя использовать одноразовые email в обратном адресе!',
        '51' => 'Использование обратного адреса на публичных доменах Mail.ru СТРОГО ЗАПРЕЩЕНО политикой DMARC данного почтового провайдера.',
        '52' => 'Email-адрес не подтвержден в качестве отправителя',
        '53' => 'Недопустимое событие для webhook',
        '54' => 'Некорректный домен. Кириллические и другие национальные домены в качестве DKIM/SPF запрещены.',
        '55' => 'Данный домен находится в черном списке, его добавление запрещено.',
        '56' => 'Данный домен занят другим аккаунтом',
    ];

    public function __construct(
        #[SensitiveParameter] private readonly string $key,
        private readonly bool $noTrackOpens = false,
        private readonly bool $noTrackClicks = false,
        HttpClientInterface $client = null,
        EventDispatcherInterface $dispatcher = null,
        LoggerInterface $logger = null
    ) {
        parent::__construct($client, $dispatcher, $logger);
    }

    public function __toString(): string
    {
        return sprintf('dashamail+api://%s', $this->getEndpoint());
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        $body = new FormDataPart($this->getPayload($email, $envelope));

        $headers = [];
        foreach ($body->getPreparedHeaders()->all() as $header) {
            $headers[] = $header->toString();
        }

        $statusCode = null;
        $response   = $this->client->request(
            'POST',
            'https://' . $this->getEndpoint(),
            [
                'headers' => $headers,
                'query'   => [
                    'method'  => 'transactional.send',
                    'api_key' => $this->key,
                ],
                'body' => $body->bodyToIterable(),
            ]
        );

        try {
            $statusCode = $response->getStatusCode();
            $result     = $response->toArray(false);
        } catch (DecodingExceptionInterface) {
            throw new HttpTransportException(
                'Unable to send an email: ' . $response->getContent(false) . sprintf(' (code %d).', $statusCode),
                $response
            );
        } catch (TransportExceptionInterface $e) {
            throw new HttpTransportException('Could not reach the remote Mailgun server.', $response, 0, $e);
        }

        if (200 !== $statusCode) {
            throw new HttpTransportException(
                'Unable to send an email: ' . $result['message'] . sprintf(' (code %d).', $statusCode),
                $response
            );
        }

        if (isset($result['response']['msg']['err_code']) && 0 !== $result['response']['msg']['err_code']) {
            throw new HttpTransportException(
                $this->getErrorMessage($result),
                $response
            );
        }

        if (isset($result['response']['data']['transaction_id'])) {
            $sentMessage->setMessageId($result['response']['data']['transaction_id']);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function getPayload(Email $email, Envelope $envelope): array
    {
        [$attachments, $inlines] = $this->prepareAttachments($email);

        $payload = [
            'format'          => 'json',
            'no_track_opens'  => $this->noTrackOpens ? 'true' : 'false',
            'no_track_clicks' => $this->noTrackClicks ? 'true' : 'false',
            'from_email'      => $envelope->getSender()->getEncodedAddress(),
            'to'              => implode(',', $this->stringifyAddresses($this->getRecipients($email, $envelope))),
            'subject'         => $email->getSubject(),
            'attachments'     => $attachments,
            'inline'          => $inlines,
        ];

        if ('' !== $envelope->getSender()->getName()) {
            $payload['from_name'] = $envelope->getSender()->getName();
        }

        if ($cc = $email->getCc()) {
            $payload['cc'] = implode(',', $this->stringifyAddresses($cc));
        }

        if ($bcc = $email->getBcc()) {
            $payload['bcc'] = implode(',', $this->stringifyAddresses($bcc));
        }

        if ($email->getTextBody()) {
            $payload['plain_text'] = $email->getTextBody();
        }

        if ($email->getHtmlBody()) {
            $payload['message'] = $email->getHtmlBody();
        }

        if ($headers = $this->prepareHeaders($email->getHeaders())) {
            $payload = array_merge($payload, $headers);
        }

        return $payload;
    }

    /**
     * @return array<mixed>
     */
    private function prepareAttachments(Email $email): array
    {
        $attachments = $inlines = [];
        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            if ('inline' === $headers->getHeaderBody('Content-Disposition')) {
                $inlines[] = [
                    'mime_type' => $attachment->getMediaType() . '/' . $attachment->getMediaSubtype(),
                    'filename'  => $attachment->getFilename(),
                    'body'      => $attachment->bodyToString(),
                    'cid'       => $attachment->getFilename(),
                ];
            } else {
                $attachments[] = $attachment;
            }
        }

        return [
            $attachments,
            json_encode($inlines),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareHeaders(Headers $headers): array
    {
        $headersAndTags  = [];
        $headersToBypass = [
            'from',
            'sender',
            'to',
            'cc',
            'bcc',
            'subject',
            'reply-to',
            'content-type',
            'accept',
        ];
        foreach ($headers->all() as $name => $header) {
            if (in_array($name, $headersToBypass, true)) {
                continue;
            }
            if ($header instanceof TagHeader) {
                $headersAndTags['headers']['X-Tag'][] = $header->getValue();

                continue;
            }
            if ($header instanceof MetadataHeader) {
                $headersAndTags['headers']['X-Metadata-' . $header->getKey()] = $header->getValue();

                continue;
            }

            $headersAndTags['headers'][$header->getName()] = $header->getBodyAsString();
        }

        return $headersAndTags;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function getErrorMessage(array $result): string
    {
        if (isset($result['response']['msg']['text'])) {
            return $result['response']['msg']['text'];
        }

        if (isset($result['response']['msg']['err_code'])) {
            return self::DASHA_MAIL_SPECIFIC_ERROR_CODES[$result['response']['msg']['err_code']] ?? 'unknown error code: ' . $result['response']['msg']['err_code'];
        }

        return 'unknown error';
    }

    private function getEndpoint(): string
    {
        return ($this->host ?: 'api.dashamail.com') . ($this->port ? ':' . $this->port : '');
    }
}

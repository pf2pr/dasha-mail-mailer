# DashaMail Bridge

Provides DashaMail integration for Symfony Mailer.

## Installation
```sh
composer config extra.symfony.allow-contrib true
composer require pf2pr/dasha-mail-mailer
```

Supported versions:

- PHP 8.1 or later;
- Symfony Mailer 6.4, 7.4 or 8.1.

This package is a Symfony Mailer bridge. In full Symfony applications, the contributed Flex recipe registers the optional `DashaMailMailerBundle` DI adapter. Without the recipe, register the adapter manually:

```php
// config/bundles.php
return [
    // ...
    Pf2Pr\DashaMailMailer\DashaMailMailerBundle::class => ['all' => true],
];
```

The API transport additionally requires Symfony HttpClient:

```sh
composer require symfony/http-client
```

## Configuration

```env
# SMTP
MAILER_DSN=dashamail+smtp://USERNAME:PASSWORD@default?no_track_opens=false&no_track_clicks=false

# API
MAILER_DSN=dashamail+api://KEY@default?no_track_opens=false&no_track_clicks=false
```

where:
- `KEY` is your DashaMail API Key
- `no_track_opens` disabling tracking opens. Default is `false`
- `no_track_clicks` disabling tracking (substitution) of links. Default is `false`

The API transport uses DashaMail REST API v2.

SMTP tracking options apply to both `Email` and `RawMessage`. Sending serialized
MIME requires an explicit envelope. Enabled options append their tracking headers
without rewriting existing headers or the MIME body.

### API delivery semantics

- The envelope recipient list controls delivery to `To`, `Cc` and `Bcc`. Symfony
  `framework.mailer.envelope.recipients` overrides also exclude original copy
  recipients unless they are included in the overridden envelope.
- The visible sender comes from the message's `From` header, or `Sender` when
  `From` is absent; overriding the envelope sender does not replace it.
- Plain-text and HTML bodies support strings and streams. Their declared charset
  is converted to UTF-8 for the API request. An explicit MIME body set with
  `Email::setBody()` takes precedence over the text, HTML and attachment builder
  fields. Plain-text, HTML and standard alternative/related/mixed MIME parts are
  supported; bodies that cannot be represented by the API are rejected.
- Named inline image references in HTML `src`/`background` attributes and CSS
  `url(...)` values are resolved as complete URLs, preserving custom content IDs.
  Literal CID text does not turn a regular file attachment into an inline image.
- Regular and inline attachment streams preserve their bytes, including `"0"`,
  binary data and original line endings, independently of MIME transfer encoding.
- HTTP transport failures use Symfony Mailer exceptions and participate in
  `failover(...)`, including failures raised before an HTTP response is available
  and HTTP errors when the client disables response buffering.

### Per-message options

The same DashaMail headers work with API and SMTP transports:

| Header | Purpose |
| --- | --- |
| `DM-Message-ID` | Correlate a message with delivery events |
| `DM-Campaign-ID` | Group messages in statistics |
| `DM-Delivery-Timestamp` | Schedule delivery using a Unix timestamp |
| `DM-Domain` | Select the sending domain |
| `DM-Ignore-Delivery-Policy` | Ignore the account delivery policy |
| `DM-No-Track-Opens` | Disable open tracking |
| `DM-No-Track-Clicks` | Disable click tracking |

```php
$email->getHeaders()->addTextHeader('DM-Message-ID', 'order-123');
$email->getHeaders()->addTextHeader('DM-Campaign-ID', 'order-paid');
$email->getHeaders()->addTextHeader('DM-Delivery-Timestamp', '1790000000');
```


### Configure headers

```yaml
# config/packages/mailer.yaml
framework:
  mailer:
    dsn: '%env(MAILER_DSN)%'
    headers:
      X-Dashamail-Unsub-Ignore: '1'
```

### Webhooks

Webhook handling is optional. Install the required Symfony components before using
`DashaMailRequestParser` or `DashaMailPayloadConverter`:

```sh
composer require symfony/webhook symfony/remote-event
```

Required webhook fields (`event`, `email`, `message_id`, `event_time` and `secret`)
must be strings. Optional `custom_vars` accepts an empty string or a JSON object
or array encoded as a string. Invalid field types, dates, JSON and scalar JSON
metadata are rejected with HTTP 406. Direct use of `DashaMailPayloadConverter`
reports malformed event data with `ParseException`.
Integer values in `custom_vars` outside PHP's native integer range are preserved
as decimal strings rather than rounded floating-point values, including nested
metadata. Native-range integers and fractional numbers retain their numeric types.
For bounced and dropped events, optional `description`, `error` and `reason`
fields must also be strings; non-empty values are joined in that order.

## Resources

- [DashaMail REST API v2](https://dashamail.ru/api/)
- [DashaMail SMTP](https://dashamail.ru/smtp/)

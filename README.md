# DashaMail Bridge

Provides DashaMail integration for Symfony Mailer.

## Installation
```sh
composer require pf2pr/dasha-mail-mailer
```

Supported versions:

- PHP 8.1 or later;
- Symfony Mailer 6.4, 7.4 or 8.1.

Register the bundle in Symfony applications:

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

## Resources

- [DashaMail REST API v2](https://dashamail.ru/api/)
- [DashaMail SMTP](https://dashamail.ru/smtp/)

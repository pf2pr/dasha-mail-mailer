DashaMail Bridge
=================

Provides DashaMail integration for Symfony Mailer.

Installation
---------
```sh
composer require pf2pr/dasha-mail-mailer
```

Configuration example
---------

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


Resources
---------

* [DashaMail doc](https://docs.dashamail.ru/)

# Grav Email SMTP2GO Plugin

The **Email SMTP2GO** plugin adds [SMTP2GO](https://www.smtp2go.com/) as a transport for the Grav [Email plugin](https://github.com/getgrav/grav-plugin-email). It supports both:

- **API** (HTTPS POST to `https://api.smtp2go.com/v3/email/send`)
- **SMTP** (relay through `mail.smtp2go.com`)

## Requirements

- Grav `>= 1.7`
- Email plugin `>= 4.0` (Symfony Mailer based)
- An SMTP2GO account with an **authorized sender domain**

## Installation

Manual install:

```bash
cd /path/to/grav/user/plugins
git clone https://github.com/getgrav/grav-plugin-email-smtp2go email-smtp2go
cd email-smtp2go
composer install --no-dev
```

## Configuration

In the Grav admin go to **Plugins → Email SMTP2GO**, enable the plugin, then go to **Plugins → Email** and set **Engine** to `SMTP2GO`.

### API transport (recommended)

1. In the [SMTP2GO dashboard](https://app.smtp2go.com/) create an **API key** under *Settings → API Keys*. Give it `send-only` permission.
2. Paste the key (`api-...`) into the **API Key** field.
3. Leave **Transport** set to `API`.

### SMTP transport

1. In the SMTP2GO dashboard create an **SMTP user** under *Settings → Users*.
2. Set **Transport** to `SMTP`.
3. Enter the SMTP **Username** and **Password**.
4. Pick a port:
   - `2525` (default, STARTTLS) — most reliable through firewalls
   - `587`, `25`, `8025` — also STARTTLS
   - `465` — implicit TLS (also enable the **Implicit TLS** toggle)

## Sender Domain

SMTP2GO requires the `From:` address to use a domain you have verified in *Settings → Sender Domains*. In the main Email plugin set **From Email** to an address on that domain.

## License

MIT — see [LICENSE](LICENSE).

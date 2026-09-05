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

## Delivery reports

Sending mail tells you it left the building. A delivery report tells you what happened to it — whether it arrived, whether it bounced and permanently or not, whether somebody marked it as spam, opened it or clicked something in it. SMTP2GO sends all of that to a web address of yours as it happens, and this plugin is what reads it.

You do not set any of this up here. A plugin that wants delivery reports — the KahunaCart Newsletter add-on is the one that does today — mints the address, shows it on its own screen and asks this plugin to check and read what arrives. This plugin's job is knowing SMTP2GO: which of their event names means what, that a permanent failure is the word `hard`, where they put an echoed header, and what a sending domain's DNS has to say. That used to live in the add-on, which meant a sixth provider was a change to somebody else's plugin. Now it lives here.

**What you get once it is set up.** Bounced addresses stop being mailed. Complaints do the same. Delivered, opened and clicked counts fill in per campaign. Nothing else changes about how mail is sent.

**The one button.** Paste your API key into the field above and press whatever the other plugin calls Set up — in the newsletter add-on it is "Set up in SMTP2GO". The webhook is created for you with the right events, the JSON output format and the send header registered. Pressing it twice leaves one webhook, not two. The key needs the Webhooks permission: in SMTP2GO, open **Settings → API Keys**, open your key and tick **Webhooks**. A send-only key sends mail perfectly well and cannot create a webhook, and that is by far the most common reason the button refuses.

**Doing it by hand.** If you would rather, or if your key is send-only and staying that way:

1. In SMTP2GO open **Settings → Webhooks** and add a webhook.
2. Paste in the address the other plugin is showing you.
3. Set the output format to **JSON**. Their default is form-encoded, which nothing can read — and a webhook left on it looks perfectly healthy in their dashboard.
4. Tick **delivered**, **bounce**, **spam complaint**, **open** and **click**. Leave the rest; they are answered with a 200 and ignored.
5. Add `X-KahunaCart-Send` to the webhook's own **Headers** list. SMTP2GO only sends `Subject` and `Message-id` back by default, and a header that is not named here is never echoed.

**How the address is protected.** SMTP2GO does not sign its webhooks — there is no signature to check and no setting that adds one — so the secret in the address is the whole of it, which is why the plugin that mints it makes it long. SMTP2GO's dashboard does offer an optional `Authorization` header. If you set one by hand, tell the other plugin what you set and it will be checked as well.

## Sender Domain

SMTP2GO requires the `From:` address to use a domain you have verified in *Settings → Sender Domains*. In the main Email plugin set **From Email** to an address on that domain.

## License

MIT — see [LICENSE](LICENSE).

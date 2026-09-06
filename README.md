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

**What you get once it is set up.** Bounced addresses stop being mailed. Complaints do the same. So do the messages SMTP2GO refuses to send at all, which it calls **reject** and reports when the address is already on your account's suppression list. Delivered, opened and clicked counts fill in per campaign. Nothing else changes about how mail is sent.

**The one button.** Paste your API key into the field above and press whatever the other plugin calls Set up — in the newsletter add-on it is "Set up in SMTP2GO". The webhook is created for you with the right events, the JSON output format and the send header registered. Pressing it twice leaves one webhook, not two, and pressing it after the secret has changed points the existing webhook at the new address rather than adding a second. The key needs the **Webhooks** permission — see below for the three things on that screen that matter.

**Doing it by hand.** If you would rather, or if your key is send-only and staying that way:

1. In SMTP2GO open **Settings → Webhooks** and add a webhook.
2. Paste in the address the other plugin is showing you.
3. Set the output format to **JSON**. Their default is form-encoded, which nothing can read — and a webhook left on it looks perfectly healthy in their dashboard.
4. Tick **delivered**, **bounce**, **spam complaint**, **open**, **click** and **reject**. Leave the rest; they are answered with a 200 and ignored.
5. Add `X-Grav-Send-Id` to the webhook's own **Headers** list. SMTP2GO only sends `Subject` and `Message-id` back by default, and a header that is not named here is never echoed. That is the header unless the site has set `providers.send_header` in the Email plugin's own configuration, in which case use whatever it says — the add-on showing you this address will name it.

**How the address is protected.** SMTP2GO does not sign its webhooks — there is no signature to check and no setting that adds one — so the secret in the address is the whole of it, which is why the plugin that mints it makes it long. SMTP2GO's dashboard does offer an optional `Authorization` header. If you set one by hand, tell the other plugin what you set and it will be checked as well.

### What the API key has to be allowed to do

Ticked per key in SMTP2GO under **Settings → API Keys**, on the key's own **Permissions** tab. Three of the things on that screen matter, and nothing else does:

- **Emails** — `POST /v3/email/send`, which is how the API transport sends. Not needed on the SMTP transport, where the key is not used to send at all.
- **Webhooks** — `/v3/webhook/add`, `/v3/webhook/edit` and `/v3/webhook/view`, which is the Set up button creating the webhook, repointing it after a secret change, and reading back what is already registered.
- **Sender Domains** — `/v3/domain/view`, and view is enough. Optional: it is what lets a deliverability check read your domain's DKIM selector and custom return path straight from your account. Without it nothing breaks; somebody types the selector into the other plugin's settings by hand instead.

Statistics, Activity and the rest of that screen are not read by anything here. A **send-only** key sends mail perfectly well and cannot create a webhook, and that is by far the most common reason the Set up button refuses — SMTP2GO's own sentence comes back when it does.

### Tying a bounce back to the message it came from

Two paths, and the first needs nothing configured. SMTP2GO returns the `Subject` and `Message-id` headers on every event by default, and a site that mints its own `Message-ID` before a message leaves gets a direct join out of that alone.

The second is the send header, and it is the belt to that braces: a header of your own naming the row a message belongs to. SMTP2GO echoes a custom header **only when it is named on the webhook itself**, which is what the Set up button does for you and what step five above does by hand. Where the header is not registered, correlation falls back to the `Message-ID` and nothing is lost that was not already there.

### How a bounce is classified

SMTP2GO decides this themselves and says so: the `bounce` event carries the literal word `hard` or `soft`, read from the receiving server's answer. A hard bounce is an address that will never accept mail again and a soft one is a mailbox that was full this morning; the plugin reading these reports is what decides what to do about each.

Their **reject** is a third thing and worth knowing about separately. It is a message SMTP2GO would not send at all, and it means one of two things — the address is on your account's suppression list, has hard-bounced, complained or unsubscribed before, which is the address being refused; or the sender is not verified, which is the message being refused and has nothing to do with the recipient. This plugin tells the two apart from SMTP2GO's own wording and says which, so a store that fails to send from an unverified address does not lose the subscriber it was writing to.

### What a sending domain's DNS has to say

Four records, and three of them are CNAMEs SMTP2GO hands you when you add a sender domain rather than anything you compose:

| Name | Type | Value | What it is for |
| --- | --- | --- | --- |
| `yourdomain.com` | TXT | `v=spf1 include:spf.smtp2go.com ~all` | Says SMTP2GO may send as this domain. One include, two DNS lookups against a receiver's limit of ten |
| `em<id>.yourdomain.com` | CNAME | `return.smtp2go.net` | The custom return path — the envelope sender messages actually go out with, which is what makes SPF align on your domain rather than on SMTP2GO's |
| `s<id>._domainkey.yourdomain.com` | CNAME | `dkim.smtp2go.net` | The DKIM selector. A CNAME rather than a pasted key, so SMTP2GO can rotate the key without anybody touching DNS again |
| `link.yourdomain.com` | CNAME | `track.smtp2go.net` | Click and open tracking on your own hostname rather than SMTP2GO's, so nothing in a message points at a domain the reader does not recognise |

`<id>` is your SMTP2GO account's own numeric id and is different for every account. A deliverability check does not have to be told it: with **Sender Domains** ticked on the API key, this plugin reads the selector and the return path out of your account and hands them over.

## Sender Domain

SMTP2GO requires the `From:` address to use a domain you have verified in *Settings → Sender Domains*. In the main Email plugin set **From Email** to an address on that domain.

## License

MIT — see [LICENSE](LICENSE).

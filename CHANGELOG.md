# v1.1.0
## 09/04/2026

1. [](#new)
    * Delivery reports. This plugin now tells the Email plugin everything it knows about SMTP2GO through the Email plugin's new provider contract: how to read and check a delivery webhook, how to create one from the API key you already pasted in, what a sending domain's DNS has to say, and what each of the two transports does to a custom header. Anything on the site that records bounces, complaints, opens and clicks — the KahunaCart Newsletter add-on today — asks the Email plugin for it instead of carrying its own copy, so SMTP2GO renaming a field is one plugin to update rather than several. All of this used to live in the newsletter add-on and has moved here, where the API key already is.
    * A one-press setup for the webhook. Anything that shows a Set up button hands this plugin the address, and it creates the webhook in SMTP2GO with the JSON output format and the send header registered — the two settings that otherwise fail silently, because a webhook on their default format posts bodies nothing can read and an unregistered header is never echoed back. Pressing it twice leaves one webhook, and a key that is not allowed to manage webhooks comes back with SMTP2GO's own words plus the box to tick.
    * The API key's help text now says what it does. It is not only for sending: it is also what reads your sending domain's DKIM selectors and return paths out of SMTP2GO, which works on the SMTP transport too.
    * The header a send id travels in is now named by the Email plugin rather than by this one. It is `X-Grav-Send-Id`, or whatever `providers.send_header` in the Email plugin's configuration says, and the setup button registers whatever it is at the moment you press it. It used to be `X-KahunaCart-Send`, which was another product's name sitting in a Team Grav plugin. **A store that was already receiving delivery reports should press Set up again after upgrading**, because SMTP2GO only echoes a header that is named on the webhook and the name has changed.
    * SMTP2GO's `reject` is now reported, as the contract's `dropped`. It is SMTP2GO refusing to send at all — their own message for it is "Recipient address is on the account suppression list" — and it was previously answered with a 200 and a log line. Nothing was handed to a receiving server, so it is not a bounce, and whatever records these events decides for itself what a refused message means. The setup button now asks for it along with the other five.
    * A `reject` now says whether SMTP2GO refused the address or refused the message, so that whatever records these events can tell a subscriber who is gone from one who happened to be on the list on a bad morning. A reason naming the suppression list, a previous bounce, a spam complaint or an unsubscribe is the address, and a store may treat it as permanent; an unverified sender, and anything else that cannot be placed, is the message and touches nobody. It is matched on the words in SMTP2GO's own reason rather than on the whole sentence.
    * A test suite for the provider — SMTP2GO's own webhook payloads read field by field, the optional Authorization header accepted and refused, the setup call against a stand-in HTTP client, and the DNS facts pinned.

# v1.0.1
## 09/04/2026

1. [](#bugfix)
    * `Return-Path` is no longer copied into the API request as a custom header. It belongs to the envelope rather than the message — SMTP2GO writes its own so that bounces come back to them — and sending ours as well risked a duplicate on the delivered mail. Every other header set on the message is carried through as before, with its original case, so `List-Unsubscribe`, `List-Unsubscribe-Post`, `Precedence` and any `X-` header a plugin adds reach the recipient on the API path exactly as they do over SMTP.
    * Both transports no longer emit deprecation notices on PHP 8.4. Their constructors marked the optional client, dispatcher and logger arguments nullable by implication, which PHP 8.4 warns about; they are now declared nullable outright.

# v1.0.0
## 05/19/2026

1. [](#new)
    * Initial release. SMTP2GO integration for the Email plugin with both API and SMTP transports.

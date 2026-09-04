# v1.0.1
## 09/04/2026

1. [](#bugfix)
    * `Return-Path` is no longer copied into the API request as a custom header. It belongs to the envelope rather than the message — SMTP2GO writes its own so that bounces come back to them — and sending ours as well risked a duplicate on the delivered mail. Every other header set on the message is carried through as before, with its original case, so `List-Unsubscribe`, `List-Unsubscribe-Post`, `Precedence` and any `X-` header a plugin adds reach the recipient on the API path exactly as they do over SMTP.
    * Both transports no longer emit deprecation notices on PHP 8.4. Their constructors marked the optional client, dispatcher and logger arguments nullable by implication, which PHP 8.4 warns about; they are now declared nullable outright.

# v1.0.0
## 05/19/2026

1. [](#new)
    * Initial release. SMTP2GO integration for the Email plugin with both API and SMTP transports.

# Security Policy

[Català](SECURITY.ca.md) · [English](SECURITY.md) · [Castellano](SECURITY.es.md)

MetaWhatsApp touches, by definition, the most sensitive parts of a FreeScout installation: Meta API credentials, a publicly exposed webhook, work queues and an external integration that carries private customer conversations. Responsible security reports are therefore especially important to this project, and we take them seriously.

## Supported versions

| Version | Security support |
|---|---|
| 1.x (main branch) | Yes |
| Earlier versions | No |

This module targets FreeScout 1.8.x. Vulnerabilities in FreeScout versions no longer supported by the upstream project are out of scope here.

## How to report a vulnerability

Do not open public issues or pull requests for security vulnerabilities. Publishing before a fix exists puts every installation using the module at risk.

Private channels, in order of preference:

1. GitHub → Security → Report a vulnerability, in this repository.
2. Email: losimo@gmail.com

## What the report should include

Try to include, at minimum:

- Module version, FreeScout version and PHP version.
- A clear description of the vulnerability and its impact.
- Reproduction steps or a proof of concept, if you have one.
- Any configuration relevant to reproduce it.

Do not send secrets, tokens, passwords or private data that are not strictly necessary.

## Response commitment

This project is maintained on personal time. We do not promise a formal SLA, but we commit to:

- Acknowledging receipt, normally within a week.
- Making an initial assessment and proposing an action plan, normally within 30 days.
- Coordinating the fix and disclosure with the reporter before any public announcement.

## What we consider a vulnerability

We treat as security issues, among others:

- Any bypass of the webhook HMAC verification.
- Exposure of credentials, tokens or secrets in logs, HTTP responses or exceptions.
- Injection or manipulation via the webhook payload.
- Misattribution or cross-injection between accounts or channels.
- Authorization bypass in the administration screens.
- CSRF on sensitive administration actions.

## Out of scope

We do not consider vulnerabilities of this project, on their own:

- FreeScout core vulnerabilities (report them to [freescout-helpdesk/freescout](https://github.com/freescout-helpdesk/freescout)).
- Insecurely deployed installations, for example without HTTPS in production.
- Internal behavior of the Meta Cloud API itself.
- Limitations already documented in the README that do not imply a security bypass.

If you are unsure whether a case is a security issue or a functionality issue, prefer the private security channel.

# Contributing Guide

[Català](CONTRIBUTING.ca.md) · [English](CONTRIBUTING.md) · [Castellano](CONTRIBUTING.es.md)

MetaWhatsApp is an open project with responsible maintenance. Good faith is the starting point, but openness does not mean accepting any change without criteria. The project has a defined architecture, a clear MVP scope and a set of red lines that must be respected.

## Philosophy

We want useful, well-argued and easily maintainable contributions. Small, obvious changes are welcome; changes affecting architecture, security, core UX or functional scope must be discussed first.

The worst experience for everyone is a large, well-crafted PR that has to be rejected over a decision that could have been settled in an issue.

## Before you start

Pick the right channel:

| You have... | Do... |
|---|---|
| A reproducible bug | Open a bug issue. |
| A usage question | Open a question issue. |
| A change or feature proposal | Open a proposal issue. |
| A vulnerability | Never a public issue; use the private security channel. |

For small, obvious bugs, a direct PR is welcome. For anything that may touch architecture, security, core UX or scope, open an issue before working on the PR.

## What is welcome

- Bug fixes with a regression test.
- Documentation improvements and translations.
- Additional tests for existing behavior.
- UX improvements within the current scope.
- Compatibility with new FreeScout versions, as long as it does not alter the module's model.

## What requires prior discussion

Mandatory prior discussion is required for:

- New features, however small.
- Database schema changes.
- New dependencies.
- Refactors touching more than one component.
- Any change to the sensitive paths described below.

## Red lines

These are not negotiable through a direct PR:

1. Zero-core: the FreeScout core is never modified.
2. Security is never relaxed to simplify UX.
3. The fail-closed model of the webhook is never broken.
4. No functionality outside the MVP is added without prior agreement.
5. No premature dependencies or abstractions are introduced without strong justification.

## Sensitive changes

These areas always require prior discussion, however small the modification may seem:

- The webhook and the HMAC verification.
- Credential and secret handling.
- The jobs and the queue.
- Anything depending on FreeScout core semantics, such as hooks, the undo window, conversation types or `customer_channel`.

Direct PRs on these paths without a linked issue may be closed without merging. It is not distrust; in these areas mistakes are not always visible in the diff, they show up in production.

## Minimum PR requirements

Any PR should include:

- A clear description of the change.
- Motivation.
- Functional impact.
- Security impact, where applicable.
- Tests covering the change, or an explicit justification if there are none.
- Updated documentation, where needed.
- Code consistent with the existing style.

If the change alters any documented limitation or expectation, the documentation must be updated too.

## Language

**English is the working language of this codebase**: commit messages, code comments, test names, log messages and issue discussion. Only user-facing strings are translated, and those live in `Resources/lang/` (English, Catalan, Castellano and Dutch), never hardcoded.

**English is the base, the rest may lag.** New strings land in English first, and a translation that does not have them yet falls back to English on screen, never to a raw key. So a language file being behind is not a bug and does not block a release: it is an invitation to whoever maintains that language.

Earlier code carries Catalan comments and test names, from before the project had outside contributors. Those are being replaced as files are touched rather than in one sweep, so you will find both for a while. New code should be English throughout.

## Discussion policy

Always keep these cases separate:

- Bug.
- Question.
- Proposal.
- Vulnerability.

This keeps conversations tidy and speeds up decision-making.

## Maintenance tone

Reviews will be clear, frank and product-oriented. Proposals are accepted on impact, not on effort invested. Disagreements can be discussed, but new and concrete arguments are required.

## Tests

The suite can be run with:

```bash
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php Modules/MetaWhatsApp/Tests
```

Tests run against the installation database with per-test rollback and leave no persistent data.

## Final note

If you are unsure whether an idea fits the MVP, touches a sensitive path or may have security implications, open an issue before coding.

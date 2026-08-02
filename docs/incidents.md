# Incidents & operational notes

A lightweight log of things that went wrong — in production or in the release process itself — that cost real time to diagnose. Not a formal postmortem template: a few lines per entry is enough. The goal is to stop re-investigating the same gotcha from scratch next time.

Add an entry whenever something takes more than a few minutes to figure out and the answer isn't already obvious from the code or the README.

---

## 2026-07-28 — Publishing to GitHub required forensic reconstruction of the release workflow

**What happened:** Preparing to publish v1.4.1 to `github.com/losimo/freescout-meta-whatsapp`, the monorepo (`/Users/albert/Documents/odoo+freescout`) turned out to have no git remote configured at all — despite five prior releases (v1.0.1 through v1.4.0) having been published successfully. Figuring out how those releases actually got out required reflog archaeology: reading local commit history, comparing it against the public repo's commits, and noticing the public repo's commit messages (English, no `(MetaWhatsApp)` prefix) don't match the monorepo's local commits (Catalan, `tipus(MetaWhatsApp): missatge`) — different SHAs entirely.

**Root cause:** The monorepo intentionally has no remote, because it holds private Catalan development history for the whole Odoo+FreeScout stack, not just this module. Publishing has always gone through a throwaway flow: clone the public repo fresh into `/tmp`, `rsync` the module directory over (excluding `.git` and internal docs), write a fresh English commit, tag, and push — with no persistent link between the two repos to reconstruct the process from.

**Resolution:** Reconstructed and followed the 8-step flow (clone → rsync → verify `LICENSE` → English commit → tag → push via the `losimodev` SSH key → `gh release create` with `version.txt` and `metawhatsapp.zip` assets), then wrote it down so it doesn't need re-deriving again.

**Follow-up:** None needed — the workflow is now documented for future sessions. One standing gotcha worth remembering: `version.txt` must never be committed, it's a release asset generated fresh each time.

## 2026-07-31/08-02 — v1.5.0 shipped a fatal PHP error: `require` placed before `namespace`

**What happened:** v1.5.0 added `require_once __DIR__.'/../vendor/autoload.php';` at the very top of `MetaWhatsAppServiceProvider.php`, above the `namespace` declaration, to make the module self-contained (see the entry below on issue #13). This is invalid PHP — `namespace` must be the first statement in a file — so the module failed to load at all on any install running v1.5.0. The fatal error was never caught before release because the change was made, committed, and published without running the test suite against it.

**Root cause:** No automated or manual test run happened between writing the fix and publishing it. The change looked trivially correct (a one-line `require_once`) and skipped verification.

**Resolution:** Caught two days later while implementing v1.5.1 (official channel IDs, issue #4) and running the full test suite for the first time since v1.5.0 — `PHP Fatal error: Namespace declaration statement has to be the very first statement`. Moved the `require_once` to after the `namespace`/`use` block, before the class declaration (still module-root-relative, still resolves correctly). Shipped as part of v1.5.1, released immediately given the severity.

**Follow-up:** Any change to a file that ships in a release must be run through `vendor/bin/phpunit ... Modules/MetaWhatsApp/Tests` inside `freescout-integration` (see Tests section in README) *before* publishing — not just before merging. A one-line change is not exempt.

## 2026-08-02 — Issue #4 (channel IDs) resolved: FreeScout assigned 103/104 after 1001/1002 didn't fit

**What happened:** FreeScout support first assigned channel IDs `1001`/`1002` (see roadmap notes from 2026-07-15), which don't fit `customer_channel.channel`/`customers.channel` (`tinyint unsigned`, max 255) even on current master. After we reported this back, support corrected the assignment to `103`/`104`, which do fit.

**Resolution:** Shipped in v1.5.1: constants bumped from the provisional `100`/`101` to the official `103`/`104`, with a reversible data migration (`2026_08_02_000001_migrate_meta_whatsapp_channel_ids.php`) that remaps only this module's own rows in `customer_channel`/`customers` — identified via `meta_whatsapp_messages.contact_phone` for the phone channel, and unconditionally for the BSUID channel since no other module ever used it. Existing installations upgrade transparently.

**Follow-up:** None — issue #4 closed.

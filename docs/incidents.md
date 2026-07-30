# Incidents & operational notes

A lightweight log of things that went wrong — in production or in the release process itself — that cost real time to diagnose. Not a formal postmortem template: a few lines per entry is enough. The goal is to stop re-investigating the same gotcha from scratch next time.

Add an entry whenever something takes more than a few minutes to figure out and the answer isn't already obvious from the code or the README.

---

## 2026-07-28 — Publishing to GitHub required forensic reconstruction of the release workflow

**What happened:** Preparing to publish v1.4.1 to `github.com/losimo/freescout-meta-whatsapp`, the monorepo (`/Users/albert/Documents/odoo+freescout`) turned out to have no git remote configured at all — despite five prior releases (v1.0.1 through v1.4.0) having been published successfully. Figuring out how those releases actually got out required reflog archaeology: reading local commit history, comparing it against the public repo's commits, and noticing the public repo's commit messages (English, no `(MetaWhatsApp)` prefix) don't match the monorepo's local commits (Catalan, `tipus(MetaWhatsApp): missatge`) — different SHAs entirely.

**Root cause:** The monorepo intentionally has no remote, because it holds private Catalan development history for the whole Odoo+FreeScout stack, not just this module. Publishing has always gone through a throwaway flow: clone the public repo fresh into `/tmp`, `rsync` the module directory over (excluding `.git` and internal docs), write a fresh English commit, tag, and push — with no persistent link between the two repos to reconstruct the process from.

**Resolution:** Reconstructed and followed the 8-step flow (clone → rsync → verify `LICENSE` → English commit → tag → push via the `losimodev` SSH key → `gh release create` with `version.txt` and `metawhatsapp.zip` assets), then wrote it down so it doesn't need re-deriving again.

**Follow-up:** None needed — the workflow is now documented for future sessions. One standing gotcha worth remembering: `version.txt` must never be committed, it's a release asset generated fresh each time.

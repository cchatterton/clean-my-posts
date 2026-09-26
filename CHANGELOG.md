# Changelog

All notable changes to TN Clean My Posts are recorded here.

## 2.0.1 - 2026-09-26

- Align WordPress 7.0 / PHP 7.4 metadata, GPL/readme packaging and project-authored CSS units with current Codex standards.
- Remove PHP 8-only return declarations while preserving WP_Error handling and cleanup safeguards.
- Remove independent GitHub update checks and delegate updates to Techn Update Controller. Keep Beta readiness and existing feature settings, package identity and domain restrictions.
- Standardise update headers and controller-aware Install/Activate/Check links.

## 2.0.0 - 2026-08-25

- Replaced four action-oriented Tools pages with one preview-first cleanup screen.
- Added clear cleanup categories, record counts, and estimated content sizes.
- Added nonce protection, capability checks, strict selection validation, and confirmation prompts.
- Added dated backup batches, audit details, configurable retention, explicit restore, and permanent deletion.
- Added transactional cleanup and restore with a 250-record-per-category request limit.
- Switched post removal to WordPress deletion APIs so related metadata, comments, terms, hooks, and caches are handled.
- Added Techn naming, production structure, documentation, release packaging, and native GitHub updates.

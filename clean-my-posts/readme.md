# TN Clean My Posts

Author: Techn
Version: 2.0.0
Status: Production

## Purpose

Safely preview, back up, clean, and restore unwanted WordPress post-related records.

## Key Features

- Preview-first cleanup with counts and estimated content size.
- Dated backup batches with explicit restore and deletion controls.
- Revisions, auto-drafts, trash, orphaned metadata, and custom post type categories.
- Nonce protection, capability checks, validation, transactional rollback, and bounded processing.
- Native updates from public GitHub releases.

## Folder Structure

Business logic is separated into `functions/`, the admin view into `templates/`, and scoped assets into `scripts/` and `styles/`.

## Important Notes

Always create an external database backup before cleanup. In-database plugin backups are not a substitute. Restores require the original database IDs to remain available.

## Future Considerations

Very large sites may benefit from asynchronous background processing.

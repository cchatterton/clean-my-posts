# TN Clean My Posts

Author: Techn
Version: 2.0.1
Status: Production

## Purpose

TN Clean My Posts gives WordPress administrators a safer way to preview, back up, clean, and restore post-related database records.

## Key Features

- One WordPress-native cleanup screen under Tools.
- Counts and estimated content size before cleanup.
- Explicit preview and confirmation workflow.
- Nonce and capability protection for every write operation.
- Cleanup categories for revisions, auto-drafts, trash, orphaned post meta, and custom post types.
- Dated, user-attributed backup batches with configurable retention.
- Explicit restore and permanent-delete actions.
- Transactional cleanup and restore with rollback on failure.
- Maximum of 250 records per category per request to reduce timeout risk.
- Native GitHub release updates from the WordPress Plugins screen.

## Folder Structure

```text
clean-my-posts/
├── clean-my-posts.php
├── functions/
├── scripts/
├── styles/
└── templates/
```

## Important Notes

- Create a full external database backup before cleaning a production site.
- Plugin backup batches are stored in the same WordPress database and are not a replacement for an external backup.
- Restoring a batch requires its original WordPress database IDs to remain unused.
- Existing version 1.0 backup tables are left untouched. They are not automatically imported because they do not contain reliable batch metadata.

## Future Considerations

- Background processing may be appropriate for sites that routinely clean hundreds of thousands of records.

## Controller integration — 2.0.1

Remove independent GitHub update checks and delegate updates to Techn Update Controller. Keep Beta readiness and existing feature settings, package identity and domain restrictions. Previous standalone GitHub update instructions are superseded.

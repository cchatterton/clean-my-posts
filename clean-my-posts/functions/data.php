<?php

if (!defined('ABSPATH')) {
    exit;
}

function cmp_cleanup_targets(): array
{
    global $wpdb;

    $targets = array(
        'revisions' => cmp_build_post_target(
            __('Post revisions', 'clean-my-posts'),
            "post_type = 'revision'",
            array(),
            __('Older saved versions of posts and pages.', 'clean-my-posts')
        ),
        'auto_drafts' => cmp_build_post_target(
            __('Auto-drafts', 'clean-my-posts'),
            'post_status = %s',
            array('auto-draft'),
            __('Automatically created drafts that were never completed.', 'clean-my-posts')
        ),
        'trash' => cmp_build_post_target(
            __('Trashed content', 'clean-my-posts'),
            'post_status = %s',
            array('trash'),
            __('Posts already moved to the WordPress trash.', 'clean-my-posts')
        ),
    );

    $orphan_sql = "SELECT COUNT(*) AS item_count, COALESCE(SUM(CHAR_LENGTH(pm.meta_key) + CHAR_LENGTH(pm.meta_value)), 0) AS byte_count
        FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL";
    $orphan = $wpdb->get_row($orphan_sql, ARRAY_A);
    $targets['orphan_meta'] = array(
        'label'       => __('Orphaned post metadata', 'clean-my-posts'),
        'description' => __('Metadata whose post no longer exists.', 'clean-my-posts'),
        'kind'        => 'meta',
        'where'       => '',
        'values'      => array(),
        'count'       => (int) ($orphan['item_count'] ?? 0),
        'bytes'       => (int) ($orphan['byte_count'] ?? 0),
        'custom'      => false,
    );

    $custom_types = get_post_types(array('_builtin' => false), 'objects');
    foreach ($custom_types as $post_type => $object) {
        $statuses = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_status, COUNT(*) AS item_count FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash', 'auto-draft') GROUP BY post_status",
                $post_type
            ),
            ARRAY_A
        );

        foreach ($statuses as $status) {
            $status_key = sanitize_key((string) $status['post_status']);
            $key = 'custom_' . substr(sha1($post_type . '|' . $status_key), 0, 12);
            $target = cmp_build_post_target(
                sprintf(
                    /* translators: 1: post type label, 2: post status. */
                    __('Custom content: %1$s — %2$s', 'clean-my-posts'),
                    $object->labels->singular_name,
                    $status_key
                ),
                'post_type = %s AND post_status = %s',
                array($post_type, $status_key),
                __('Custom content is never selected automatically. Review it carefully before cleaning.', 'clean-my-posts')
            );
            $target['custom'] = true;
            $targets[$key] = $target;
        }
    }

    return $targets;
}

function cmp_build_post_target(string $label, string $where, array $values, string $description): array
{
    global $wpdb;

    $sql = "SELECT COUNT(*) AS item_count, COALESCE(SUM(CHAR_LENGTH(post_title) + CHAR_LENGTH(post_content) + CHAR_LENGTH(post_excerpt)), 0) AS byte_count FROM {$wpdb->posts} WHERE {$where}";
    if ($values) {
        $sql = $wpdb->prepare($sql, $values);
    }
    $row = $wpdb->get_row($sql, ARRAY_A);

    return array(
        'label'       => $label,
        'description' => $description,
        'kind'        => 'post',
        'where'       => $where,
        'values'      => $values,
        'count'       => (int) ($row['item_count'] ?? 0),
        'bytes'       => (int) ($row['byte_count'] ?? 0),
        'custom'      => false,
    );
}

function cmp_clean_targets(array $selected_keys): array|WP_Error
{
    global $wpdb;

    $targets = cmp_cleanup_targets();
    $selected = array_intersect_key($targets, array_flip($selected_keys));
    if (!$selected) {
        return new WP_Error('cmp_no_selection', __('Select at least one cleanup category.', 'clean-my-posts'));
    }

    $batch_table = $wpdb->prefix . 'cmp_cleanup_batches';
    $record_table = $wpdb->prefix . 'cmp_cleanup_records';
    $retention_days = max(1, min(365, (int) get_option('cmp_retention_days', 30)));
    $description = implode(', ', array_column($selected, 'label'));
    $processed = 0;
    $remaining = 0;

    $wpdb->query('START TRANSACTION');

    try {
        $inserted = $wpdb->insert(
            $batch_table,
            array(
                'created_at'   => current_time('mysql', true),
                'expires_at'   => gmdate('Y-m-d H:i:s', time() + ($retention_days * DAY_IN_SECONDS)),
                'user_id'      => get_current_user_id(),
                'description'  => $description,
                'record_count' => 0,
                'status'       => 'available',
            ),
            array('%s', '%s', '%d', '%s', '%d', '%s')
        );

        if (false === $inserted) {
            throw new RuntimeException('Could not create the cleanup backup batch.');
        }

        $batch_id = (int) $wpdb->insert_id;

        foreach ($selected as $target) {
            if ('post' === $target['kind']) {
                $result = cmp_clean_posts_for_target($target, $batch_id, $record_table);
            } else {
                $result = cmp_clean_orphan_meta($batch_id, $record_table);
            }

            if (is_wp_error($result)) {
                throw new RuntimeException($result->get_error_message());
            }

            $processed += $result['processed'];
            $remaining += $result['remaining'];
        }

        if (0 === $processed) {
            throw new RuntimeException('No matching records were available to clean.');
        }

        $wpdb->update($batch_table, array('record_count' => $processed), array('id' => $batch_id), array('%d'), array('%d'));
        $wpdb->query('COMMIT');

        return array('batch_id' => $batch_id, 'processed' => $processed, 'remaining' => $remaining);
    } catch (Throwable $error) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('cmp_cleanup_failed', __('Cleanup failed and all database changes were rolled back.', 'clean-my-posts'), $error->getMessage());
    }
}

function cmp_clean_posts_for_target(array $target, int $batch_id, string $record_table): array|WP_Error
{
    global $wpdb;

    $limit = 250;
    $sql = "SELECT ID FROM {$wpdb->posts} WHERE {$target['where']} ORDER BY ID ASC LIMIT %d";
    $values = array_merge($target['values'], array($limit + 1));
    $ids = array_map('intval', $wpdb->get_col($wpdb->prepare($sql, $values)));
    $remaining = count($ids) > $limit ? count($ids) - $limit : 0;
    $ids = array_slice($ids, 0, $limit);
    $processed = 0;

    foreach ($ids as $post_id) {
        $payload = cmp_capture_post($post_id);
        if (!$payload) {
            continue;
        }

        if (false === $wpdb->insert($record_table, array(
            'batch_id'    => $batch_id,
            'record_type' => 'post',
            'original_id' => $post_id,
            'payload'     => wp_json_encode($payload),
        ), array('%d', '%s', '%d', '%s'))) {
            return new WP_Error('cmp_backup_failed', __('A post could not be written to the backup table.', 'clean-my-posts'));
        }

        if (!wp_delete_post($post_id, true)) {
            return new WP_Error('cmp_delete_failed', __('A post could not be deleted after it was backed up.', 'clean-my-posts'));
        }
        ++$processed;
    }

    if ($remaining) {
        $count_sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$target['where']}";
        if ($target['values']) {
            $count_sql = $wpdb->prepare($count_sql, $target['values']);
        }
        $remaining = (int) $wpdb->get_var($count_sql);
    }

    return array('processed' => $processed, 'remaining' => $remaining);
}

function cmp_capture_post(int $post_id): ?array
{
    global $wpdb;

    $post = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d", $post_id), ARRAY_A);
    if (!$post) {
        return null;
    }

    $comments = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->comments} WHERE comment_post_ID = %d", $post_id), ARRAY_A);
    $comment_ids = array_map('intval', array_column($comments, 'comment_ID'));
    $commentmeta = array();
    if ($comment_ids) {
        $placeholders = implode(',', array_fill(0, count($comment_ids), '%d'));
        $commentmeta = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->commentmeta} WHERE comment_id IN ({$placeholders})", $comment_ids), ARRAY_A);
    }

    $revisions = array();
    if ('revision' !== $post['post_type']) {
        $revision_ids = array_map(
            'intval',
            $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'revision' ORDER BY ID ASC",
                    $post_id
                )
            )
        );
        foreach ($revision_ids as $revision_id) {
            $revision = cmp_capture_post($revision_id);
            if ($revision) {
                $revisions[] = $revision;
            }
        }
    }

    return array(
        'post'               => $post,
        'postmeta'           => $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->postmeta} WHERE post_id = %d", $post_id), ARRAY_A),
        'term_relationships' => $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->term_relationships} WHERE object_id = %d", $post_id), ARRAY_A),
        'comments'           => $comments,
        'commentmeta'        => $commentmeta,
        'revisions'          => $revisions,
    );
}

function cmp_clean_orphan_meta(int $batch_id, string $record_table): array|WP_Error
{
    global $wpdb;

    $rows = $wpdb->get_results(
        "SELECT pm.* FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL ORDER BY pm.meta_id ASC LIMIT 251",
        ARRAY_A
    );
    $remaining = count($rows) > 250 ? 1 : 0;
    $rows = array_slice($rows, 0, 250);

    foreach ($rows as $row) {
        if (false === $wpdb->insert($record_table, array(
            'batch_id'    => $batch_id,
            'record_type' => 'postmeta',
            'original_id' => (int) $row['meta_id'],
            'payload'     => wp_json_encode($row),
        ), array('%d', '%s', '%d', '%s'))) {
            return new WP_Error('cmp_backup_failed', __('A metadata record could not be backed up.', 'clean-my-posts'));
        }

        if (false === $wpdb->delete($wpdb->postmeta, array('meta_id' => (int) $row['meta_id']), array('%d'))) {
            return new WP_Error('cmp_delete_failed', __('A metadata record could not be deleted.', 'clean-my-posts'));
        }
    }

    if ($remaining) {
        $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL");
    }

    return array('processed' => count($rows), 'remaining' => $remaining);
}

function cmp_get_backup_batches(): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'cmp_cleanup_batches';
    return $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 100", ARRAY_A);
}

function cmp_restore_batch(int $batch_id): int|WP_Error
{
    global $wpdb;

    $batch_table = $wpdb->prefix . 'cmp_cleanup_batches';
    $record_table = $wpdb->prefix . 'cmp_cleanup_records';
    $batch = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$batch_table} WHERE id = %d AND status = 'available'", $batch_id), ARRAY_A);
    if (!$batch) {
        return new WP_Error('cmp_missing_batch', __('That backup batch is not available.', 'clean-my-posts'));
    }

    $records = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$record_table} WHERE batch_id = %d ORDER BY id ASC", $batch_id), ARRAY_A);
    $wpdb->query('START TRANSACTION');

    try {
        foreach ($records as $record) {
            $payload = json_decode((string) $record['payload'], true, 512, JSON_THROW_ON_ERROR);
            if ('post' === $record['record_type']) {
                cmp_restore_post_payload($payload);
            } elseif ('postmeta' === $record['record_type']) {
                cmp_restore_table_row($wpdb->postmeta, $payload, 'meta_id');
            }
        }

        $wpdb->update($batch_table, array('status' => 'restored'), array('id' => $batch_id), array('%s'), array('%d'));
        $wpdb->query('COMMIT');
        return count($records);
    } catch (Throwable $error) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('cmp_restore_failed', __('Restore failed because one or more original database IDs are now in use. No records were restored.', 'clean-my-posts'), $error->getMessage());
    }
}

function cmp_restore_post_payload(array $payload): void
{
    global $wpdb;

    cmp_restore_table_row($wpdb->posts, $payload['post'], 'ID');
    foreach ($payload['postmeta'] as $row) {
        cmp_restore_table_row($wpdb->postmeta, $row, 'meta_id');
    }
    foreach ($payload['comments'] as $row) {
        cmp_restore_table_row($wpdb->comments, $row, 'comment_ID');
    }
    foreach ($payload['commentmeta'] as $row) {
        cmp_restore_table_row($wpdb->commentmeta, $row, 'meta_id');
    }
    foreach ($payload['term_relationships'] as $row) {
        if (false === $wpdb->insert($wpdb->term_relationships, $row)) {
            throw new RuntimeException('A term relationship could not be restored.');
        }
        $taxonomy = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d",
                $row['term_taxonomy_id']
            )
        );
        if ('' !== $taxonomy && taxonomy_exists($taxonomy)) {
            wp_update_term_count_now(array((int) $row['term_taxonomy_id']), $taxonomy);
        }
    }

    $post_id = (int) $payload['post']['ID'];
    clean_post_cache($post_id);
    wp_update_comment_count_now($post_id);

    foreach ((array) ($payload['revisions'] ?? array()) as $revision) {
        cmp_restore_post_payload($revision);
    }
}

function cmp_restore_table_row(string $table, array $row, string $primary_key): void
{
    global $wpdb;

    $existing = $wpdb->get_var($wpdb->prepare("SELECT {$primary_key} FROM {$table} WHERE {$primary_key} = %d", (int) $row[$primary_key]));
    if ($existing || false === $wpdb->insert($table, $row)) {
        throw new RuntimeException('An original database ID is already in use.');
    }
}

function cmp_delete_backup_batch(int $batch_id): bool
{
    global $wpdb;
    $records = $wpdb->prefix . 'cmp_cleanup_records';
    $batches = $wpdb->prefix . 'cmp_cleanup_batches';
    $wpdb->delete($records, array('batch_id' => $batch_id), array('%d'));
    return false !== $wpdb->delete($batches, array('id' => $batch_id), array('%d'));
}

function cmp_purge_expired_backups(): void
{
    global $wpdb;

    if (get_transient('cmp_expired_backup_check')) {
        return;
    }
    set_transient('cmp_expired_backup_check', 1, HOUR_IN_SECONDS);

    $batches = $wpdb->prefix . 'cmp_cleanup_batches';
    $ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$batches} WHERE expires_at < %s", current_time('mysql', true))));
    foreach ($ids as $batch_id) {
        cmp_delete_backup_batch($batch_id);
    }
}

<?php

if (!defined('ABSPATH')) {
    exit;
}

function cmp_activate(): void
{
    cmp_create_backup_tables();
    update_option('cmp_database_version', CMP_VERSION, false);
    add_option('cmp_retention_days', 30, '', false);
}

function cmp_maybe_upgrade(): void
{
    if (CMP_VERSION === get_option('cmp_database_version')) {
        return;
    }

    cmp_create_backup_tables();
    update_option('cmp_database_version', CMP_VERSION, false);
}

function cmp_create_backup_tables(): void
{
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset = $wpdb->get_charset_collate();
    $batches = $wpdb->prefix . 'cmp_cleanup_batches';
    $records = $wpdb->prefix . 'cmp_cleanup_records';

    dbDelta("CREATE TABLE {$batches} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        created_at datetime NOT NULL,
        expires_at datetime NOT NULL,
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        description text NOT NULL,
        record_count bigint(20) unsigned NOT NULL DEFAULT 0,
        status varchar(20) NOT NULL DEFAULT 'available',
        PRIMARY KEY  (id),
        KEY expires_at (expires_at),
        KEY status (status)
    ) {$charset};");

    dbDelta("CREATE TABLE {$records} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        batch_id bigint(20) unsigned NOT NULL,
        record_type varchar(20) NOT NULL,
        original_id bigint(20) unsigned NOT NULL DEFAULT 0,
        payload longtext NOT NULL,
        PRIMARY KEY  (id),
        KEY batch_id (batch_id),
        KEY original_lookup (record_type, original_id)
    ) {$charset};");
}

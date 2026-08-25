<?php

if (!defined('ABSPATH')) {
    exit;
}

function cmp_register_admin_hooks(): void
{
    if (!is_admin()) {
        return;
    }

    add_action('admin_menu', 'cmp_add_admin_page');
    add_action('admin_enqueue_scripts', 'cmp_enqueue_admin_assets');
    add_action('admin_init', 'cmp_purge_expired_backups');
    add_action('admin_post_cmp_preview_cleanup', 'cmp_handle_preview_cleanup');
    add_action('admin_post_cmp_run_cleanup', 'cmp_handle_run_cleanup');
    add_action('admin_post_cmp_restore_batch', 'cmp_handle_restore_batch');
    add_action('admin_post_cmp_delete_batch', 'cmp_handle_delete_batch');
    add_action('admin_post_cmp_save_settings', 'cmp_handle_save_settings');
}

function cmp_add_admin_page(): void
{
    add_submenu_page(
        'tools.php',
        __('TN Clean My Posts', 'clean-my-posts'),
        __('Clean My Posts', 'clean-my-posts'),
        'manage_options',
        'clean-my-posts',
        'cmp_render_admin_page'
    );
}

function cmp_render_admin_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to manage database cleanup.', 'clean-my-posts'));
    }

    $targets = cmp_cleanup_targets();
    $preview_keys = array();
    if (isset($_GET['preview'])) {
        $preview_keys = (array) get_transient('cmp_preview_' . get_current_user_id());
        $preview_keys = array_values(array_intersect(array_keys($targets), $preview_keys));
    }
    $batches = cmp_get_backup_batches();
    $retention_days = max(1, min(365, (int) get_option('cmp_retention_days', 30)));

    require CMP_PLUGIN_DIR . 'templates/admin-page.php';
}

function cmp_handle_preview_cleanup(): void
{
    cmp_require_admin_action('cmp_preview_cleanup');
    $selected = cmp_sanitise_selected_targets();
    if (!$selected) {
        cmp_redirect_with_notice('select_items');
    }

    set_transient('cmp_preview_' . get_current_user_id(), $selected, 10 * MINUTE_IN_SECONDS);
    wp_safe_redirect(add_query_arg('preview', '1', cmp_admin_url()));
    exit;
}

function cmp_handle_run_cleanup(): void
{
    cmp_require_admin_action('cmp_run_cleanup');
    $selected = cmp_sanitise_selected_targets();
    $expected = (array) get_transient('cmp_preview_' . get_current_user_id());

    if (!$selected || array_diff($selected, $expected) || array_diff($expected, $selected)) {
        cmp_redirect_with_notice('preview_expired');
    }

    delete_transient('cmp_preview_' . get_current_user_id());
    $result = cmp_clean_targets($selected);
    if (is_wp_error($result)) {
        cmp_store_error($result);
        cmp_redirect_with_notice('cleanup_failed');
    }

    $url = add_query_arg(
        array(
            'cmp_notice' => 'cleaned',
            'count'      => (int) $result['processed'],
            'remaining'  => (int) $result['remaining'],
        ),
        cmp_admin_url()
    );
    wp_safe_redirect($url);
    exit;
}

function cmp_handle_restore_batch(): void
{
    cmp_require_admin_action('cmp_restore_batch');
    $batch_id = isset($_POST['batch_id']) ? absint($_POST['batch_id']) : 0;
    $result = cmp_restore_batch($batch_id);
    if (is_wp_error($result)) {
        cmp_store_error($result);
        cmp_redirect_with_notice('restore_failed');
    }
    wp_safe_redirect(add_query_arg(array('cmp_notice' => 'restored', 'count' => $result), cmp_admin_url()));
    exit;
}

function cmp_handle_delete_batch(): void
{
    cmp_require_admin_action('cmp_delete_batch');
    $batch_id = isset($_POST['batch_id']) ? absint($_POST['batch_id']) : 0;
    cmp_delete_backup_batch($batch_id);
    cmp_redirect_with_notice('backup_deleted');
}

function cmp_handle_save_settings(): void
{
    cmp_require_admin_action('cmp_save_settings');
    $days = isset($_POST['retention_days']) ? absint($_POST['retention_days']) : 30;
    update_option('cmp_retention_days', max(1, min(365, $days)), false);
    cmp_redirect_with_notice('settings_saved');
}

function cmp_require_admin_action(string $nonce_action): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to perform this action.', 'clean-my-posts'));
    }
    check_admin_referer($nonce_action);
}

function cmp_sanitise_selected_targets(): array
{
    $submitted = isset($_POST['targets']) ? (array) wp_unslash($_POST['targets']) : array();
    $submitted = array_values(array_unique(array_map('sanitize_key', $submitted)));
    return array_values(array_intersect($submitted, array_keys(cmp_cleanup_targets())));
}

function cmp_admin_url(): string
{
    return admin_url('tools.php?page=clean-my-posts');
}

function cmp_redirect_with_notice(string $notice): never
{
    wp_safe_redirect(add_query_arg('cmp_notice', sanitize_key($notice), cmp_admin_url()));
    exit;
}

function cmp_store_error(WP_Error $error): void
{
    set_transient(
        'cmp_error_' . get_current_user_id(),
        array('message' => $error->get_error_message(), 'detail' => (string) $error->get_error_data()),
        MINUTE_IN_SECONDS
    );
}

function cmp_admin_notice_message(): array
{
    $notice = isset($_GET['cmp_notice']) ? sanitize_key((string) wp_unslash($_GET['cmp_notice'])) : '';
    $count = isset($_GET['count']) ? absint($_GET['count']) : 0;
    $remaining = isset($_GET['remaining']) ? absint($_GET['remaining']) : 0;
    $messages = array(
        'select_items'    => array('warning', __('Select at least one cleanup category.', 'clean-my-posts')),
        'preview_expired' => array('warning', __('The cleanup preview expired or changed. Preview the cleanup again.', 'clean-my-posts')),
        'backup_deleted'  => array('success', __('The backup batch was permanently deleted.', 'clean-my-posts')),
        'settings_saved'  => array('success', __('Backup retention settings saved.', 'clean-my-posts')),
        'restored'        => array('success', sprintf(_n('%d record was restored.', '%d records were restored.', $count, 'clean-my-posts'), $count)),
    );

    if ('cleaned' === $notice) {
        $message = sprintf(_n('%d record was backed up and cleaned.', '%d records were backed up and cleaned.', $count, 'clean-my-posts'), $count);
        if ($remaining) {
            $message .= ' ' . sprintf(_n('%d matching record remains; preview and run another batch.', '%d matching records remain; preview and run another batch.', $remaining, 'clean-my-posts'), $remaining);
        }
        return array('success', $message);
    }

    if (in_array($notice, array('cleanup_failed', 'restore_failed'), true)) {
        $error = get_transient('cmp_error_' . get_current_user_id());
        delete_transient('cmp_error_' . get_current_user_id());
        return array('error', is_array($error) ? (string) $error['message'] : __('The operation failed safely. No partial database changes were kept.', 'clean-my-posts'));
    }

    return $messages[$notice] ?? array('', '');
}

<?php

if (!defined('ABSPATH')) {
    exit;
}

function cmp_register_github_updater(): void
{
    if (!is_admin()) {
        return;
    }

    add_filter('pre_set_site_transient_update_plugins', 'cmp_add_update_data');
    add_filter('site_transient_update_plugins', 'cmp_add_update_data');
    add_filter('plugins_api', 'cmp_plugin_details', 10, 3);
    add_filter('plugin_row_meta', 'cmp_plugin_row_meta', 10, 2);
    add_action('admin_init', 'cmp_handle_manual_update_check');
    add_action('admin_notices', 'cmp_update_check_notice');
    add_action('network_admin_notices', 'cmp_update_check_notice');
    add_action('upgrader_process_complete', 'cmp_clear_update_cache_after_upgrade', 10, 2);
}

function cmp_add_update_data($transient)
{
    if (!is_object($transient)) {
        $transient = new stdClass();
    }
    $transient->response = isset($transient->response) && is_array($transient->response) ? $transient->response : array();
    $transient->no_update = isset($transient->no_update) && is_array($transient->no_update) ? $transient->no_update : array();
    $release = cmp_latest_github_release();

    unset($transient->response[CMP_PLUGIN_BASENAME], $transient->no_update[CMP_PLUGIN_BASENAME]);
    if (!$release || !version_compare($release['version'], CMP_VERSION, '>')) {
        return $transient;
    }

    $transient->response[CMP_PLUGIN_BASENAME] = (object) array(
        'id'           => cmp_github_repo_url(),
        'slug'         => 'clean-my-posts',
        'plugin'       => CMP_PLUGIN_BASENAME,
        'new_version'  => $release['version'],
        'url'          => $release['release_url'],
        'package'      => $release['package'],
        'requires'     => '6.0',
        'requires_php' => '8.1',
    );
    return $transient;
}

function cmp_plugin_details($result, string $action, object $args)
{
    if ('plugin_information' !== $action || ($args->slug ?? '') !== 'clean-my-posts') {
        return $result;
    }
    $release = cmp_latest_github_release();
    if (!$release) {
        return $result;
    }
    return (object) array(
        'name'          => 'TN Clean My Posts',
        'slug'          => 'clean-my-posts',
        'version'       => $release['version'],
        'author'        => 'Techn',
        'homepage'      => cmp_github_repo_url(),
        'download_link' => $release['package'],
        'requires'      => '6.0',
        'requires_php'  => '8.1',
        'sections'      => array(
            'description' => __('Safely previews, backs up, cleans, and restores unwanted WordPress content.', 'clean-my-posts'),
            'changelog'   => wp_kses_post($release['body']),
        ),
    );
}

function cmp_plugin_row_meta(array $links, string $file): array
{
    if (CMP_PLUGIN_BASENAME !== $file) {
        return $links;
    }
    $links[] = '<a href="' . esc_url(cmp_github_repo_url()) . '">' . esc_html__('GitHub', 'clean-my-posts') . '</a>';
    if (current_user_can('update_plugins')) {
        $url = wp_nonce_url(add_query_arg('cmp_check_updates', '1', cmp_plugins_page_url()), 'cmp_check_updates');
        $links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Check for updates', 'clean-my-posts') . '</a>';
    }
    return $links;
}

function cmp_handle_manual_update_check(): void
{
    if (empty($_GET['cmp_check_updates'])) {
        return;
    }
    if (!current_user_can('update_plugins')) {
        wp_die(esc_html__('You do not have permission to check for plugin updates.', 'clean-my-posts'));
    }
    check_admin_referer('cmp_check_updates');
    cmp_clear_github_update_cache();
    delete_site_transient('update_plugins');
    if (!function_exists('wp_update_plugins')) {
        require_once ABSPATH . 'wp-includes/update.php';
    }
    $_GET['force-check'] = '1';
    wp_update_plugins();
    $transient = cmp_add_update_data(get_site_transient('update_plugins'));
    set_site_transient('update_plugins', $transient);

    $result = isset($transient->response[CMP_PLUGIN_BASENAME]) ? 'available' : 'current';
    if (get_site_transient('cmp_github_latest_release_error')) {
        $result = 'failed';
    }
    wp_safe_redirect(add_query_arg('cmp_update_check', $result, cmp_plugins_page_url()));
    exit;
}

function cmp_update_check_notice(): void
{
    $result = isset($_GET['cmp_update_check']) ? sanitize_key((string) wp_unslash($_GET['cmp_update_check'])) : '';
    $messages = array(
        'available' => array('success', __('A newer TN Clean My Posts release is available below.', 'clean-my-posts')),
        'current'   => array('info', __('TN Clean My Posts is current.', 'clean-my-posts')),
        'failed'    => array('error', __('TN Clean My Posts could not read the latest GitHub release. Try again later.', 'clean-my-posts')),
    );
    if (isset($messages[$result])) {
        echo '<div class="notice notice-' . esc_attr($messages[$result][0]) . ' is-dismissible"><p>' . esc_html($messages[$result][1]) . '</p></div>';
    }
}

function cmp_latest_github_release(): ?array
{
    if (cmp_is_forced_update_check()) {
        delete_site_transient('cmp_github_latest_release');
    }
    $cached = get_site_transient('cmp_github_latest_release');
    if (is_array($cached)) {
        return $cached;
    }

    $manifest = wp_remote_get(
        'https://raw.githubusercontent.com/cchatterton/clean-my-posts/main/update.json',
        array('timeout' => 10, 'headers' => array('User-Agent' => 'TN-Clean-My-Posts/' . CMP_VERSION))
    );
    if (!is_wp_error($manifest) && 200 === (int) wp_remote_retrieve_response_code($manifest)) {
        $data = json_decode(wp_remote_retrieve_body($manifest), true);
        $version = cmp_validate_release_version((string) ($data['version'] ?? ''));
        if ($version) {
            return cmp_cache_release(array(
                'version'     => $version,
                'body'        => sanitize_textarea_field((string) ($data['body'] ?? '')),
                'release_url' => cmp_github_repo_url() . '/releases/tag/v' . rawurlencode($version),
                'package'     => cmp_github_repo_url() . '/releases/download/v' . rawurlencode($version) . '/clean-my-posts.zip',
            ));
        }
    }

    $response = wp_remote_get(
        'https://api.github.com/repos/cchatterton/clean-my-posts/releases/latest',
        array(
            'timeout' => 10,
            'headers' => array('Accept' => 'application/vnd.github+json', 'User-Agent' => 'TN-Clean-My-Posts/' . CMP_VERSION),
        )
    );
    if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
        cmp_store_github_lookup_error($response);
        return null;
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $version = cmp_validate_release_version(ltrim((string) ($data['tag_name'] ?? ''), 'vV'));
    $package = '';
    foreach ((array) ($data['assets'] ?? array()) as $asset) {
        if ('clean-my-posts.zip' === ($asset['name'] ?? '') && !empty($asset['browser_download_url'])) {
            $package = esc_url_raw((string) $asset['browser_download_url']);
            break;
        }
    }
    if (!$version || !$package) {
        cmp_store_github_lookup_error(new WP_Error('invalid_release', 'The GitHub release metadata was incomplete.'));
        return null;
    }
    return cmp_cache_release(array(
        'version'     => $version,
        'body'        => (string) ($data['body'] ?? ''),
        'release_url' => esc_url_raw((string) ($data['html_url'] ?? cmp_github_repo_url())),
        'package'     => $package,
    ));
}

function cmp_cache_release(array $release): array
{
    $ttl = version_compare($release['version'], CMP_VERSION, '>') ? 6 * HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS;
    set_site_transient('cmp_github_latest_release', $release, $ttl);
    delete_site_transient('cmp_github_latest_release_error');
    return $release;
}

function cmp_validate_release_version(string $version): string
{
    return preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/', $version) ? $version : '';
}

function cmp_store_github_lookup_error($error): void
{
    $message = is_wp_error($error) ? $error->get_error_message() : wp_remote_retrieve_response_message($error);
    set_site_transient('cmp_github_latest_release_error', array('message' => $message, 'checked_at' => time()), 10 * MINUTE_IN_SECONDS);
    delete_site_transient('cmp_github_latest_release');
}

function cmp_clear_update_cache_after_upgrade($upgrader, array $hook_extra): void
{
    unset($upgrader);
    if ('plugin' === ($hook_extra['type'] ?? '') && in_array(CMP_PLUGIN_BASENAME, (array) ($hook_extra['plugins'] ?? array()), true)) {
        cmp_clear_github_update_cache();
    }
}

function cmp_clear_github_update_cache(): void
{
    delete_site_transient('cmp_github_latest_release');
    delete_site_transient('cmp_github_latest_release_error');
}

function cmp_is_forced_update_check(): bool
{
    if (!current_user_can('update_plugins')) {
        return false;
    }
    $action = isset($_REQUEST['action']) ? sanitize_key((string) wp_unslash($_REQUEST['action'])) : '';
    return isset($_REQUEST['force-check']) || isset($_GET['cmp_check_updates']) || in_array($action, array('update-selected', 'upgrade-plugin', 'do-plugin-upgrade'), true);
}

function cmp_github_repo_url(): string
{
    return 'https://github.com/cchatterton/clean-my-posts';
}

function cmp_plugins_page_url(): string
{
    return is_multisite() ? network_admin_url('plugins.php') : admin_url('plugins.php');
}

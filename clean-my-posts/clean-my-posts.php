<?php
/**
 * Plugin Name: TN Clean My Posts
 * Description: Safely previews, backs up, cleans, and restores unwanted WordPress content.
 * Version: 2.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Update URI: https://github.com/cchatterton/clean-my-posts
 * Author: Techn
 * Author URI: https://techn.com.au
 * Text Domain: clean-my-posts
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CMP_VERSION', '2.0.0');
define('CMP_PLUGIN_FILE', __FILE__);
define('CMP_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('CMP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CMP_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once CMP_PLUGIN_DIR . 'functions/setup.php';
require_once CMP_PLUGIN_DIR . 'functions/data.php';
require_once CMP_PLUGIN_DIR . 'functions/assets.php';
require_once CMP_PLUGIN_DIR . 'functions/admin.php';
require_once CMP_PLUGIN_DIR . 'functions/github-updater.php';

register_activation_hook(CMP_PLUGIN_FILE, 'cmp_activate');

add_action('plugins_loaded', 'cmp_load_plugin');

function cmp_load_plugin(): void
{
    cmp_maybe_upgrade();
    cmp_register_admin_hooks();
    cmp_register_github_updater();
}

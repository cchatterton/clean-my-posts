<?php
// Run with WP-CLI eval-file on a disposable WordPress site only.
if (DB_NAME !== 'tnuc_test') { throw new RuntimeException('Disposable test database required.'); }
wp_set_current_user(1);
$http = 0;
add_filter('pre_http_request', function () use (&$http) { $http++; return new WP_Error('blocked', 'No HTTP in metadata test.'); });
require_once WP_PLUGIN_DIR . '/clean-my-posts/clean-my-posts.php;
$_GET['force-check'] = '1';
$state = (object) ['response' => [], 'no_update' => []];
apply_filters('site_transient_update_plugins', $state);
apply_filters('pre_set_site_transient_update_plugins', $state);
$links = implode(' ', apply_filters('plugin_row_meta', [], 'clean-my-posts/clean-my-posts.php', [], ''));
if ($http !== 0 || substr_count($links, '>GitHub<') !== 1) { throw new RuntimeException('Metadata contract failed.'); }
echo "PASS: controller metadata renders without HTTP\n";

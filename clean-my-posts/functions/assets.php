<?php

if (!defined('ABSPATH')) {
    exit;
}

function cmp_enqueue_admin_assets(string $hook_suffix): void
{
    if ('tools_page_clean-my-posts' !== $hook_suffix) {
        return;
    }

    wp_enqueue_style('cmp-admin', CMP_PLUGIN_URL . 'styles/clean-my-posts.css', array(), CMP_VERSION);
    wp_enqueue_script('cmp-admin', CMP_PLUGIN_URL . 'scripts/clean-my-posts.js', array(), CMP_VERSION, true);
}

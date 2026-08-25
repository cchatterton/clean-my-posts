<?php
if (!defined('ABSPATH')) {
    exit;
}
$notice = cmp_admin_notice_message();
?>
<div class="wrap cmp-wrap">
    <h1><?php echo esc_html__('TN Clean My Posts', 'clean-my-posts'); ?></h1>
    <p class="cmp-note"><?php echo esc_html__('Preview and remove unwanted content while retaining dated, restorable backup batches. Make a full external database backup before cleaning a production site.', 'clean-my-posts'); ?></p>

    <?php if ($notice[0]) : ?>
        <div class="notice notice-<?php echo esc_attr($notice[0]); ?> is-dismissible"><p><?php echo esc_html($notice[1]); ?></p></div>
    <?php endif; ?>

    <?php if ($preview_keys) : ?>
        <?php
        $preview_count = 0;
        $preview_bytes = 0;
        foreach ($preview_keys as $key) {
            $preview_count += $targets[$key]['count'];
            $preview_bytes += $targets[$key]['bytes'];
        }
        ?>
        <h2><?php echo esc_html__('Confirm cleanup', 'clean-my-posts'); ?></h2>
        <div class="cmp-summary">
            <div class="cmp-card"><?php echo esc_html__('Records selected', 'clean-my-posts'); ?><strong><?php echo esc_html(number_format_i18n($preview_count)); ?></strong></div>
            <div class="cmp-card"><?php echo esc_html__('Estimated content size', 'clean-my-posts'); ?><strong><?php echo esc_html(size_format($preview_bytes)); ?></strong></div>
            <div class="cmp-card"><?php echo esc_html__('Backup retention', 'clean-my-posts'); ?><strong><?php echo esc_html(sprintf(_n('%d day', '%d days', $retention_days, 'clean-my-posts'), $retention_days)); ?></strong></div>
        </div>
        <ul>
            <?php foreach ($preview_keys as $key) : ?>
                <li><?php echo esc_html($targets[$key]['label'] . ': ' . number_format_i18n($targets[$key]['count'])); ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="cmp-danger"><?php echo esc_html__('The plugin will process no more than 250 records per category in this request. Every processed record is backed up before deletion.', 'clean-my-posts'); ?></p>
        <div class="cmp-actions">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-cmp-confirm="<?php echo esc_attr__('Create the backup batch and clean these records?', 'clean-my-posts'); ?>">
                <input type="hidden" name="action" value="cmp_run_cleanup">
                <?php foreach ($preview_keys as $key) : ?><input type="hidden" name="targets[]" value="<?php echo esc_attr($key); ?>"><?php endforeach; ?>
                <?php wp_nonce_field('cmp_run_cleanup'); ?>
                <?php submit_button(__('Back up and clean', 'clean-my-posts'), 'primary', 'submit', false); ?>
            </form>
            <a class="button" href="<?php echo esc_url(cmp_admin_url()); ?>"><?php echo esc_html__('Cancel', 'clean-my-posts'); ?></a>
        </div>
    <?php else : ?>
        <h2><?php echo esc_html__('Cleanup preview', 'clean-my-posts'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="cmp_preview_cleanup">
            <?php wp_nonce_field('cmp_preview_cleanup'); ?>
            <table class="widefat striped">
                <thead><tr><th class="check-column"><span class="screen-reader-text"><?php echo esc_html__('Select', 'clean-my-posts'); ?></span></th><th><?php echo esc_html__('Category', 'clean-my-posts'); ?></th><th><?php echo esc_html__('Records', 'clean-my-posts'); ?></th><th><?php echo esc_html__('Estimated size', 'clean-my-posts'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($targets as $key => $target) : ?>
                    <tr>
                        <th class="check-column"><input type="checkbox" name="targets[]" value="<?php echo esc_attr($key); ?>" aria-label="<?php echo esc_attr(sprintf(__('Select %s', 'clean-my-posts'), $target['label'])); ?>" <?php disabled(0, $target['count']); ?>></th>
                        <td><strong><?php echo esc_html($target['label']); ?></strong><br><span class="description"><?php echo esc_html($target['description']); ?></span></td>
                        <td><?php echo esc_html(number_format_i18n($target['count'])); ?></td>
                        <td><?php echo esc_html(size_format($target['bytes'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php submit_button(__('Preview cleanup', 'clean-my-posts')); ?>
        </form>
    <?php endif; ?>

    <hr>
    <h2><?php echo esc_html__('Backup history', 'clean-my-posts'); ?></h2>
    <?php if (!$batches) : ?>
        <p><?php echo esc_html__('No backup batches are available.', 'clean-my-posts'); ?></p>
    <?php else : ?>
        <table class="widefat striped">
            <thead><tr><th><?php echo esc_html__('Created', 'clean-my-posts'); ?></th><th><?php echo esc_html__('Cleanup', 'clean-my-posts'); ?></th><th><?php echo esc_html__('Records', 'clean-my-posts'); ?></th><th><?php echo esc_html__('User', 'clean-my-posts'); ?></th><th><?php echo esc_html__('Status', 'clean-my-posts'); ?></th><th><?php echo esc_html__('Actions', 'clean-my-posts'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($batches as $batch) : $user = get_userdata((int) $batch['user_id']); ?>
                <tr>
                    <td><?php echo esc_html(get_date_from_gmt($batch['created_at'], 'Y-m-d H:i')); ?></td>
                    <td><?php echo esc_html($batch['description']); ?></td>
                    <td><?php echo esc_html(number_format_i18n((int) $batch['record_count'])); ?></td>
                    <td><?php echo esc_html($user ? $user->display_name : __('Unknown', 'clean-my-posts')); ?></td>
                    <td><?php echo esc_html(ucfirst($batch['status'])); ?></td>
                    <td><div class="cmp-actions">
                        <?php if ('available' === $batch['status']) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-cmp-confirm="<?php echo esc_attr__('Restore every record in this backup batch?', 'clean-my-posts'); ?>">
                                <input type="hidden" name="action" value="cmp_restore_batch"><input type="hidden" name="batch_id" value="<?php echo esc_attr($batch['id']); ?>"><?php wp_nonce_field('cmp_restore_batch'); ?><button class="button" type="submit"><?php echo esc_html__('Restore', 'clean-my-posts'); ?></button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-cmp-confirm="<?php echo esc_attr__('Permanently delete this backup? It cannot be restored afterward.', 'clean-my-posts'); ?>">
                            <input type="hidden" name="action" value="cmp_delete_batch"><input type="hidden" name="batch_id" value="<?php echo esc_attr($batch['id']); ?>"><?php wp_nonce_field('cmp_delete_batch'); ?><button class="button-link-delete" type="submit"><?php echo esc_html__('Delete permanently', 'clean-my-posts'); ?></button>
                        </form>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2><?php echo esc_html__('Settings', 'clean-my-posts'); ?></h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="cmp_save_settings"><?php wp_nonce_field('cmp_save_settings'); ?>
        <label for="cmp-retention-days"><?php echo esc_html__('Keep backup batches for', 'clean-my-posts'); ?></label>
        <input id="cmp-retention-days" name="retention_days" type="number" min="1" max="365" value="<?php echo esc_attr($retention_days); ?>"> <?php echo esc_html__('days', 'clean-my-posts'); ?>
        <?php submit_button(__('Save settings', 'clean-my-posts'), 'secondary', 'submit', false); ?>
    </form>
</div>

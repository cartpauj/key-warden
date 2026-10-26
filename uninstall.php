<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

// Removes our own settings and data. Core's application passwords are left untouched.
function key_warden_uninstall_site() {
    global $wpdb;
    delete_option('key_warden_settings');
    delete_option('key_warden_profiles');
    delete_option('key_warden_log');
    wp_clear_scheduled_hook('key_warden_daily');
    // Rate-limit and notice transients.
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_key\\_warden\\_%' OR option_name LIKE '\\_transient\\_timeout\\_key\\_warden\\_%'"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

if (is_multisite()) {
    foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $key_warden_site_id) {
        switch_to_blog($key_warden_site_id);
        key_warden_uninstall_site();
        restore_current_blog();
    }
} else {
    key_warden_uninstall_site();
}
delete_metadata('user', 0, '_key_warden_passwords', '', true);

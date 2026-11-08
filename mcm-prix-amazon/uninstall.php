<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('mcm_prix_settings');
delete_option('mcm_prix_asins');
delete_option('mcm_prix_cache');
delete_option('mcm_prix_status');
delete_option('mcm_prix_meta');
delete_option('mcm_prix_api');
delete_transient('mcm_prix_lock');
delete_transient('mcm_prix_upd');
wp_clear_scheduled_hook('mcm_prix_refresh');
wp_clear_scheduled_hook('mcm_prix_digest');

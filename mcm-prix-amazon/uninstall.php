<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('mcm_prix_settings');
delete_option('mcm_prix_asins');
delete_option('mcm_prix_cache');
delete_option('mcm_prix_status');
delete_transient('mcm_prix_lock');
wp_clear_scheduled_hook('mcm_prix_refresh');

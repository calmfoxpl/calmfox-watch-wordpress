<?php
/**
 * Odinstalowanie Calmfox Watch: sprzątamy wszystko po sobie.
 * (Rozłączenie z hubem robi hook deaktywacji — tu tylko dane lokalne.)
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('calmfox_watch');
delete_option('calmfox_watch_history');
delete_option('calmfox_watch_versions');
if (is_multisite()) {
    delete_site_option('calmfox_watch_history');
    delete_site_option('calmfox_watch_versions');
}
delete_transient('calmfox_watch_pairing_nonce');
delete_transient('calmfox_watch_health_payload');
delete_transient('calmfox_watch_security_payload');
delete_transient('calmfox_watch_smtp_check');
delete_transient('calmfox_watch_es_check');

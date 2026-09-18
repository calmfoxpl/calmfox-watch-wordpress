<?php
/**
 * Plugin Name:       Calmfox Watch
 * Plugin URI:        https://watch.calmfox.net
 * Description:       Monitoring zdrowia WordPressa dla Calmfox Watch: sekretny endpoint stanu usług (baza, dysk, poczta, cron, cache), podstawowa higiena bezpieczeństwa i historia aktualizacji. Konto Free aktywujesz wprost z wtyczki.
 * Version:           1.9.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Calmfox
 * Author URI:        https://calmfox.pl
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       calmfox-watch
 */

defined('ABSPATH') || exit;

define('CALMFOX_WATCH_VERSION', '1.9.1');
define('CALMFOX_WATCH_FILE', __FILE__);
define('CALMFOX_WATCH_DIR', plugin_dir_path(__FILE__));

require_once CALMFOX_WATCH_DIR.'includes/class-settings.php';
require_once CALMFOX_WATCH_DIR.'includes/class-checks.php';
require_once CALMFOX_WATCH_DIR.'includes/class-security.php';
require_once CALMFOX_WATCH_DIR.'includes/class-history.php';
require_once CALMFOX_WATCH_DIR.'includes/class-endpoint.php';
require_once CALMFOX_WATCH_DIR.'includes/class-hub.php';
require_once CALMFOX_WATCH_DIR.'includes/class-score.php';
require_once CALMFOX_WATCH_DIR.'includes/class-score-ring.php';
require_once CALMFOX_WATCH_DIR.'includes/class-admin.php';
require_once CALMFOX_WATCH_DIR.'includes/class-repairs.php';
require_once CALMFOX_WATCH_DIR.'includes/class-updater.php';

/**
 * Adres API Calmfox Watch. Dev/staging nadpisuje stałą w wp-config.php:
 * define('CALMFOX_WATCH_API_URL', 'http://host.docker.internal:8099');
 */
function calmfox_watch_api_url(): string {
	$url = defined('CALMFOX_WATCH_API_URL') ? CALMFOX_WATCH_API_URL : 'https://watch.calmfox.net';

	return untrailingslashit((string) apply_filters('calmfox_watch_api_url', $url));
}

add_action('init', static function (): void {
	load_plugin_textdomain('calmfox-watch');
});

add_action('rest_api_init', array('Calmfox_Watch_Endpoint', 'register_routes'));

// Naprawy odwracalne działają jako ustawienia wtyczki, nie jako edycja plików
// strony — wyłączenie ich w panelu wtyczki przywraca stan sprzed naprawy.
if (Calmfox_Watch_Settings::get('disable_xmlrpc')) {
	add_filter('xmlrpc_enabled', '__return_false');
}
if (Calmfox_Watch_Settings::get('disallow_file_edit') && !defined('DISALLOW_FILE_EDIT')) {
	define('DISALLOW_FILE_EDIT', true);
}

Calmfox_Watch_History::boot();
Calmfox_Watch_Admin::boot();
Calmfox_Watch_Updater::boot();

register_activation_hook(__FILE__, static function (): void {
	Calmfox_Watch_Settings::ensure_secret();
	Calmfox_Watch_History::snapshot_init();
});

/**
 * Deaktywacja = świadome wyłączenie monitoringu wnętrza: mówimy hubowi wprost
 * (sonda pauzuje), zamiast zostawiać mu głuchy endpoint i budzić ludzi
 * incydentem „endpoint wtyczki zablokowany". Ustawienia zostają — ponowna
 * aktywacja łączy jednym kliknięciem.
 */
register_deactivation_hook(__FILE__, static function (): void {
	if (Calmfox_Watch_Settings::get('connected')) {
		Calmfox_Watch_Hub::disconnect();
		Calmfox_Watch_Settings::update(array('connected' => false));
	}
});

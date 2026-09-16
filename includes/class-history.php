<?php
defined('ABSPATH') || exit;

/**
 * Historia aktualizacji — WordPress sam jej nie prowadzi, więc logujemy każdą
 * aktualizację core/wtyczek/motywów (hook upgrader_process_complete) z wersją
 * PRZED i PO (wersje „przed" trzyma migawka odświeżana po każdym wpisie).
 * Historia zbierana od instalacji wtyczki — wstecz nie da się jej odtworzyć.
 * Tłumaczenia pomijamy świadomie: szum, który nic nie psuje.
 */
final class Calmfox_Watch_History {

	const OPTION   = 'calmfox_watch_history';
	const SNAPSHOT = 'calmfox_watch_versions';
	const CURSOR   = 'calmfox_watch_update_cursor';
	const MAX      = 200;

	public static function boot(): void {
		add_action('upgrader_process_complete', array(__CLASS__, 'on_upgrade'), 10, 2);
	}

	/**
	 * Znacznik dla huba: ile razy ta strona się aktualizowała i kiedy ostatnio.
	 *
	 * Własna opcja, a NIE długość tablicy historii — tamta jest przycinana do
	 * self::MAX, więc po dwustu wpisach licznik zacząłby się cofać i hub czytałby
	 * to jako przywrócenie strony z kopii. Osobna opcja jest też tania: szybki
	 * payload jedzie co 60 s i nie ma po co wczytywać przy tym dwustu wpisów.
	 *
	 * @return array{seq: int, at: ?string}
	 */
	public static function cursor(): array {
		$stored = self::read(self::CURSOR, array());
		if (!is_array($stored)) {
			return array('seq' => 0, 'at' => null);
		}

		return array(
			'seq' => isset($stored['seq']) ? (int) $stored['seq'] : 0,
			'at'  => isset($stored['at']) && is_string($stored['at']) ? $stored['at'] : null,
		);
	}

	/** @return array<int, array<string, mixed>> najnowsze pierwsze */
	public static function all(): array {
		$stored = is_multisite() ? get_site_option(self::OPTION, array()) : get_option(self::OPTION, array());

		return is_array($stored) ? $stored : array();
	}

	/**
	 * Multisite: wtyczki i motywy są wspólne dla sieci, a aktualizacje robi się
	 * w panelu sieci — historia musi więc leżeć w opcji sieciowej, inaczej
	 * podstrona zapisywałaby tylko to, co przypadkiem poszło w jej kontekście.
	 *
	 * @param mixed $value
	 */
	private static function put(string $key, $value): void {
		if (is_multisite()) {
			update_site_option($key, $value);

			return;
		}
		update_option($key, $value, false);
	}

	/** @return mixed */
	private static function read(string $key, $default) {
		return is_multisite() ? get_site_option($key, $default) : get_option($key, $default);
	}

	/**
	 * Uzgodnienie migawki z faktycznymi wersjami — leczy dryf po zmianach poza
	 * updaterem (FTP, instalacja starszej wersji). Wołane przy instalacjach
	 * i przy budowie sekcji security (max co 10 minut przez jej cache).
	 */
	public static function reconcile_snapshot(): void {
		self::snapshot_init();
	}

	/** Migawka wersji na start — dzięki niej pierwszy update ma skąd wziąć „z wersji". */
	public static function snapshot_init(): void {
		if (!function_exists('get_plugins')) {
			require_once ABSPATH.'wp-admin/includes/plugin.php';
		}
		$snapshot = array('core' => (string) get_bloginfo('version'));
		foreach (get_plugins() as $file => $data) {
			$snapshot['plugin:'.$file] = (string) ($data['Version'] ?? '');
		}
		foreach (wp_get_themes() as $slug => $theme) {
			$snapshot['theme:'.$slug] = (string) $theme->get('Version');
		}
		self::put(self::SNAPSHOT, $snapshot);
	}

	/**
	 * @param WP_Upgrader $upgrader
	 * @param array<string, mixed> $extra
	 */
	public static function on_upgrade($upgrader, $extra): void {
		if (!is_array($extra)) {
			return;
		}
		$type = (string) ($extra['type'] ?? '');
		if (!in_array($type, array('core', 'plugin', 'theme'), true)) {
			return; // tłumaczenia i inne — pomijamy
		}
		// Instalacja (także nadpisująca, np. wgranie starszej wersji) nie jest
		// wpisem historii, ale MUSI odświeżyć migawkę — inaczej następny update
		// pokaże „z wersji", której już dawno nie było.
		if ('install' === ($extra['action'] ?? '')) {
			self::reconcile_snapshot();

			return;
		}
		if ('update' !== ($extra['action'] ?? '')) {
			return;
		}

		$snapshot = self::read(self::SNAPSHOT, array());
		$snapshot = is_array($snapshot) ? $snapshot : array();
		$entries  = array();

		if ('core' === $type) {
			$to = self::core_version_from_disk();
			$entries[] = self::entry('core', 'WordPress', $snapshot['core'] ?? null, $to);
			$snapshot['core'] = $to;
		} elseif ('plugin' === $type) {
			if (!function_exists('get_plugin_data')) {
				require_once ABSPATH.'wp-admin/includes/plugin.php';
			}
			foreach (self::items($extra, 'plugins', 'plugin') as $file) {
				$path = WP_PLUGIN_DIR.'/'.$file;
				$data = file_exists($path) ? get_plugin_data($path, false, false) : array();
				$to   = (string) ($data['Version'] ?? '');
				$name = (string) ($data['Name'] ?? $file);
				$entries[] = self::entry('plugin', $name, $snapshot['plugin:'.$file] ?? null, $to);
				$snapshot['plugin:'.$file] = $to;
			}
		} else {
			foreach (self::items($extra, 'themes', 'theme') as $slug) {
				$theme = wp_get_theme($slug);
				$to    = (string) $theme->get('Version');
				$entries[] = self::entry('theme', (string) $theme->get('Name'), $snapshot['theme:'.$slug] ?? null, $to);
				$snapshot['theme:'.$slug] = $to;
			}
		}

		if (empty($entries)) {
			return;
		}
		self::put(self::SNAPSHOT, $snapshot);
		self::put(self::OPTION, array_slice(array_merge($entries, self::all()), 0, self::MAX));

		// Kursor rośnie o LICZBĘ WPISÓW, nie o jeden: partia auto-aktualizacji woła
		// ten hook raz na rodzaj (core/wtyczki/motywy), więc skok o jeden gubiłby
		// informację, ile rzeczy naprawdę się ruszyło.
		$cursor = self::cursor();
		self::put(self::CURSOR, array(
			'seq' => $cursor['seq'] + count($entries),
			'at'  => gmdate('c'),
		));
	}

	/** @return array<int, string> */
	private static function items(array $extra, string $bulk_key, string $single_key): array {
		if (!empty($extra[$bulk_key]) && is_array($extra[$bulk_key])) {
			return array_map('strval', $extra[$bulk_key]);
		}
		if (!empty($extra[$single_key])) {
			return array((string) $extra[$single_key]);
		}

		return array();
	}

	/** @return array<string, mixed> */
	private static function entry(string $kind, string $name, $from, string $to): array {
		$by = '';
		if (defined('WP_CLI') && WP_CLI) {
			$by = 'wp-cli';
		} elseif (is_user_logged_in()) {
			$user = wp_get_current_user();
			$by   = (string) $user->user_login;
		}

		return array(
			'kind' => $kind,
			'name' => mb_substr($name, 0, 120),
			'from' => is_string($from) && '' !== $from ? mb_substr($from, 0, 32) : null,
			'to'   => '' !== $to ? mb_substr($to, 0, 32) : null,
			'at'   => gmdate('c'),
			'mode' => wp_doing_cron() ? 'auto' : 'manual',
			'by'   => '' !== $by ? mb_substr($by, 0, 80) : null,
		);
	}

	/** Po aktualizacji core $wp_version w pamięci procesu bywa stary — czytamy świeży plik. */
	private static function core_version_from_disk(): string {
		$contents = (string) @file_get_contents(ABSPATH.WPINC.'/version.php');
		if (preg_match("/\\\$wp_version\s*=\s*'([^']+)'/", $contents, $m)) {
			return $m[1];
		}

		return (string) get_bloginfo('version');
	}
}

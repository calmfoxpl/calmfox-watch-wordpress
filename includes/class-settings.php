<?php
defined('ABSPATH') || exit;

/**
 * Ustawienia wtyczki w jednej opcji + sekret endpointu health.
 * Sekret jest w URL-u (model pull huba); rotacja trzyma poprzedni sekret
 * przez 15 minut, żeby nieudane przepięcie w hubie nie zrywało monitoringu.
 */
final class Calmfox_Watch_Settings {

	const OPTION          = 'calmfox_watch';
	const NONCE_TRANSIENT = 'calmfox_watch_pairing_nonce';
	const PREV_WINDOW     = 900; // 15 min ważności starego sekretu po rotacji

	/** @return array<string, mixed> */
	public static function all(): array {
		$defaults = array(
			'secret'            => '',
			'prev_secret'       => '',
			'prev_secret_until' => 0,
			'connected'         => false,
			'install_token'     => '',
			'site_id'           => '',
			'plan'              => '',
			'panel_url'         => 'https://watch.calmfox.net',
			'paired_at'         => '',
			'last_poll_at'      => 0,
			// Limit dyskowy konta w GB podany przez klienta — hosting współdzielony
			// go nie ujawnia, a bez niego nie da się uczciwie liczyć zajętości.
			'disk_quota_gb'     => 0,
			// Naprawy wykonane z panelu wtyczki (odwracalne, trzymane jako flagi).
			'disable_xmlrpc'    => false,
			'disallow_file_edit' => false,
		);
		$stored = get_option(self::OPTION, array());
		$all    = array_merge($defaults, is_array($stored) ? $stored : array());

		// Przeprowadzka panelu na watch.calmfox.net. Adres huba siedzi w opcji zapisanej
		// przy parowaniu, więc sama zmiana wartości domyślnej nie rusza istniejących
		// instalacji — a reinstalacja wtyczki opcji nie kasuje. Stary host oddaje 308,
		// czyli wszystko DZIAŁA, tylko każde żądanie idzie przez skok, a klient widzi
		// w ustawieniach adres, którego już nie używamy. Podmieniamy wyłącznie NASZ
		// stary host; adres wpisany ręcznie (środowisko testowe klienta) zostaje.
		if ( 'https://watch.calmfox.pl' === untrailingslashit( (string) $all['panel_url'] ) ) {
			$all['panel_url'] = 'https://watch.calmfox.net';
		}

		return $all;
	}

	/** @return mixed */
	public static function get(string $key) {
		$all = self::all();

		return isset($all[$key]) ? $all[$key] : null;
	}

	/** @param array<string, mixed> $changes */
	public static function update(array $changes): void {
		update_option(self::OPTION, array_merge(self::all(), $changes), false);
	}

	/**
	 * Adres mapy strony tej instalacji, zgłaszany hubowi przy parowaniu. Pod jedną domeną bywa
	 * kilka systemów (Neos i WordPress), a robots.txt nie zawsze wymienia mapę bloga —
	 * WordPress zna swoją sam: rdzeń od 5.5 pod wp-sitemap.xml, Yoast pod sitemap_index.xml.
	 * Pusty tekst = nie wiemy (rdzeń wyłączył mapy i nie ma Yoasta).
	 */
	public static function sitemap_url(): string {
		if (defined('WPSEO_VERSION')) {
			return home_url('/sitemap_index.xml');
		}
		if (function_exists('get_sitemap_url')) {
			$url = get_sitemap_url('index');

			return is_string($url) ? $url : '';
		}

		return '';
	}

	/** Sekret URL-a: 32 znaki hex (128 bitów). */
	public static function ensure_secret(): string {
		$secret = (string) self::get('secret');
		if ('' === $secret) {
			$secret = self::random_secret();
			self::update(array('secret' => $secret));
		}

		return $secret;
	}

	/** Rotacja: nowy sekret od razu aktywny, stary honorowany jeszcze 15 minut. */
	public static function rotate_secret(): string {
		$fresh = self::random_secret();
		self::update(array(
			'secret'            => $fresh,
			'prev_secret'       => (string) self::get('secret'),
			'prev_secret_until' => time() + self::PREV_WINDOW,
		));

		return $fresh;
	}

	/** Klucz z żądania kontra sekret bieżący albo poprzedni w oknie rotacji. */
	public static function key_is_valid(string $key): bool {
		if ('' === $key) {
			return false;
		}
		$secret = (string) self::get('secret');
		if ('' !== $secret && hash_equals($secret, $key)) {
			return true;
		}
		$prev  = (string) self::get('prev_secret');
		$until = (int) self::get('prev_secret_until');

		return '' !== $prev && time() <= $until && hash_equals($prev, $key);
	}

	/** Adres panelu Watch (connect flow, przycisk „Otwórz panel"). */
	public static function panel_url(): string {
		$stored = (string) self::get('panel_url');

		return untrailingslashit((string) apply_filters('calmfox_watch_panel_url', '' !== $stored ? $stored : 'https://watch.calmfox.net'));
	}

	/**
	 * Link do panelu w kontekście TEJ strony — panel przełącza się na nią sam
	 * (?site=…), więc klient nie szuka jej w przełączniku stron.
	 */
	public static function panel_link(string $path = '/app/dashboard', string $fragment = ''): string {
		$url  = self::panel_url().$path;
		$site = (string) self::get('site_id');
		if ('' !== $site) {
			$url = add_query_arg('site', $site, $url);
		}

		return $url.('' !== $fragment ? '#'.$fragment : '');
	}

	/** Pełny sekretny URL endpointu health — to on jest rejestrowany w hubie. */
	public static function health_url(): string {
		return add_query_arg('key', self::ensure_secret(), rest_url('calmfox/v1/health'));
	}

	/** Nonce parowania: hub oczekuje jego echa w polu `pairing` podczas challenge'u. */
	public static function make_pairing_nonce(): string {
		$nonce = wp_generate_password(32, false, false);
		set_transient(self::NONCE_TRANSIENT, $nonce, 15 * MINUTE_IN_SECONDS);

		return $nonce;
	}

	public static function pairing_nonce(): string {
		return (string) get_transient(self::NONCE_TRANSIENT);
	}

	public static function clear_pairing_nonce(): void {
		delete_transient(self::NONCE_TRANSIENT);
	}

	/** Znacznik ostatniego autoryzowanego odpytania (throttling zapisu: raz na minutę). */
	public static function touch_last_poll(): void {
		$last = (int) self::get('last_poll_at');
		if (time() - $last >= MINUTE_IN_SECONDS) {
			self::update(array('last_poll_at' => time()));
		}
	}

	private static function random_secret(): string {
		try {
			return bin2hex(random_bytes(16));
		} catch (Exception $e) {
			return substr(str_shuffle(str_repeat('abcdef0123456789', 8)), 0, 32); // awaryjnie, bez CSPRNG
		}
	}
}

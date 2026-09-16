<?php
defined('ABSPATH') || exit;

/**
 * Samoaktualizacja wtyczki spoza katalogu wordpress.org: manifest JSON leży
 * obok zipa na watch.calmfox.net (generowany skryptem build:plugin-zip przy
 * wydaniu). Wpinamy się w standardowy mechanizm WordPressa (transient
 * update_plugins), więc aktualizacja wygląda i działa jak każda inna —
 * łącznie z auto-aktualizacjami, jeśli użytkownik je włączy.
 */
final class Calmfox_Watch_Updater {

	const CACHE     = 'calmfox_watch_manifest';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;
	/** Nieudany fetch też jest cache'owany — nie młócimy serwera przy każdym wejściu do admina. */
	const FAIL_TTL  = HOUR_IN_SECONDS;

	public static function boot(): void {
		add_filter('pre_set_site_transient_update_plugins', array(__CLASS__, 'inject_update'));
		add_filter('plugins_api', array(__CLASS__, 'plugin_info'), 10, 3);
	}

	public static function manifest_url(): string {
		return (string) apply_filters('calmfox_watch_manifest_url', calmfox_watch_api_url().'/calmfox-watch.json');
	}

	/** @param object $transient */
	public static function inject_update($transient) {
		if (!is_object($transient)) {
			return $transient;
		}
		$manifest = self::manifest();
		if (null === $manifest || !version_compare((string) $manifest['version'], CALMFOX_WATCH_VERSION, '>')) {
			return $transient;
		}

		$plugin = plugin_basename(CALMFOX_WATCH_FILE);
		if (!isset($transient->response) || !is_array($transient->response)) {
			$transient->response = array();
		}
		$transient->response[$plugin] = (object) array(
			'slug'         => 'calmfox-watch',
			'plugin'       => $plugin,
			'new_version'  => (string) $manifest['version'],
			'url'          => (string) ($manifest['homepage'] ?? 'https://watch.calmfox.net'),
			'package'      => (string) $manifest['download_url'],
			'requires'     => (string) ($manifest['requires'] ?? '6.0'),
			'requires_php' => (string) ($manifest['requires_php'] ?? '7.4'),
			'tested'       => (string) ($manifest['tested'] ?? ''),
			'icons'        => self::icons($manifest),
		);

		return $transient;
	}

	/**
	 * Okno „Więcej informacji" przy aktualizacji.
	 *
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 */
	public static function plugin_info($result, $action, $args) {
		if ('plugin_information' !== $action || 'calmfox-watch' !== ($args->slug ?? '')) {
			return $result;
		}
		$manifest = self::manifest();
		if (null === $manifest) {
			return $result;
		}

		return (object) array(
			'name'          => 'Calmfox Watch',
			'slug'          => 'calmfox-watch',
			'version'       => (string) $manifest['version'],
			'author'        => '<a href="https://calmfox.pl">Calmfox</a>',
			'homepage'      => (string) ($manifest['homepage'] ?? 'https://watch.calmfox.net'),
			'requires'      => (string) ($manifest['requires'] ?? '6.0'),
			'requires_php'  => (string) ($manifest['requires_php'] ?? '7.4'),
			'tested'        => (string) ($manifest['tested'] ?? ''),
			'last_updated'  => (string) ($manifest['last_updated'] ?? ''),
			'download_link' => (string) $manifest['download_url'],
			'icons'         => self::icons($manifest),
			'sections'      => array(
				'description' => (string) ($manifest['description'] ?? 'Monitoring wnętrza WordPressa dla Calmfox Watch.'),
				'changelog'   => (string) ($manifest['changelog'] ?? 'Lista zmian: https://watch.calmfox.net'),
			),
		);
	}

	/**
	 * Ręczne wymuszenie sprawdzenia aktualizacji: przycisk „Sprawdź ponownie"
	 * (update-core.php?force-check=1) albo `wp plugin update` z wp-cli.
	 */
	private static function force_check(): bool {
		if (!empty($_GET['force-check'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tylko odczyt, bez skutków ubocznych
			return true;
		}

		return defined('WP_CLI') && WP_CLI;
	}

	/**
	 * Ikony wtyczki dla kart aktualizacji: z manifestu (wtedy jadą z panelu),
	 * a w razie ich braku — znak marki spakowany razem z wtyczką.
	 *
	 * @param array<string, mixed> $manifest
	 *
	 * @return array<string, string>
	 */
	private static function icons(array $manifest): array {
		if (!empty($manifest['icons']) && is_array($manifest['icons'])) {
			return array_map('strval', $manifest['icons']);
		}

		return array(
			'1x'      => plugins_url('assets/icon-128.png', CALMFOX_WATCH_FILE),
			'2x'      => plugins_url('assets/icon-256.png', CALMFOX_WATCH_FILE),
			'default' => plugins_url('assets/icon-256.png', CALMFOX_WATCH_FILE),
		);
	}

	/** @return array<string, mixed>|null null = manifest niedostępny albo bez kompletu pól */
	private static function manifest(): ?array {
		// „Sprawdź ponownie" w kokpicie ma znaczyć naprawdę teraz — inaczej świeże
		// wydanie czekałoby na wygaśnięcie naszego cache'u mimo ręcznego kliknięcia.
		$cached = self::force_check() ? false : get_transient(self::CACHE);
		if (is_array($cached)) {
			return array() === $cached ? null : $cached; // pusta tablica = zapamiętany nieudany fetch
		}

		$response = wp_remote_get(self::manifest_url(), array(
			'timeout' => 5,
			'headers' => array('Accept' => 'application/json'),
		));
		$manifest = null;
		if (!is_wp_error($response) && 200 === (int) wp_remote_retrieve_response_code($response)) {
			$data = json_decode((string) wp_remote_retrieve_body($response), true);
			// Paczka wyłącznie po https — nikt nie wstrzyknie zipa po drodze.
			// Dev/lab może świadomie poluzować filtrem calmfox_watch_allow_insecure_manifest.
			$secure = 0 === strpos((string) ($data['download_url'] ?? ''), 'https://')
				|| apply_filters('calmfox_watch_allow_insecure_manifest', false);
			if (is_array($data) && !empty($data['version']) && !empty($data['download_url']) && $secure) {
				$manifest = $data;
			}
		}

		set_transient(self::CACHE, $manifest ?? array(), null === $manifest ? self::FAIL_TTL : self::CACHE_TTL);

		return $manifest;
	}
}

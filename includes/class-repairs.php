<?php
defined('ABSPATH') || exit;

/**
 * Naprawy jednym kliknięciem. Świadomie wąski zakres: tylko rzeczy, które
 * potrafimy naprawić pewnie i ODWRACALNIE, bez ruszania cudzego kodu i bez
 * masowego chmod-a po całym drzewie. Wszystko, czego nie umiemy zrobić
 * bezpiecznie (sole, konto „admin", kasowanie wtyczek), zostaje poradą.
 */
final class Calmfox_Watch_Repairs {

	/**
	 * Katalog napraw: id checku → co potrafimy z nim zrobić.
	 *
	 * @return array<string, array{label: string, note: string}>
	 */
	public static function catalog(): array {
		return array(
			'config_perms' => array(
				'label' => __('Napraw uprawnienia', 'calmfox-watch'),
				'note'  => __('Ustawimy plikowi wp-config.php prawa 640 — właściciel zapisuje, reszta serwera nie czyta.', 'calmfox-watch'),
			),
			'dir_perms' => array(
				'label' => __('Napraw uprawnienia', 'calmfox-watch'),
				'note'  => __('Katalogom zapisywalnym dla wszystkich (777) ustawimy 755 — WordPress dalej zapisuje, obcy procesy nie.', 'calmfox-watch'),
			),
			'xmlrpc' => array(
				'label' => __('Wyłącz XML-RPC', 'calmfox-watch'),
				'note'  => __('Wyłączymy XML-RPC filtrem wtyczki (bez edycji plików). Jeśli korzystasz z aplikacji mobilnej WordPressa albo Jetpacka, zostaw włączone.', 'calmfox-watch'),
			),
			'file_editor' => array(
				'label' => __('Wyłącz edytor plików', 'calmfox-watch'),
				'note'  => __('Zablokujemy edytor wtyczek i motywów w kokpicie (DISALLOW_FILE_EDIT). Przejęte konto admina nie wgra już własnego kodu PHP.', 'calmfox-watch'),
			),
		);
	}

	public static function can_fix(string $check_id): bool {
		return isset(self::catalog()[$check_id]);
	}

	/**
	 * Wykonuje naprawę i zwraca komunikat dla człowieka.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public static function run(string $check_id): array {
		switch ($check_id) {
			case 'config_perms':
				return self::fix_config_perms();
			case 'dir_perms':
				return self::fix_dir_perms();
			case 'xmlrpc':
				Calmfox_Watch_Settings::update(array('disable_xmlrpc' => true));

				return array('ok' => true, 'message' => __('XML-RPC wyłączone. Możesz to cofnąć w każdej chwili — ustawienie trzyma wtyczka, nie pliki strony.', 'calmfox-watch'));
			case 'file_editor':
				Calmfox_Watch_Settings::update(array('disallow_file_edit' => true));

				return array('ok' => true, 'message' => __('Edytor plików w kokpicie wyłączony.', 'calmfox-watch'));
			default:
				return array('ok' => false, 'message' => __('Tej pozycji nie naprawiamy automatycznie.', 'calmfox-watch'));
		}
	}

	/** Cofnięcie napraw odwracalnych (te plikowe zostawiamy — nie psujemy z powrotem uprawnień). */
	public static function revert(string $check_id): array {
		if ('xmlrpc' === $check_id) {
			Calmfox_Watch_Settings::update(array('disable_xmlrpc' => false));

			return array('ok' => true, 'message' => __('XML-RPC znów włączone.', 'calmfox-watch'));
		}
		if ('file_editor' === $check_id) {
			Calmfox_Watch_Settings::update(array('disallow_file_edit' => false));

			return array('ok' => true, 'message' => __('Edytor plików znów dostępny.', 'calmfox-watch'));
		}

		return array('ok' => false, 'message' => __('Tej naprawy nie da się cofnąć z panelu wtyczki.', 'calmfox-watch'));
	}

	private static function fix_config_perms(): array {
		$path = self::config_path();
		if ('' === $path) {
			return array('ok' => false, 'message' => __('Nie znaleźliśmy pliku wp-config.php.', 'calmfox-watch'));
		}
		$before = fileperms($path) & 0777;
		if (!@chmod($path, 0640)) {
			return array('ok' => false, 'message' => sprintf(
				/* translators: %s: prawa */
				__('Nie udało się zmienić uprawnień (obecnie %s) — na tym hostingu zrobi to tylko administrator serwera.', 'calmfox-watch'), decoct($before)));
		}
		clearstatcache(true, $path);
		$after = fileperms($path) & 0777;

		return array('ok' => true, 'message' => sprintf(
			/* translators: 1: przed, 2: po */
			__('Uprawnienia wp-config.php zmienione z %1$s na %2$s.', 'calmfox-watch'), decoct($before), decoct($after)));
	}

	/** Tylko katalogi, które sami wskazujemy w checku — żadnego rekurencyjnego chmod-a. */
	private static function fix_dir_perms(): array {
		$upload = wp_upload_dir(null, false);
		$dirs   = array(WP_CONTENT_DIR);
		if (!empty($upload['basedir'])) {
			$dirs[] = $upload['basedir'];
		}

		$fixed  = array();
		$failed = array();
		foreach ($dirs as $dir) {
			if (!is_dir($dir) || !((fileperms($dir) & 0777) & 0002)) {
				continue; // nie jest zapisywalny dla świata — nie ruszamy
			}
			if (@chmod($dir, 0755)) {
				$fixed[] = basename($dir);
			} else {
				$failed[] = basename($dir);
			}
		}

		if (array() === $fixed && array() === $failed) {
			return array('ok' => true, 'message' => __('Nie ma czego naprawiać — katalogi mają już bezpieczne uprawnienia.', 'calmfox-watch'));
		}
		if (array() !== $failed) {
			return array('ok' => false, 'message' => sprintf(
				/* translators: %s: lista katalogów */
				__('Nie udało się zmienić uprawnień: %s. Na tym hostingu zrobi to tylko administrator serwera.', 'calmfox-watch'), implode(', ', $failed)));
		}

		return array('ok' => true, 'message' => sprintf(
			/* translators: %s: lista katalogów */
			__('Ustawiliśmy prawa 755 na: %s.', 'calmfox-watch'), implode(', ', $fixed)));
	}

	public static function config_path(): string {
		if (file_exists(ABSPATH.'wp-config.php')) {
			return ABSPATH.'wp-config.php';
		}
		$parent = dirname(ABSPATH).'/wp-config.php';

		return file_exists($parent) ? $parent : '';
	}
}

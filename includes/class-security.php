<?php
defined('ABSPATH') || exit;

/**
 * Podstawowa higiena bezpieczeństwa (sekcja security) — świadomie NIE audyt
 * i NIE skaner malware: proste, tanie odczyty konfiguracji i uprawnień.
 * Wyniki nie budzą nikogo w nocy — lądują w panelu i raporcie.
 */
final class Calmfox_Watch_Security {

	/** @return array{status: string, checks: array<int, array<string, mixed>>} */
	public static function run(): array {
		if (!function_exists('get_plugins')) {
			require_once ABSPATH.'wp-admin/includes/plugin.php';
		}

		$checks = array(
			self::check_admins(),
			self::check_admin_login(),
			self::check_registration(),
			self::check_file_edit(),
			self::check_xmlrpc(),
			self::check_debug_display(),
			self::check_salts(),
			self::check_https(),
			self::check_php_version(),
			self::check_config_perms(),
			self::check_dir_perms(),
			self::check_leftovers(),
			self::check_auto_updates(),
			self::check_pending_updates(),
			self::check_table_prefix(),
		);
		$checks = Calmfox_Watch_Checks::normalize(array_values(array_filter($checks)));

		return array(
			'status' => Calmfox_Watch_Checks::aggregate($checks),
			'checks' => $checks,
		);
	}

	private static function check_admins(): array {
		$admins = get_users(array('role' => 'administrator', 'fields' => 'ID', 'number' => 26));
		$supers = Calmfox_Watch_Checks::super_admin_logins();
		$count  = count($admins) + count($supers);

		$detail = sprintf(
			/* translators: %s: liczba */
			__('Kont z pełnymi uprawnieniami: %s.', 'calmfox-watch'), $count > 25 ? '25+' : (string) $count);
		if (array() !== $supers) {
			$detail .= ' '.sprintf(
				/* translators: %d: liczba super-adminów */
				_n('W tym %d super-admin sieci, który ma dostęp do wszystkich stron.',
					'W tym %d super-adminów sieci, którzy mają dostęp do wszystkich stron.', count($supers), 'calmfox-watch'),
				count($supers));
		}

		$many = $count > 5;

		return array('id' => 'admin_count', 'status' => $many ? 'warn' : 'ok',
			'label'  => __('Liczba administratorów', 'calmfox-watch'),
			'detail' => $detail.($many ? ' '.__('Im mniej kont z pełnymi uprawnieniami, tym mniejsza powierzchnia ataku.', 'calmfox-watch') : ''),
			'fix'    => $many ? __('Kontom, które tylko redagują treść, zmień rolę na Redaktor (Editor). Uprawnienia administratora zostaw tym, którzy instalują wtyczki i zmieniają ustawienia.', 'calmfox-watch') : null,
			'command' => $many ? 'wp user list --role=administrator' : null);
	}

	private static function check_admin_login(): array {
		$exists = false !== username_exists('admin');

		return array('id' => 'admin_login', 'status' => $exists ? 'warn' : 'ok',
			'label'  => __('Konto o loginie „admin”', 'calmfox-watch'),
			'detail' => $exists
				? __('Istnieje konto z loginem „admin” — pierwszy cel ataków słownikowych.', 'calmfox-watch')
				: __('Brak konta o domyślnym loginie.', 'calmfox-watch'),
			// Polecenia świadomie nie ma: skasowanie konta administratora zanim
			// zastępcze naprawdę działa to prosta droga do zamknięcia się na zewnątrz.
			'fix'    => $exists
				? __('Załóż konto administratora z własnym loginem, przenieś na nie treści konta „admin”, sprawdź logowanie i dopiero wtedy skasuj stare konto.', 'calmfox-watch')
				: null,
			'command' => null);
	}

	private static function check_registration(): array {
		$open = (bool) get_option('users_can_register');
		$role = (string) get_option('default_role');
		$fix = __('W Ustawienia → Ogólne wyłącz „Każdy może się zarejestrować”, a jeśli rejestracja jest potrzebna — ustaw rolę domyślną na Subskrybent.', 'calmfox-watch');
		if ($open && in_array($role, array('administrator', 'editor'), true)) {
			return array('id' => 'registration', 'status' => 'fail', 'label' => __('Otwarta rejestracja', 'calmfox-watch'),
				/* translators: %s: rola */
				'detail'  => sprintf(__('Każdy może założyć konto z rolą „%s” — to prosta droga do przejęcia strony.', 'calmfox-watch'), $role),
				'fix'     => $fix,
				'command' => 'wp option update default_role subscriber');
		}
		if ($open && 'author' === $role) {
			return array('id' => 'registration', 'status' => 'warn', 'label' => __('Otwarta rejestracja', 'calmfox-watch'),
				'detail'  => __('Każdy może założyć konto autora — może publikować treści.', 'calmfox-watch'),
				'fix'     => $fix,
				'command' => 'wp option update default_role subscriber');
		}

		return array('id' => 'registration', 'status' => 'ok', 'label' => __('Rejestracja użytkowników', 'calmfox-watch'),
			'detail' => $open ? __('Otwarta, z bezpieczną rolą domyślną.', 'calmfox-watch') : __('Wyłączona.', 'calmfox-watch'));
	}

	private static function check_file_edit(): array {
		$blocked = defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT;

		return array('id' => 'file_editor', 'status' => $blocked ? 'ok' : 'warn',
			'label'  => __('Edytor plików w kokpicie', 'calmfox-watch'),
			'detail' => $blocked ? null
				: __('Włączony edytor wtyczek i motywów: przejęcie konta administratora pozwala uruchomić dowolny kod PHP na serwerze. Ustaw DISALLOW_FILE_EDIT.', 'calmfox-watch'),
			'fix'    => $blocked ? null
				: __('Najprościej przyciskiem „Wyłącz edytor plików” obok — ustawienie trzyma wtyczka i cofniesz je w każdej chwili. Na stałe: define(\'DISALLOW_FILE_EDIT\', true); w wp-config.php.', 'calmfox-watch'),
			'command' => $blocked ? null : 'wp config set DISALLOW_FILE_EDIT true --raw');
	}

	private static function check_xmlrpc(): array {
		$enabled = (bool) apply_filters('xmlrpc_enabled', true);

		return array('id' => 'xmlrpc', 'status' => $enabled ? 'warn' : 'ok',
			'label'  => __('XML-RPC', 'calmfox-watch'),
			'detail' => $enabled
				? __('Aktywne — wykorzystywane do ataków siłowych na hasła i ataków typu pingback; wyłącz, jeżeli żadna usługa z niego nie korzysta.', 'calmfox-watch')
				: __('Wyłączone.', 'calmfox-watch'),
			'fix'    => $enabled
				? __('Wyłącz przyciskiem obok (filtr wtyczki, bez edycji plików). Zostaw włączone, jeśli korzystasz z aplikacji mobilnej WordPressa albo z Jetpacka — one chodzą właśnie tędy.', 'calmfox-watch')
				: null,
			'command' => null);
	}

	private static function check_debug_display(): array {
		$leaking = defined('WP_DEBUG') && WP_DEBUG && (!defined('WP_DEBUG_DISPLAY') || WP_DEBUG_DISPLAY);

		return array('id' => 'debug_display', 'status' => $leaking ? 'fail' : 'ok',
			'label'  => __('Wyświetlanie błędów PHP', 'calmfox-watch'),
			'detail' => $leaking
				? __('WP_DEBUG z wyświetlaniem błędów na serwerze produkcyjnym ujawnia ścieżki plików i strukturę bazy danych każdemu odwiedzającemu.', 'calmfox-watch')
				: null,
			'fix'    => $leaking
				? __('W wp-config.php ustaw WP_DEBUG na false. Jeśli musisz zbierać błędy, zostaw WP_DEBUG włączone, ale z WP_DEBUG_DISPLAY na false i WP_DEBUG_LOG na true — wtedy trafiają do pliku, nie na stronę.', 'calmfox-watch')
				: null,
			'command' => $leaking ? 'wp config set WP_DEBUG false --raw' : null);
	}

	private static function check_salts(): array {
		$keys = array('AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT');
		$bad  = array();
		foreach ($keys as $key) {
			$value = defined($key) ? (string) constant($key) : '';
			if (strlen($value) < 32 || false !== stripos($value, 'put your unique phrase here')) {
				$bad[] = $key;
			}
		}

		return array('id' => 'salts', 'status' => empty($bad) ? 'ok' : 'fail',
			'label'  => __('Sole i klucze uwierzytelniania', 'calmfox-watch'),
			'detail' => empty($bad) ? null
				/* translators: %s: lista kluczy */
				: sprintf(__('Domyślne albo zbyt krótkie: %s — ciasteczka logowania można podrobić. Wygeneruj nowe klucze.', 'calmfox-watch'), implode(', ', $bad)),
			'fix'    => empty($bad) ? null
				: __('Wygeneruj nowy komplet kluczy (api.wordpress.org/secret-key/1.1/salt/) i podmień je w wp-config.php. Skutek uboczny jest normalny: wszyscy zalogowani zostaną wylogowani.', 'calmfox-watch'),
			'command' => empty($bad) ? null : 'wp config shuffle-salts');
	}

	private static function check_https(): array {
		$scheme = (string) wp_parse_url(home_url(), PHP_URL_SCHEME);

		return array('id' => 'https', 'status' => 'https' === $scheme ? 'ok' : 'fail',
			'label'  => __('Szyfrowanie HTTPS', 'calmfox-watch'),
			'detail' => 'https' === $scheme ? null
				: __('Strona działa po protokole HTTP — dane logowania i formularze przesyłane są bez szyfrowania, a przeglądarki ostrzegają odwiedzających.', 'calmfox-watch'),
			// Bez polecenia: podmiana adresów strony jednym wywołaniem z literówką
			// w domenie potrafi odciąć dostęp do kokpitu.
			'fix'     => 'https' === $scheme ? null
				: __('Włącz certyfikat u hostingodawcy, zmień „Adres WordPressa” i „Adres witryny” na https w Ustawienia → Ogólne, a na serwerze dodaj stałe przekierowanie z http na https.', 'calmfox-watch'),
			'command' => null);
	}

	private static function check_php_version(): array {
		$label = __('Wersja PHP', 'calmfox-watch');
		// Stan wsparcia PHP na sierpień 2026: <8.2 bez wsparcia, 8.2 tylko łatki bezpieczeństwa.
		// Wersję PHP przestawia się w panelu hostingu, nie poleceniem: `php -v` w SSH
		// pokazuje wersję konsolową, która bywa inna niż ta, na której chodzi strona.
		$fix = __('W panelu hostingu przestaw wersję PHP dla tej domeny na 8.3 lub nowszą, a zaraz po zmianie sprawdź stronę i kokpit.', 'calmfox-watch');
		if (version_compare(PHP_VERSION, '8.2', '<')) {
			return array('id' => 'php_version', 'status' => 'fail', 'label' => $label,
				/* translators: %s: wersja */
				'detail'  => sprintf(__('PHP %s nie dostaje już nawet poprawek bezpieczeństwa — konieczna aktualizacja u hostingodawcy.', 'calmfox-watch'), PHP_VERSION),
				'fix'     => $fix,
				'command' => null);
		}
		if (version_compare(PHP_VERSION, '8.3', '<')) {
			return array('id' => 'php_version', 'status' => 'warn', 'label' => $label,
				/* translators: %s: wersja */
				'detail'  => sprintf(__('PHP %s dostaje już tylko poprawki bezpieczeństwa — zaplanuj przejście wyżej.', 'calmfox-watch'), PHP_VERSION),
				'fix'     => $fix,
				'command' => null);
		}

		return array('id' => 'php_version', 'status' => 'ok', 'label' => $label,
			/* translators: %s: wersja */
			'detail' => sprintf(__('PHP %s — wersja wspierana.', 'calmfox-watch'), PHP_VERSION));
	}

	/** wp-config.php: 644 to norma na współdzielonych hostingach — alarmujemy dopiero przy zapisie dla grupy/świata. */
	private static function check_config_perms() {
		$path = file_exists(ABSPATH.'wp-config.php') ? ABSPATH.'wp-config.php'
			: (file_exists(dirname(ABSPATH).'/wp-config.php') ? dirname(ABSPATH).'/wp-config.php' : '');
		if ('' === $path) {
			return null;
		}
		$perms = fileperms($path) & 0777;
		if ($perms & 0002) {
			$status = 'fail';
		} elseif ($perms & 0020) {
			$status = 'warn';
		} else {
			$status = 'ok';
		}

		$relative = 0 === strpos($path, ABSPATH) ? substr($path, strlen(ABSPATH)) : '../wp-config.php';

		return array('id' => 'config_perms', 'status' => $status,
			'label'  => __('Uprawnienia wp-config.php', 'calmfox-watch'),
			/* translators: %s: prawa */
			'detail' => 'ok' === $status ? sprintf(__('Prawa %s.', 'calmfox-watch'), decoct($perms))
				: sprintf(__('Uprawnienia %s — plik z danymi dostępu do bazy jest zapisywalny dla innych użytkowników serwera. Ustaw 640 albo 600.', 'calmfox-watch'), decoct($perms)),
			'fix'    => 'ok' === $status ? null
				: __('Docelowo 640: właściciel czyta i zapisuje, grupa serwera WWW tylko czyta, reszta serwera nic. Ten sam efekt daje przycisk „Napraw uprawnienia” obok.', 'calmfox-watch'),
			'command' => 'ok' === $status ? null : Calmfox_Watch_Checks::chmod_command('640', array($relative)));
	}

	private static function check_dir_perms(): array {
		$world  = array();
		$paths  = array();
		$upload = wp_upload_dir(null, false);
		$dirs   = array('wp-content' => WP_CONTENT_DIR);
		if (!empty($upload['basedir'])) {
			$dirs['uploads'] = $upload['basedir'];
		}
		foreach ($dirs as $name => $dir) {
			if (is_dir($dir) && ((fileperms($dir) & 0777) & 0002)) {
				$world[] = $name.' ('.decoct(fileperms($dir) & 0777).')';
				$paths[] = 0 === strpos($dir, ABSPATH) ? rtrim(substr($dir, strlen(ABSPATH)), '/') : $name;
			}
		}

		// -R świadomie: prawa 777 zwykle siedzą też w podkatalogach, a zmiana samego
		// katalogu nadrzędnego zostawiłaby otwarte dokładnie te miejsca, do których
		// trafiają wgrywane pliki.
		return array('id' => 'dir_perms', 'status' => empty($world) ? 'ok' : 'fail',
			'label'  => __('Uprawnienia katalogów', 'calmfox-watch'),
			'detail' => empty($world) ? null
				/* translators: %s: katalogi */
				: sprintf(__('Zapisywalne dla wszystkich (777): %s — dowolny proces na serwerze może umieścić tam własny plik.', 'calmfox-watch'), implode(', ', $world)),
			'fix'    => empty($world) ? null
				: __('Katalogom WordPressa wystarczy 755, a gdy serwer WWW pracuje na innym użytkowniku niż właściciel plików — 775 przy wspólnej grupie. Ten sam efekt daje przycisk „Napraw uprawnienia” obok.', 'calmfox-watch'),
			'command' => empty($world) ? null : Calmfox_Watch_Checks::chmod_command('755', $paths, true));
	}

	/** Nieaktywne wtyczki i motywy to martwy kod z własnymi dziurami — do sprzątnięcia. */
	private static function check_leftovers(): array {
		$all      = count(get_plugins());
		$active   = count((array) get_option('active_plugins', array()));
		$inactive = max(0, $all - $active);
		$themes   = max(0, count(wp_get_themes()) - 1);

		$status = ($inactive > 5 || $themes > 2) ? 'warn' : 'ok';

		return array('id' => 'leftovers', 'status' => $status,
			'label'  => __('Nieużywane wtyczki i motywy', 'calmfox-watch'),
			/* translators: 1: wtyczki, 2: motywy */
			'detail' => sprintf(__('Nieaktywne wtyczki: %1$d, zapasowe motywy: %2$d.', 'calmfox-watch'), $inactive, $themes)
				.('warn' === $status ? ' '.__('Nieużywany kod również bywa podatny na ataki — usuń to, czego nie potrzebujesz.', 'calmfox-watch') : ''),
			'fix'    => 'warn' === $status
				? __('Przejrzyj listę nieaktywnych wtyczek i usuń te, do których nie wrócisz. Jeden motyw zapasowy warto zostawić — WordPress przełączy się na niego, gdy padnie motyw główny.', 'calmfox-watch')
				: null,
			'command' => 'warn' === $status ? 'wp plugin list --status=inactive' : null);
	}

	/**
	 * Trzy źródła prawdy o auto-aktualizacjach (stała, filtr wyłączający i opcje
	 * per wtyczka) — patrz Calmfox_Watch_Checks::auto_update_state(). Sama stała
	 * przepuszczała wtyczki wyłączające aktualizacje filtrem.
	 */
	private static function check_auto_updates(): array {
		$state = Calmfox_Watch_Checks::auto_update_state();
		$label = __('Automatyczne aktualizacje', 'calmfox-watch');

		if ('off' === $state['core']) {
			return array('id' => 'auto_updates', 'status' => 'warn', 'label' => $label,
				'detail'  => __('Wyłączone dla WordPressa: poprawki bezpieczeństwa wymagają ręcznej instalacji. Sprawdź, czy nie zrobiła tego wtyczka blokująca aktualizacje.', 'calmfox-watch'),
				'fix'     => __('W wp-config.php usuń define(\'WP_AUTO_UPDATE_CORE\', false) albo ustaw wartość na \'minor\' — wtedy wracają automatyczne wydania z poprawkami bezpieczeństwa.', 'calmfox-watch'),
				'command' => 'wp config set WP_AUTO_UPDATE_CORE minor');
		}
		if (0 === (int) $state['plugins']) {
			return array('id' => 'auto_updates', 'status' => 'warn', 'label' => $label,
				'detail'  => __('WordPress aktualizuje się sam, ale żadna wtyczka nie ma włączonej automatycznej aktualizacji. Wtyczki to najczęstsza droga włamania na stronę.', 'calmfox-watch'),
				'fix'     => __('Na liście wtyczek włącz „Włącz automatyczne aktualizacje” przy tych, którym ufasz. Przy wtyczce kluczowej dla wyglądu albo sprzedaży lepiej aktualizować ręcznie po kopii zapasowej.', 'calmfox-watch'),
				'command' => 'wp plugin auto-updates enable --all');
		}

		return array('id' => 'auto_updates', 'status' => 'ok', 'label' => $label,
			/* translators: 1: liczba wtyczek, 2: liczba motywów */
			'detail' => sprintf(__('WordPress aktualizuje się sam, automatyczna aktualizacja włączona dla %1$d wtyczek i %2$d motywów.', 'calmfox-watch'),
				(int) $state['plugins'], (int) $state['themes']));
	}

	private static function check_pending_updates(): array {
		$info    = Calmfox_Watch_Checks::site_info();
		$updates = $info['updates'];
		if ($updates['core'] > 0) {
			return array('id' => 'pending_updates', 'status' => 'warn', 'label' => __('Zaległe aktualizacje', 'calmfox-watch'),
				'detail'  => __('Dostępna aktualizacja WordPressa — aktualizacja rdzenia jest najpilniejsza.', 'calmfox-watch'),
				'fix'     => __('Zrób kopię zapasową, zaktualizuj rdzeń, a zaraz po tym sprawdź stronę główną i formularz kontaktowy.', 'calmfox-watch'),
				'command' => 'wp core check-update');
		}
		$rest = $updates['plugins'] + $updates['themes'];
		$many = $rest >= 5;

		return array('id' => 'pending_updates', 'status' => $many ? 'warn' : 'ok',
			'label'  => __('Zaległe aktualizacje', 'calmfox-watch'),
			/* translators: 1: wtyczki, 2: motywy */
			'detail' => sprintf(__('Wtyczki: %1$d, motywy: %2$d do aktualizacji.', 'calmfox-watch'), $updates['plugins'], $updates['themes']),
			'fix'    => $many
				? __('Zrób kopię zapasową i aktualizuj partiami, zaczynając od wtyczek odpowiedzialnych za bezpieczeństwo i płatności. Po każdej partii sprawdź stronę.', 'calmfox-watch')
				: null,
			'command' => $many ? 'wp plugin list --update=available' : null);
	}

	private static function check_table_prefix(): array {
		global $wpdb;
		$default = 'wp_' === $wpdb->prefix;

		// Zmiana prefiksu to operacja na bazie działającej strony, a zysk jest
		// niewielki — dlatego status zostaje „ok", a podpowiedź mówi wprost,
		// że to zadanie na wdrożenie, nie na jedno polecenie z kokpitu.
		return array('id' => 'table_prefix', 'status' => 'ok',
			'label'  => __('Prefiks tabel bazy', 'calmfox-watch'),
			'detail' => $default
				? __('Domyślny „wp_” — nieznacznie ułatwia zautomatyzowane ataki SQL; zmiana jest zalecana, ale nie konieczna.', 'calmfox-watch')
				: __('Niestandardowy.', 'calmfox-watch'),
			'fix'    => $default
				? __('Prefiks zmienia się razem z nazwami tabel i wpisami w bazie — rób to wyłącznie po kopii zapasowej i najlepiej przy okazji większych prac, nie na żywej stronie w środku dnia.', 'calmfox-watch')
				: null,
			'command' => null);
	}
}

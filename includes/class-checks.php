<?php
defined('ABSPATH') || exit;

/**
 * Checki zdrowia usług (sekcja health). Kontrakt statusów: ok / warn / fail —
 * fail przełącza endpoint na HTTP 503 i budzi monitoring, warn zostaje w panelu.
 * Każdy check ma twardy, krótki timeout — endpoint nie może zamulić strony.
 * Własne usługi (RabbitMQ, Elasticsearch spoza ElasticPress itp.) dorzuca się
 * filtrem `calmfox_watch_health_checks`.
 */
final class Calmfox_Watch_Checks {

	const SMTP_CACHE  = 'calmfox_watch_smtp_check';
	const SIZE_CACHE  = 'calmfox_watch_install_size';
	const ES_CACHE    = 'calmfox_watch_es_check';
	const NET_TIMEOUT = 2; // sekundy na pojedyncze połączenie sieciowe
	const MAX_COMMAND = 200; // limit przykładowego polecenia naprawczego, taki sam po stronie huba

	/** @return array{status: string, checks: array<int, array<string, mixed>>} */
	public static function run(): array {
		$checks = array(
			self::check_db(),
			self::check_disk(),
			self::check_smtp(),
			self::check_cron(),
			self::check_object_cache(),
			self::check_elasticpress(),
		);
		$checks = array_values(array_filter($checks));

		/**
		 * Deweloper strony dokłada własne usługi:
		 * add_filter('calmfox_watch_health_checks', function (array $checks) {
		 *     $ok = @fsockopen('127.0.0.1', 5672, $e, $s, 2);
		 *     $checks[] = array('id' => 'rabbitmq', 'status' => $ok ? 'ok' : 'fail',
		 *         'label' => 'RabbitMQ', 'detail' => $ok ? null : 'Broker nie przyjmuje połączeń.');
		 *     if ($ok) { fclose($ok); }
		 *     return $checks;
		 * });
		 */
		$checks = apply_filters('calmfox_watch_health_checks', $checks);

		$checks = self::normalize($checks);

		return array(
			'status' => self::aggregate($checks),
			'checks' => $checks,
		);
	}

	/** Wersje i LICZBY zaległych aktualizacji — świadomie bez nazw wtyczek. */
	public static function site_info(): array {
		$counts = array('core' => 0, 'plugins' => 0, 'themes' => 0);
		if (function_exists('wp_get_update_data')) {
			$data              = wp_get_update_data();
			$counts['core']    = isset($data['counts']['wordpress']) ? (int) $data['counts']['wordpress'] : 0;
			$counts['plugins'] = isset($data['counts']['plugins']) ? (int) $data['counts']['plugins'] : 0;
			$counts['themes']  = isset($data['counts']['themes']) ? (int) $data['counts']['themes'] : 0;
		}

		return array(
			'wp'      => (string) get_bloginfo('version'),
			'php'     => PHP_VERSION,
			'plugin'  => CALMFOX_WATCH_VERSION,
			'updates' => $counts,
		);
	}

	/**
	 * Sygnały bezpieczeństwa dołączane do sekcji health, żeby hub sprawdzał je
	 * co pięć minut, a nie raz na dobę: nagły przyrost administratorów to
	 * klasyczny objaw przejęcia strony, a wyłączenie aktualizacji i wtyczek
	 * zabezpieczających bywa pierwszym krokiem po przejęciu kokpitu.
	 * Loginów administratorów NIE wysyłamy — jedzie liczba, jednokierunkowy
	 * odcisk zbioru kont (zmiana odcisku = zmiana składu) i data najnowszego
	 * konta. Kto to konkretnie, klient widzi u siebie.
	 *
	 * @return array<string, mixed>
	 */
	public static function security_signals(): array {
		$admins = get_users(array(
			'role'    => 'administrator',
			'fields'  => array('ID', 'user_login', 'user_registered'),
			'number'  => 200,
			'orderby' => 'ID',
		));

		$parts  = array();
		$newest = '';
		foreach ($admins as $admin) {
			$parts[] = $admin->ID.':'.$admin->user_login;
			if ((string) $admin->user_registered > $newest) {
				$newest = (string) $admin->user_registered;
			}
		}

		// Multisite: super-admin ma władzę nad KAŻDĄ stroną w sieci, a nie ma
		// roli administratora na podstronie — bez tego nowe konto tej rangi
		// przeszłoby niezauważone, czyli dokładnie to, na czym zależy atakującemu.
		$supers = self::super_admin_logins();
		foreach ($supers as $login) {
			$parts[] = 'super:'.$login;
		}
		sort($parts);

		$plugins = self::plugin_signals();

		return array_merge(array(
			'adminCount'        => count($admins) + count($supers),
			// Sól z sekretu instalacji: odcisk jest bezużyteczny poza tą stroną.
			'adminsFingerprint' => substr(hash_hmac('sha256', implode('|', $parts), (string) Calmfox_Watch_Settings::get('secret')), 0, 32),
			'newestAdminAt'     => '' !== $newest ? gmdate('c', (int) strtotime($newest)) : null,
		), $plugins);
	}

	/**
	 * Zestaw aktywnych wtyczek i stan automatycznych aktualizacji. Tu nazwy SĄ
	 * wysyłane (inaczej hub powie tylko „coś się zmieniło”, a klient potrzebuje
	 * wiedzieć która wtyczka zniknęła) — tak samo, jak od zawsze robi to historia
	 * aktualizacji. Wersji przy nazwach świadomie nie ma: hub pilnuje SKŁADU,
	 * a wersje i tak jadą historią po aktualizacji.
	 *
	 * @return array<string, mixed>
	 */
	private static function plugin_signals(): array {
		if (!function_exists('get_plugins')) {
			require_once ABSPATH.'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();

		$names = array();
		$files = array();
		foreach (array_keys($all) as $file) {
			if (!is_plugin_active((string) $file) && !(is_multisite() && is_plugin_active_for_network((string) $file))) {
				continue;
			}
			$files[] = (string) $file;
			$names[] = (string) ($all[$file]['Name'] ?? $file);
		}
		sort($files);
		sort($names);

		// Kursor aktualizacji jedzie w SZYBKIM payloadzie, choć szczegóły (co, z jakiej
		// wersji na jaką) siedzą w sekcji `security`. Powód: `security` hub odpytuje raz
		// na dobę, a odcisk wtyczek liczy się ze ŚCIEŻEK plików, więc podbicie wersji go
		// nie rusza. Bez tego kursora aktualizacja o drugiej w nocy czekałaby na reakcję
		// do następnej nocy.
		$cursor = Calmfox_Watch_History::cursor();

		return array(
			'pluginCount'        => count($files),
			'pluginsFingerprint' => substr(hash_hmac('sha256', implode('|', $files), (string) Calmfox_Watch_Settings::get('secret')), 0, 32),
			'activePlugins'      => array_slice($names, 0, 100),
			'autoUpdates'        => self::auto_update_state(),
			'updateSeq'          => $cursor['seq'],
			'lastUpdateAt'       => $cursor['at'],
		);
	}

	/**
	 * Stan automatycznych aktualizacji. Sama stała WP_AUTO_UPDATE_CORE nie wystarcza:
	 * wtyczki wyłączające aktualizacje (Easy Updates Manager i pokrewne) robią to
	 * filtrem `automatic_updater_disabled`, a per-wtyczkowe auto-aktualizacje z kokpitu
	 * siedzą w opcjach `auto_update_plugins` / `auto_update_themes`. Bez tych trzech
	 * źródeł „aktualizacje wyłączone” przechodzi niezauważone.
	 *
	 * @return array<string, mixed>
	 */
	public static function auto_update_state(): array {
		$blocked = (defined('AUTOMATIC_UPDATER_DISABLED') && AUTOMATIC_UPDATER_DISABLED)
			|| (bool) apply_filters('automatic_updater_disabled', false);
		$constOff = defined('WP_AUTO_UPDATE_CORE') && in_array(WP_AUTO_UPDATE_CORE, array(false, 'false'), true);

		if ($blocked || $constOff) {
			$core = 'off';
		} elseif (defined('WP_AUTO_UPDATE_CORE') && in_array(WP_AUTO_UPDATE_CORE, array(true, 'true', 'beta', 'rc'), true)) {
			$core = 'all';
		} else {
			$core = 'minor'; // domyślne zachowanie WordPressa: wydania poprawkowe i bezpieczeństwa
		}

		$plugins = (array) get_site_option('auto_update_plugins', array());
		$themes  = (array) get_site_option('auto_update_themes', array());
		$self    = in_array(plugin_basename(CALMFOX_WATCH_FILE), $plugins, true);

		return array(
			'core'    => $core,
			'plugins' => count($plugins),
			'themes'  => count($themes),
			'self'    => $self,
		);
	}

	/**
	 * Super-adminowie sieci, których nie ma wśród administratorów tej podstrony.
	 *
	 * @return array<int, string>
	 */
	public static function super_admin_logins(): array {
		if (!is_multisite()) {
			return array();
		}
		$local = array();
		foreach (get_users(array('role' => 'administrator', 'fields' => array('user_login'), 'number' => 200)) as $admin) {
			$local[] = $admin->user_login;
		}

		return array_values(array_diff((array) get_super_admins(), $local));
	}

	/** Wspólna agregacja: fail bije warn, warn bije ok. */
	public static function aggregate(array $checks): string {
		$status = 'ok';
		foreach ($checks as $check) {
			if ('fail' === $check['status']) {
				return 'fail';
			}
			if ('warn' === $check['status']) {
				$status = 'warn';
			}
		}

		return $status;
	}

	/** Filtr mógł dorzucić cokolwiek — normalizujemy do kontraktu jak hub. */
	public static function normalize($checks): array {
		if (!is_array($checks)) {
			return array();
		}
		$out = array();
		foreach ($checks as $check) {
			if (!is_array($check)) {
				continue;
			}
			$id     = strtolower((string) ($check['id'] ?? ''));
			$status = (string) ($check['status'] ?? '');
			if (!preg_match('/^[a-z0-9_-]{1,40}$/', $id) || !in_array($status, array('ok', 'warn', 'fail'), true)) {
				continue;
			}
			$ms    = $check['ms'] ?? null;
			$out[] = array(
				'id'      => $id,
				'status'  => $status,
				'label'   => self::text($check['label'] ?? null, 80),
				'detail'  => self::text($check['detail'] ?? null, 300),
				'fix'     => self::text($check['fix'] ?? null, 200),
				'command' => self::command($check['command'] ?? null),
				'ms'      => (is_int($ms) && $ms >= 0) ? $ms : null,
			);
			if (count($out) >= 60) {
				break;
			}
		}

		return $out;
	}

	// ── Poszczególne checki ──────────────────────────────────────────────

	private static function check_db(): array {
		global $wpdb;
		$start = microtime(true);
		$one   = $wpdb->get_var('SELECT 1');
		$ms    = (int) round((microtime(true) - $start) * 1000);
		$ok    = '1' === (string) $one;

		return array(
			'id'     => 'db',
			'status' => $ok ? 'ok' : 'fail',
			'label'  => __('Baza danych', 'calmfox-watch'),
			'detail' => $ok ? null : ('' !== (string) $wpdb->last_error ? (string) $wpdb->last_error : __('Zapytanie testowe nie zwróciło wyniku.', 'calmfox-watch')),
			'ms'     => $ms,
		);
	}

	/**
	 * Miejsce na dysku. Na hostingu współdzielonym disk_free_space() podaje CAŁY
	 * wolumen serwera (widzieliśmy „wolne 4,8 TB" na koncie z kilkoma GB limitu),
	 * więc takiej liczby nie pokazujemy — to nie jest informacja o koncie klienta.
	 * Kolejność: limit podany przez klienta → wiarygodny odczyt systemowy →
	 * uczciwe „nie wiemy, podaj limit", zawsze z realnie mierzoną zajętością strony.
	 */
	private static function check_disk(): array {
		$label  = __('Miejsce na dysku', 'calmfox-watch');
		$upload = wp_upload_dir(null, false);
		$dir    = !empty($upload['basedir']) && is_dir($upload['basedir']) ? $upload['basedir'] : WP_CONTENT_DIR;

		if (!wp_is_writable($dir)) {
			return array('id' => 'disk', 'status' => 'fail', 'label' => $label,
				'detail' => __('Katalog uploads nie jest zapisywalny, przesyłanie mediów i aktualizacje będą kończyć się błędem.', 'calmfox-watch'));
		}

		$used  = self::install_size();
		$quota = (float) Calmfox_Watch_Settings::get('disk_quota_gb');

		// 1) Limit konta podany przez klienta — jedyna pewna liczba na współdzielonym hostingu.
		if ($quota > 0) {
			$limit = $quota * GB_IN_BYTES;
			$pct   = (int) floor($used / $limit * 100);
			$status = 'ok';
			if ($pct >= 95) {
				$status = 'fail';
			} elseif ($pct >= 85) {
				$status = 'warn';
			}

			return array('id' => 'disk', 'status' => $status, 'label' => $label,
				/* translators: 1: zajęte, 2: limit, 3: procent */
				'detail' => sprintf(__('%1$s %2$s z podanego limitu konta %3$s (%4$d%%). Poza tym miejsce zajmują poczta i pozostałe strony na koncie.', 'calmfox-watch'),
					self::size_label(), size_format($used), size_format($limit), $pct));
		}

		$free  = @disk_free_space($dir);
		$total = @disk_total_space($dir);

		// 2) Odczyt systemowy tylko wtedy, gdy naprawdę dotyczy tego konta (VPS/serwer dedykowany).
		if (false !== $free && false !== $total && $total > 0 && !self::disk_reading_is_shared($total)) {
			$pct    = (int) floor($free / $total * 100);
			$status = 'ok';
			if ($pct < 3 || $free < 200 * MB_IN_BYTES) {
				$status = 'fail';
			} elseif ($pct < 10 || $free < GB_IN_BYTES) {
				$status = 'warn';
			}

			return array('id' => 'disk', 'status' => $status, 'label' => $label,
				/* translators: 1: wolne, 2: całkowite, 3: procent, 4: rozmiar strony */
				'detail' => sprintf(__('Wolne %1$s z %2$s (%3$d%%). %4$s %5$s.', 'calmfox-watch'),
					size_format($free), size_format($total), $pct, self::size_label(), size_format($used)));
		}

		// 3) Hosting współdzielony bez limitu od klienta — mówimy wprost, czego nie wiemy.
		return array('id' => 'disk', 'status' => 'ok', 'label' => $label,
			/* translators: %s: rozmiar instalacji */
			'detail' => sprintf(__('%1$s %2$s. Hosting nie pokazuje limitu tego konta (widzimy tylko wspólny dysk serwera), więc nie liczymy zajętości. Podaj limit w ustawieniach wtyczki, a będziemy go pilnować.', 'calmfox-watch'),
				self::size_label(), size_format($used)));
	}

	/**
	 * Czy odczyt disk_free_space dotyczy całego serwera, a nie konta: ślady
	 * hostingu współdzielonego (CloudLinux/CageFS, DirectAdmin, cPanel, Plesk,
	 * open_basedir) albo wolumen tak duży, że nie należy do jednego klienta.
	 */
	private static function disk_reading_is_shared(float $total): bool {
		foreach (array('/usr/local/directadmin', '/usr/local/cpanel', '/opt/psa', '/proc/lve', '/var/cagefs') as $path) {
			if (@file_exists($path)) {
				return true;
			}
		}
		if ('' !== (string) ini_get('open_basedir')) {
			return true;
		}

		return $total >= 1024 * GB_IN_BYTES; // 1 TB „wolnego" to nie jest konto współdzielone
	}

	/**
	 * Rozmiar instalacji (katalog WordPressa) — liczony realnie, ale z limitami
	 * czasu i liczby plików, żeby check nigdy nie zamulił strony. Cache na dobę;
	 * przy przekroczeniu limitu zwracamy tyle, ile zdążyliśmy policzyć.
	 */
	/** Na multisite instalacja jest wspólna dla sieci — nazywamy to po imieniu. */
	private static function size_label(): string {
		return is_multisite()
			? __('Cała sieć multisite zajmuje', 'calmfox-watch')
			: __('Sama strona zajmuje', 'calmfox-watch');
	}

	private static function install_size(): int {
		$cached = get_transient(self::SIZE_CACHE);
		if (false !== $cached) {
			return (int) $cached;
		}

		$bytes    = 0;
		$files    = 0;
		$deadline = microtime(true) + 3.0;
		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator(ABSPATH, FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::LEAVES_ONLY,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);
			foreach ($it as $file) {
				if ($file->isFile()) {
					$bytes += $file->getSize();
				}
				if (++$files >= 200000 || (0 === $files % 2000 && microtime(true) > $deadline)) {
					break;
				}
			}
		} catch (Exception $e) {
			// Nieczytelny katalog nie może wywrócić checku — zwracamy, co policzone.
		}

		set_transient(self::SIZE_CACHE, $bytes, DAY_IN_SECONDS);

		return $bytes;
	}

	/**
	 * Poczta: UCZCIWIE test połączenia z serwerem SMTP (TCP + powitanie 220 + EHLO),
	 * nie test doręczenia. Konfigurację bierzemy ze znanych wtyczek SMTP albo
	 * z filtra `calmfox_watch_smtp` (array: host, port, secure 'ssl'|'tls'|'').
	 * Wynik cache'owany 15 minut — to najdroższy check.
	 */
	private static function check_smtp(): array {
		$label = __('Wysyłka e-mail (SMTP)', 'calmfox-watch');
		$conf  = apply_filters('calmfox_watch_smtp', self::detect_smtp());

		if (!is_array($conf) || empty($conf['host'])) {
			return array('id' => 'smtp', 'status' => 'warn', 'label' => $label,
				'detail' => __('Brak skonfigurowanego serwera SMTP — wysyłka przez funkcję PHP mail() bywa zawodna i nie podlega monitorowaniu.', 'calmfox-watch'));
		}
		if (!empty($conf['api'])) {
			return array('id' => 'smtp', 'status' => 'ok', 'label' => __('Wysyłka e-mail', 'calmfox-watch'),
				/* translators: %s: nazwa mailera */
				'detail' => sprintf(__('Wysyłka przez API (%s) — test połączenia SMTP nie dotyczy.', 'calmfox-watch'), (string) $conf['api']));
		}

		$cached = get_transient(self::SMTP_CACHE);
		if (is_array($cached)) {
			return $cached;
		}

		$host   = (string) $conf['host'];
		$port   = (int) ($conf['port'] ?? 587);
		$secure = (string) ($conf['secure'] ?? '');
		$target = ('ssl' === $secure ? 'ssl://' : '').$host;

		$start  = microtime(true);
		$errno  = 0;
		$errstr = '';
		$sock   = @fsockopen($target, $port, $errno, $errstr, self::NET_TIMEOUT);
		$ms     = (int) round((microtime(true) - $start) * 1000);

		if (!is_resource($sock)) {
			$result = array('id' => 'smtp', 'status' => 'fail', 'label' => $label,
				/* translators: 1: host, 2: port, 3: błąd */
				'detail' => sprintf(__('Nie można połączyć z %1$s:%2$d — %3$s.', 'calmfox-watch'), $host, $port, '' !== $errstr ? $errstr : __('brak odpowiedzi', 'calmfox-watch')), 'ms' => $ms);
		} else {
			stream_set_timeout($sock, self::NET_TIMEOUT);
			$greeting = (string) fgets($sock, 512);
			fwrite($sock, 'EHLO '.wp_parse_url(home_url(), PHP_URL_HOST)."\r\n");
			$ehlo = (string) fgets($sock, 512);
			fwrite($sock, "QUIT\r\n");
			fclose($sock);
			$ms = (int) round((microtime(true) - $start) * 1000);

			$fine   = 0 === strpos($greeting, '220') && 0 === strpos($ehlo, '250');
			$result = array('id' => 'smtp', 'status' => $fine ? 'ok' : 'fail', 'label' => $label,
				'detail' => $fine
					/* translators: 1: host, 2: port */
					? sprintf(__('Serwer %1$s:%2$d odpowiada poprawnie.', 'calmfox-watch'), $host, $port)
					/* translators: 1: host, 2: odpowiedź */
					: sprintf(__('Serwer %1$s odpowiada, ale nie po SMTP-owemu: „%2$s”.', 'calmfox-watch'), $host, trim('' !== $greeting ? $greeting : $ehlo)),
				'ms' => $ms);
		}

		set_transient(self::SMTP_CACHE, $result, 15 * MINUTE_IN_SECONDS);

		return $result;
	}

	/** Konfiguracja SMTP ze znanych wtyczek (najpopularniejsze na polskich hostingach). */
	private static function detect_smtp() {
		// WP Mail SMTP (wpmailsmtp.com)
		$wms = get_option('wp_mail_smtp');
		if (is_array($wms) && !empty($wms['mail']['mailer'])) {
			$mailer = (string) $wms['mail']['mailer'];
			if ('smtp' === $mailer && !empty($wms['smtp']['host'])) {
				return array('host' => (string) $wms['smtp']['host'], 'port' => (int) ($wms['smtp']['port'] ?? 587),
					'secure' => (string) ($wms['smtp']['encryption'] ?? ''));
			}
			if (!in_array($mailer, array('', 'mail', 'phpmail'), true)) {
				return array('api' => $mailer);
			}
		}

		// Easy WP SMTP
		$easy = get_option('easy_wp_smtp');
		if (is_array($easy) && !empty($easy['smtp_settings']['host'])) {
			$s = $easy['smtp_settings'];

			return array('host' => (string) $s['host'], 'port' => (int) ($s['port'] ?? 587),
				'secure' => ('ssl' === ($s['type_encryption'] ?? '') ? 'ssl' : (string) ($s['type_encryption'] ?? '')));
		}

		// FluentSMTP — pierwsza skonfigurowana konekcja
		$fluent = get_option('fluentmail-settings');
		if (is_array($fluent) && !empty($fluent['connections']) && is_array($fluent['connections'])) {
			foreach ($fluent['connections'] as $conn) {
				$prov = isset($conn['provider_settings']) ? $conn['provider_settings'] : array();
				if (('smtp' === ($prov['provider'] ?? '')) && !empty($prov['host'])) {
					return array('host' => (string) $prov['host'], 'port' => (int) ($prov['port'] ?? 587),
						'secure' => (string) ($prov['encryption'] ?? ''));
				}
				if (!empty($prov['provider'])) {
					return array('api' => (string) $prov['provider']);
				}
			}
		}

		return null;
	}

	/** WP-Cron: zaległe zadania = nie działają zaplanowane wpisy, aktualizacje, wysyłki. */
	private static function check_cron(): array {
		$label = __('Harmonogram zadań (WP-Cron)', 'calmfox-watch');
		$jobs  = function_exists('wp_get_ready_cron_jobs') ? wp_get_ready_cron_jobs() : array();
		if (empty($jobs)) {
			return array('id' => 'wp_cron', 'status' => 'ok', 'label' => $label,
				'detail' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON
					? __('Na bieżąco (WP-Cron wywoływany z crona systemowego).', 'calmfox-watch')
					: __('Na bieżąco.', 'calmfox-watch'));
		}

		$oldest = min(array_keys($jobs));
		$late   = time() - (int) $oldest;
		$status = 'ok';
		if ($late > 2 * HOUR_IN_SECONDS) {
			$status = 'fail';
		} elseif ($late > 30 * MINUTE_IN_SECONDS) {
			$status = 'warn';
		}

		return array('id' => 'wp_cron', 'status' => $status, 'label' => $label,
			'detail' => 'ok' === $status ? null
				/* translators: %s: czas opóźnienia */
				: sprintf(__('Najstarsze zaległe zadanie oczekuje od %s — harmonogram mógł przestać działać.', 'calmfox-watch'), human_time_diff((int) $oldest)));
	}

	/** Cache obiektowy: tylko gdy strona faktycznie używa zewnętrznego (Redis/Memcached). */
	private static function check_object_cache() {
		if (!wp_using_ext_object_cache()) {
			return null;
		}
		global $wp_object_cache;
		$backend = is_object($wp_object_cache) ? get_class($wp_object_cache) : '';
		if (false !== stripos($backend, 'redis')) {
			$backend = 'Redis';
		} elseif (false !== stripos($backend, 'memcache')) {
			$backend = 'Memcached';
		}

		$start = microtime(true);
		$value = uniqid('cw', true);
		wp_cache_set('calmfox_watch_ping', $value, '', 30);
		$back = wp_cache_get('calmfox_watch_ping');
		$ms   = (int) round((microtime(true) - $start) * 1000);

		return array('id' => 'object_cache', 'status' => $back === $value ? 'ok' : 'fail',
			/* translators: %s: backend cache */
			'label'  => '' !== $backend ? sprintf(__('Cache obiektowy (%s)', 'calmfox-watch'), $backend) : __('Cache obiektowy', 'calmfox-watch'),
			'detail' => $back === $value ? null : __('Zapis i odczyt z cache nie zgadzają się — usługa cache mogła paść.', 'calmfox-watch'),
			'ms'     => $ms);
	}

	/** Elasticsearch przez ElasticPress — jeśli strona go używa, jego awaria psuje szukajkę/listingi. */
	private static function check_elasticpress() {
		if (!defined('EP_VERSION') && !class_exists('\ElasticPress\Elasticsearch')) {
			return null;
		}
		$host = defined('EP_HOST') ? EP_HOST : get_option('ep_host');
		if (!is_string($host) || '' === $host) {
			return null;
		}

		$cached = get_transient(self::ES_CACHE);
		if (is_array($cached)) {
			return $cached;
		}

		$label    = __('Elasticsearch (ElasticPress)', 'calmfox-watch');
		$start    = microtime(true);
		$response = wp_remote_get(untrailingslashit($host).'/_cluster/health', array('timeout' => self::NET_TIMEOUT));
		$ms       = (int) round((microtime(true) - $start) * 1000);

		if (is_wp_error($response)) {
			$result = array('id' => 'elasticsearch', 'status' => 'fail', 'label' => $label,
				'detail' => sprintf(__('Klaster nie odpowiada: %s.', 'calmfox-watch'), $response->get_error_message()), 'ms' => $ms);
		} else {
			$body    = json_decode((string) wp_remote_retrieve_body($response), true);
			$cluster = is_array($body) && isset($body['status']) ? (string) $body['status'] : '';
			$status  = 'red' === $cluster ? 'fail' : ('' === $cluster ? 'fail' : 'ok');
			$result  = array('id' => 'elasticsearch', 'status' => $status, 'label' => $label,
				'detail' => '' === $cluster ? __('Odpowiedź klastra bez pola status.', 'calmfox-watch')
					/* translators: %s: kolor klastra */
					: sprintf(__('Stan klastra: %s.', 'calmfox-watch'), $cluster), 'ms' => $ms);
		}

		set_transient(self::ES_CACHE, $result, 5 * MINUTE_IN_SECONDS);

		return $result;
	}

	/**
	 * Przykładowe polecenie naprawcze. Panel daje przy nim przycisk kopiowania,
	 * więc jest jedyną wartością z tej instalacji, którą ktoś wkleja sobie do
	 * terminala: jedna linia i wyłącznie drukowalne ASCII. Za długiego NIE
	 * przycinamy, tylko wyrzucamy w całości — polecenie urwane w połowie ścieżki
	 * wygląda na gotowe do wklejenia, a zrobi co innego, niż mówi opis.
	 *
	 * @param mixed $value
	 *
	 * @return string|null
	 */
	public static function command($value) {
		$clean = self::text($value, self::MAX_COMMAND + 1);

		return (null !== $clean && mb_strlen($clean) <= self::MAX_COMMAND && preg_match('/^[\x20-\x7E]+$/', $clean))
			? $clean : null;
	}

	/**
	 * Polecenie zmiany praw dostępu jako przykład do skopiowania. Ścieżki podajemy
	 * względem katalogu strony, bo tam stoi ten, kto to wklei. Gdy komplet nie mieści
	 * się w limicie, zostaje pierwsza ścieżka — przykład na jednym pliku jest uczciwszy
	 * niż lista ucięta w połowie nazwy.
	 *
	 * @param array<int, string> $paths
	 *
	 * @return string|null
	 */
	public static function chmod_command(string $mode, array $paths, bool $recursive = false) {
		$paths = array_values(array_filter($paths, static function ($path) { return '' !== $path; }));
		if (array() === $paths) {
			return null;
		}
		$build = static function (array $list) use ($mode, $recursive) {
			$quoted = array_map(array(__CLASS__, 'quote_path'), $list);
			$command = 'chmod '.($recursive ? '-R ' : '').$mode.' '.implode(' ', $quoted);

			return mb_strlen($command) <= self::MAX_COMMAND ? $command : null;
		};

		$full = $build($paths);

		return null !== $full ? $full : $build(array($paths[0]));
	}

	/** Cudzysłowy tylko tam, gdzie są potrzebne — zwykła ścieżka ma zostać czytelna. */
	public static function quote_path(string $path): string {
		if (preg_match('#^[A-Za-z0-9._/-]+$#', $path)) {
			return $path;
		}

		return "'".str_replace("'", "'\\''", $path)."'";
	}

	private static function text($value, int $max) {
		if (!is_string($value) && !is_numeric($value)) {
			return null;
		}
		$clean = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $value)));

		return '' === $clean ? null : mb_substr($clean, 0, $max);
	}
}

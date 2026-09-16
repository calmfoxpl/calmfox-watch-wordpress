<?php
defined('ABSPATH') || exit;

/**
 * Ekran „Calmfox Watch" w menu głównym kokpitu: dwie ścieżki startu (aktywacja Free
 * wprost z wtyczki albo parowanie tokenem z panelu), a po połączeniu podgląd
 * zdrowia, higieny bezpieczeństwa i historii aktualizacji + rotacja sekretu.
 */
final class Calmfox_Watch_Admin {

	const CAP = 'manage_options';

	public static function boot(): void {
		add_action('admin_menu', array(__CLASS__, 'menu'));
		add_action('admin_init', array(__CLASS__, 'redirect_legacy_screen'));
		add_action('wp_dashboard_setup', array(__CLASS__, 'dashboard_widget'));
		add_action('admin_post_calmfox_watch_register', array(__CLASS__, 'handle_register'));
		add_action('admin_post_calmfox_watch_connect', array(__CLASS__, 'handle_connect'));
		add_action('admin_init', array(__CLASS__, 'maybe_finish_connect'));
		add_action('admin_post_calmfox_watch_pair', array(__CLASS__, 'handle_pair'));
		add_action('admin_post_calmfox_watch_rotate', array(__CLASS__, 'handle_rotate'));
		add_action('admin_post_calmfox_watch_repair', array(__CLASS__, 'handle_repair'));
		add_action('admin_post_calmfox_watch_quota', array(__CLASS__, 'handle_quota'));
		add_action('admin_post_calmfox_watch_disconnect', array(__CLASS__, 'handle_disconnect'));
		add_action('admin_notices', array(__CLASS__, 'notices'));
		add_filter('plugin_action_links_'.plugin_basename(CALMFOX_WATCH_FILE), array(__CLASS__, 'action_links'));
		add_filter('plugin_row_meta', array(__CLASS__, 'row_meta'), 10, 2);
	}

	/**
	 * Pozycja w MENU GŁÓWNYM kokpitu, zaraz pod „Kokpitem", a nie w Ustawieniach.
	 * Monitoring, do którego trzeba się doklikać przez Ustawienia, ogląda wyłącznie
	 * ten, kto go szuka — a ma go zobaczyć ktoś, kto wszedł do kokpitu po czymś
	 * zupełnie innym i właśnie mija awarię.
	 */
	public static function menu(): void {
		$hook = add_menu_page('Calmfox Watch', 'Calmfox Watch', self::CAP, 'calmfox-watch', array(__CLASS__, 'render'), self::menu_icon(), 3.7);
		add_action('admin_print_styles-'.$hook, array(__CLASS__, 'enqueue_style'));
		// Kafelek na pulpicie korzysta z tego samego arkusza, a pulpit to index.php.
		add_action('admin_print_styles-index.php', array(__CLASS__, 'enqueue_style'));
	}

	public static function enqueue_style(): void {
		wp_enqueue_style('calmfox-watch-admin', plugins_url('assets/admin.css', CALMFOX_WATCH_FILE), array(), CALMFOX_WATCH_VERSION);
	}

	/** Adres ekranu wtyczki. Jedno miejsce, bo ekran przeprowadził się z Ustawień do menu głównego. */
	public static function screen_url(): string {
		return admin_url('admin.php?page=calmfox-watch');
	}

	/**
	 * Znak marki jako ikona menu. Wprost z pliku spakowanego we wtyczce, bo ikona
	 * ma się rysować także wtedy, gdy strona nie ma dostępu do sieci; gdyby pliku
	 * zabrakło, WordPress dostaje nazwę ikony systemowej i menu nadal wygląda jak menu.
	 */
	private static function menu_icon(): string {
		$file = plugin_dir_path(CALMFOX_WATCH_FILE).'assets/mark.svg';
		$svg  = is_readable($file) ? (string) file_get_contents($file) : '';

		return '' !== $svg ? 'data:image/svg+xml;base64,'.base64_encode($svg) : 'dashicons-heart';
	}

	/**
	 * Ekran wisiał w „Ustawieniach", więc stary adres siedzi w zakładkach i w linkach
	 * z naszych własnych maili. Po przeprowadzce WordPress odpowiedziałby tam „brak
	 * uprawnień", co wygląda na awarię wtyczki. Przekierowanie zachowuje parametry,
	 * bo tą samą drogą wraca łączenie przez panel (?cw_token=…&cw_state=…).
	 */
	public static function redirect_legacy_screen(): void {
		global $pagenow;

		if ('options-general.php' !== $pagenow || 'calmfox-watch' !== ($_GET['page'] ?? '')) {
			return;
		}
		$args = array();
		foreach (array('cw_token', 'cw_state') as $key) {
			if (isset($_GET[$key])) {
				$args[$key] = sanitize_text_field((string) wp_unslash($_GET[$key]));
			}
		}
		wp_safe_redirect(add_query_arg($args, self::screen_url()));
		exit;
	}

	/** @param array<string> $links */
	public static function action_links($links): array {
		$links[] = '<a href="'.esc_url(self::screen_url()).'">'.esc_html__('Ustawienia', 'calmfox-watch').'</a>';

		return $links;
	}

	/**
	 * Wiersz wtyczki na liście: skrót do panelu i do stanu połączenia —
	 * marka w miejscu, w którym administrator ogląda wszystkie wtyczki naraz.
	 *
	 * @param array<string> $meta
	 * @param string        $file
	 *
	 * @return array<string>
	 */
	public static function row_meta($meta, $file): array {
		if (plugin_basename(CALMFOX_WATCH_FILE) !== $file) {
			return $meta;
		}
		$meta[] = '<a href="'.esc_url(Calmfox_Watch_Settings::panel_url()).'" target="_blank" rel="noopener">'.esc_html__('Panel Calmfox Watch', 'calmfox-watch').'</a>';
		$meta[] = Calmfox_Watch_Settings::get('connected')
			? '<span style="color:#116329;">'.esc_html__('połączona', 'calmfox-watch').'</span>'
			: '<span style="color:#996800;">'.esc_html__('niepołączona', 'calmfox-watch').'</span>';

		return $meta;
	}

	// ── Akcje (admin-post) ───────────────────────────────────────────────

	public static function handle_register(): void {
		self::guard('calmfox_watch_register');

		$email   = sanitize_email((string) ($_POST['email'] ?? ''));
		$consent = !empty($_POST['consent']);
		if (!is_email($email)) {
			self::finish('error', __('Podaj poprawny adres e-mail.', 'calmfox-watch'));
		}
		if (!$consent) {
			self::finish('error', __('Do aktywacji potrzebna jest zgoda na przekazanie adresu e-mail i domeny do Calmfox.', 'calmfox-watch'));
		}

		$result = Calmfox_Watch_Hub::register($email);
		self::finish($result['ok'] ? 'success' : 'error', $result['message']);
	}

	/**
	 * Connect flow: przekierowanie do panelu Watch — tam logowanie/rejestracja
	 * i wybór organizacji, a wracamy z tokenem (maybe_finish_connect dokańcza).
	 * Znacznik stanu chroni przed podrzuceniem cudzego tokenu w callbacku.
	 */
	public static function handle_connect(): void {
		self::guard('calmfox_watch_connect');

		$state = wp_generate_password(20, false, false);
		set_transient('calmfox_watch_connect_state', $state, 15 * MINUTE_IN_SECONDS);

		$url = add_query_arg(array(
			'domain' => rawurlencode((string) wp_parse_url(home_url(), PHP_URL_HOST)),
			'return' => rawurlencode(self::screen_url()),
			'state'  => $state,
		), Calmfox_Watch_Settings::panel_url().'/polacz/wp');

		wp_redirect($url); // celowo nie wp_safe_redirect: cel to nasz panel, nie ta instalacja
		exit;
	}

	/** Powrót z panelu: ?cw_token=…&cw_state=… → walidacja stanu i automatyczne parowanie. */
	public static function maybe_finish_connect(): void {
		if (!isset($_GET['cw_token'], $_GET['cw_state']) || 'calmfox-watch' !== ($_GET['page'] ?? '')) {
			return;
		}
		if (!current_user_can(self::CAP)) {
			return;
		}
		$expected = (string) get_transient('calmfox_watch_connect_state');
		delete_transient('calmfox_watch_connect_state');

		$state = (string) wp_unslash($_GET['cw_state']);
		$token = (string) wp_unslash($_GET['cw_token']);
		if ('' === $expected || !hash_equals($expected, $state)) {
			self::finish('error', __('Znacznik połączenia wygasł albo się nie zgadza — kliknij „Połącz przez watch.calmfox.net” jeszcze raz.', 'calmfox-watch'));
		}
		if (!preg_match('/^(fxp_live_)?[a-f0-9]{16,32}$/', $token)) {
			self::finish('error', __('Token z panelu ma zły format — spróbuj ponownie.', 'calmfox-watch'));
		}

		$result = Calmfox_Watch_Hub::pair($token);
		self::finish($result['ok'] ? 'success' : 'error', $result['ok']
			? __('Połączono z Calmfox Watch — monitoring wnętrza WordPressa działa.', 'calmfox-watch')
			: $result['message']);
	}

	public static function handle_pair(): void {
		self::guard('calmfox_watch_pair');

		$token = trim((string) ($_POST['token'] ?? ''));
		if (!preg_match('/^(fxp_live_)?[a-f0-9]{16,32}$/', $token)) {
			self::finish('error', __('Klucz ma inny format niż fxp_live_… — skopiuj go z ekranu Integracje w panelu.', 'calmfox-watch'));
		}

		$result = Calmfox_Watch_Hub::pair($token);
		self::finish($result['ok'] ? 'success' : 'error', $result['message']);
	}

	/** Rotacja sekretu: nowy klucz w URL-u + przepięcie huba (stary działa jeszcze 15 minut). */
	public static function handle_rotate(): void {
		self::guard('calmfox_watch_rotate');

		Calmfox_Watch_Settings::rotate_secret();
		$result = Calmfox_Watch_Hub::pair();
		self::finish($result['ok'] ? 'success' : 'error', $result['ok']
			? __('Klucz zabezpieczający wymieniony — monitoring korzysta już z nowego adresu.', 'calmfox-watch')
			/* translators: %s: błąd */
			: sprintf(__('Klucz wymieniono na stronie, ale nie udało się zaktualizować go w panelu: %s Poprzedni klucz działa jeszcze 15 minut — użyj przycisku „Połącz ponownie”.', 'calmfox-watch'), $result['message']));
	}

	/** Naprawa (albo cofnięcie) pojedynczej pozycji z listy sprawdzeń. */
	public static function handle_repair(): void {
		self::guard('calmfox_watch_repair');

		$check  = sanitize_key((string) ($_POST['check'] ?? ''));
		$revert = !empty($_POST['revert']);
		if (!Calmfox_Watch_Repairs::can_fix($check)) {
			self::finish('error', __('Nieznana naprawa.', 'calmfox-watch'));
		}

		$result = $revert ? Calmfox_Watch_Repairs::revert($check) : Calmfox_Watch_Repairs::run($check);
		// Wynik ma być widoczny od razu, więc kasujemy cache sekcji.
		delete_transient(Calmfox_Watch_Endpoint::HEALTH_CACHE);
		delete_transient(Calmfox_Watch_Endpoint::SECURITY_CACHE);

		self::finish($result['ok'] ? 'success' : 'error', $result['message']);
	}

	/** Limit dyskowy konta podany przez klienta (hosting współdzielony go nie ujawnia). */
	public static function handle_quota(): void {
		self::guard('calmfox_watch_quota');

		$raw   = str_replace(',', '.', (string) ($_POST['quota'] ?? ''));
		$quota = '' === trim($raw) ? 0.0 : (float) $raw;
		if ($quota < 0 || $quota > 100000) {
			self::finish('error', __('Podaj limit w gigabajtach — liczbę z zakresu 0–100000 (0 = nie znam limitu).', 'calmfox-watch'));
		}

		Calmfox_Watch_Settings::update(array('disk_quota_gb' => $quota));
		delete_transient(Calmfox_Watch_Endpoint::HEALTH_CACHE);
		delete_transient(Calmfox_Watch_Checks::SIZE_CACHE);

		self::finish('success', $quota > 0
			/* translators: %s: limit */
			? sprintf(__('Zapisane — pilnujemy zajętości względem %s.', 'calmfox-watch'), size_format($quota * GB_IN_BYTES))
			: __('Wyczyszczone — wróciliśmy do informowania, że limit konta nie jest znany.', 'calmfox-watch'));
	}

	public static function handle_disconnect(): void {
		self::guard('calmfox_watch_disconnect');

		Calmfox_Watch_Hub::disconnect();
		Calmfox_Watch_Settings::update(array('connected' => false));
		// Ocena sprzed rozłączenia opisuje stronę, której już nie pilnujemy.
		Calmfox_Watch_Score::forget();
		self::finish('success', __('Połączenie zakończone — monitoring stanu WordPressa został wstrzymany. Klucz pozostaje zapisany, więc ponowne połączenie zajmie jedno kliknięcie.', 'calmfox-watch'));
	}

	// ── Widok ────────────────────────────────────────────────────────────

	public static function render(): void {
		if (!current_user_can(self::CAP)) {
			return;
		}
		$connected = (bool) Calmfox_Watch_Settings::get('connected');

		echo '<div class="wrap calmfox-watch">';
		echo '<div class="cw-header">';
		echo '<img class="cw-logo cw-logo-light" src="'.esc_url(plugins_url('assets/logo-light.svg', CALMFOX_WATCH_FILE)).'" alt="Calmfox Watch" width="226" height="54">';
		echo '<img class="cw-logo cw-logo-dark" src="'.esc_url(plugins_url('assets/logo-dark.svg', CALMFOX_WATCH_FILE)).'" alt="" aria-hidden="true" width="226" height="54">';
		echo '<span class="cw-version">'.esc_html(sprintf(__('wersja %s', 'calmfox-watch'), CALMFOX_WATCH_VERSION)).'</span>';
		echo '</div>';
		echo '<h1 class="screen-reader-text">Calmfox Watch</h1>';
		if (defined('CALMFOX_WATCH_API_URL')) {
			echo '<p class="cw-dev">'.esc_html(sprintf(__('Środowisko testowe — API: %s', 'calmfox-watch'), calmfox_watch_api_url())).'</p>';
		}

		if ($connected) {
			self::render_connected();
		} else {
			self::render_setup();
		}
		echo '</div>';
	}

	private static function render_setup(): void {
		echo '<p class="cw-lead">'.esc_html__('Monitoring wnętrza WordPressa: dostępność usług (baza danych, poczta, dysk, zadania cykliczne, pamięć podręczna), podstawowe zasady bezpieczeństwa i historia aktualizacji — wyniki znajdziesz w panelu Calmfox Watch.', 'calmfox-watch').'</p>';

		$loopback = Calmfox_Watch_Hub::loopback_check();
		if (!$loopback['ok']) {
			echo '<div class="notice notice-warning inline"><p>'.esc_html(sprintf(
				/* translators: %s: opis problemu */
				__('Uwaga: sprawdzenie wykonane z serwera nie dociera do adresu kontrolnego wtyczki (%s). Jeżeli połączenie się nie powiedzie, sprawdź, czy wtyczka zabezpieczająca albo zapora sieciowa nie blokuje ścieżki /wp-json/.', 'calmfox-watch'),
				$loopback['message']
			)).'</p></div>';
		}
		if ('https' !== wp_parse_url(home_url(), PHP_URL_SCHEME) && 0 === strpos(calmfox_watch_api_url(), 'https://')) {
			echo '<div class="notice notice-error inline"><p>'.esc_html__('Strona działa po protokole HTTP, a Calmfox Watch łączy się wyłącznie z adresami HTTPS — włącz certyfikat SSL, aby nawiązać połączenie.', 'calmfox-watch').'</p></div>';
		}

		echo '<div class="cw-columns">';

		// Ścieżka 1: nowe konto Free
		echo '<div class="card cw-card"><h2>'.esc_html__('Nie mam konta — załóż pakiet Free', 'calmfox-watch').'</h2>';
		echo '<p>'.esc_html__('Konto zakładamy od razu: monitoring dostępności strony i certyfikatu SSL oraz stanu WordPressa. Bez hasła — logowanie linkiem wysyłanym na adres e-mail.', 'calmfox-watch').'</p>';
		echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
		wp_nonce_field('calmfox_watch_register');
		echo '<input type="hidden" name="action" value="calmfox_watch_register">';
		echo '<p><label for="cw-email">'.esc_html__('Adres e-mail właściciela', 'calmfox-watch').'</label><br>';
		echo '<input type="email" id="cw-email" name="email" class="regular-text" required value="'.esc_attr((string) get_option('admin_email')).'"></p>';
		echo '<p><label><input type="checkbox" name="consent" value="1" required> ';
		echo wp_kses(sprintf(
			/* translators: %s: link do Calmfox */
			__('Zgadzam się na przekazanie adresu e-mail i domeny strony do <a href="%s" target="_blank" rel="noopener">Calmfox</a> w celu założenia konta monitoringu.', 'calmfox-watch'),
			'https://calmfox.pl'
		), array('a' => array('href' => array(), 'target' => array(), 'rel' => array())));
		echo '</label></p>';
		echo '<p><button type="submit" class="button button-primary">'.esc_html__('Aktywuj pakiet Free', 'calmfox-watch').'</button></p>';
		echo '</form></div>';

		// Ścieżka 2: istniejące konto — panel wyda token i wrócimy tu automatycznie.
		echo '<div class="card cw-card"><h2>'.esc_html__('Mam już konto — połącz przez panel', 'calmfox-watch').'</h2>';
		echo '<p>'.esc_html__('Przejdziesz do watch.calmfox.net (logowanie albo rejestracja), wybierzesz organizację, a my wrócimy tu z tokenem — wtyczka sparuje się sama. Strona nie musi wcześniej istnieć w panelu.', 'calmfox-watch').'</p>';
		echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
		wp_nonce_field('calmfox_watch_connect');
		echo '<input type="hidden" name="action" value="calmfox_watch_connect">';
		echo '<p><button type="submit" class="button button-primary">'.esc_html__('Połącz przez watch.calmfox.net', 'calmfox-watch').'</button></p>';
		echo '</form>';
		echo '<details class="cw-fallback"><summary>'.esc_html__('Wolisz wkleić token ręcznie?', 'calmfox-watch').'</summary>';
		echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
		wp_nonce_field('calmfox_watch_pair');
		echo '<input type="hidden" name="action" value="calmfox_watch_pair">';
		echo '<p><label for="cw-token">'.esc_html__('Token instalacji (ekran Integracje w panelu)', 'calmfox-watch').'</label><br>';
		echo '<input type="text" id="cw-token" name="token" class="regular-text code" placeholder="fxp_live_…" required></p>';
		echo '<p><button type="submit" class="button">'.esc_html__('Połącz tokenem', 'calmfox-watch').'</button></p>';
		echo '</form></details></div>';

		echo '</div>';
	}

	/**
	 * Kafelek na pulpicie kokpitu. Wchodzi tam, gdzie człowiek ląduje po
	 * zalogowaniu, i mówi jedno z dwóch: „nic nie wymaga uwagi" albo „to jest
	 * zepsute". Pełna tabela zostaje na ekranie wtyczki — tutaj mieszczą się
	 * trzy najpilniejsze rzeczy i liczba pozostałych.
	 */
	public static function dashboard_widget(): void {
		if (!current_user_can(self::CAP)) {
			return;
		}
		wp_add_dashboard_widget('calmfox_watch_health', __('Calmfox Watch: kondycja strony', 'calmfox-watch'), array(__CLASS__, 'render_dashboard_widget'));
	}

	public static function render_dashboard_widget(): void {
		echo '<div class="calmfox-watch cw-widget">';

		if (!Calmfox_Watch_Settings::get('connected')) {
			echo '<p>'.esc_html__('Monitoring wnętrza strony nie jest jeszcze połączony z panelem. Sprawdzenia działają lokalnie, ale nikt o nich nie wie poza tym ekranem.', 'calmfox-watch').'</p>';
			echo '<p><a class="button button-primary" href="'.esc_url(self::screen_url()).'">'.esc_html__('Połącz z Calmfox Watch', 'calmfox-watch').'</a></p></div>';

			return;
		}

		$health   = Calmfox_Watch_Endpoint::payload('health');
		$security = Calmfox_Watch_Endpoint::payload('security');
		$summary  = self::summary($health, $security);

		echo '<p class="cw-widget-head">'.self::badge($summary['status']).' ';
		echo esc_html(sprintf(
			/* translators: 1: liczba sprawdzeń bez zastrzeżeń, 2: liczba ostrzeżeń, 3: liczba awarii */
			__('Sprawdzenia: %1$d w porządku, %2$d z ostrzeżeniem, %3$d z awarią.', 'calmfox-watch'),
			$summary['counts']['ok'], $summary['counts']['warn'], $summary['counts']['fail']
		));
		$last_poll = (int) Calmfox_Watch_Settings::get('last_poll_at');
		if ($last_poll > 0) {
			/* translators: %s: ile czasu temu */
			echo ' '.esc_html(sprintf(__('Ostatnie odpytanie monitoringu: %s temu.', 'calmfox-watch'), human_time_diff($last_poll)));
		}
		echo '</p>';

		if (empty($summary['problems'])) {
			echo '<p>'.esc_html__('Nic nie wymaga uwagi.', 'calmfox-watch').'</p>';
		} else {
			echo '<ul class="cw-widget-list">';
			foreach ($summary['problems'] as $problem) {
				echo '<li>'.self::badge((string) $problem['status']).' <strong>'.esc_html((string) ($problem['label'] ?? $problem['id'])).'</strong>';
				if (!empty($problem['detail'])) {
					echo '<br><span class="cw-detail">'.esc_html((string) $problem['detail']).'</span>';
				}
				echo '</li>';
			}
			echo '</ul>';
			$rest = $summary['total'] - count($summary['problems']);
			if ($rest > 0) {
				/* translators: %d: liczba pozostałych spraw */
				echo '<p class="cw-detail">'.esc_html(sprintf(__('Dalszych spraw do przejrzenia: %d.', 'calmfox-watch'), $rest)).'</p>';
			}
		}

		// Parametry monitoringu: co na tej stronie w ogóle pilnujemy. Bez tej listy
		// „nic nie wymaga uwagi" nie mówi, CZEGO właściwie nic nie wymaga.
		$checks = isset($health['checks']) && is_array($health['checks']) ? $health['checks'] : array();
		if (!empty($checks)) {
			echo '<p class="cw-detail cw-widget-params-label">'.esc_html__('Parametry monitoringu na tej stronie:', 'calmfox-watch').'</p>';
			echo '<div class="cw-widget-params">';
			foreach ($checks as $check) {
				$status = isset($check['status']) ? (string) $check['status'] : 'ok';
				echo '<span class="cw-widget-param" title="'.esc_attr((string) ($check['detail'] ?? '')).'">';
				echo '<span class="cw-widget-dot cw-widget-dot-'.esc_attr($status).'"></span>';
				echo esc_html((string) ($check['label'] ?? $check['id'])).'</span>';
			}
			echo '</div>';
		}

		// Pakiet: na Free zapraszamy wyżej, ale mówimy wprost, co Free daje, a czego nie.
		$plan = (string) Calmfox_Watch_Settings::get('plan');
		if ('' === $plan || 'free' === strtolower($plan)) {
			echo '<div class="cw-widget-plan"><span class="cw-detail">';
			echo esc_html__('Strona jest w pakiecie Free: sprawdzenia strony głównej i kontrola certyfikatu. Wyższy próg dokłada częstsze sondy, przegląd podstron i powiadomienia bez limitu.', 'calmfox-watch');
			echo '</span></div>';
		}

		echo '<p><a class="button" href="'.esc_url(self::screen_url()).'">'.esc_html__('Otwórz Calmfox Watch', 'calmfox-watch').'</a>';
		if ('' === $plan || 'free' === strtolower($plan)) {
			echo ' <a class="button button-primary" href="'.esc_url(Calmfox_Watch_Settings::panel_link('/app/plan')).'" target="_blank" rel="noopener">'.esc_html__('Zobacz pakiety', 'calmfox-watch').'</a>';
		}
		echo '</p></div>';
	}

	/**
	 * Skrót obu sekcji: liczby i najpilniejsze problemy. Kolejność jest
	 * kolejnością pilności, nie kolejnością sekcji: najpierw awarie, potem
	 * ostrzeżenia, a w obrębie wagi zostaje kolejność sprawdzeń, czyli stan
	 * usług przed higieną bezpieczeństwa.
	 *
	 * @param array<string, mixed> $health
	 * @param array<string, mixed> $security
	 *
	 * @return array{status: string, counts: array<string, int>, problems: array<int, array<string, mixed>>, total: int}
	 */
	private static function summary(array $health, array $security): array {
		$counts   = array('ok' => 0, 'warn' => 0, 'fail' => 0);
		$problems = array('fail' => array(), 'warn' => array());

		foreach (array($health, $security) as $payload) {
			$checks = isset($payload['checks']) && is_array($payload['checks']) ? $payload['checks'] : array();
			foreach ($checks as $check) {
				$status = isset($check['status']) ? (string) $check['status'] : '';
				if (!isset($counts[$status])) {
					continue;
				}
				++$counts[$status];
				if ('ok' !== $status) {
					$problems[$status][] = $check;
				}
			}
		}

		$ordered = array_merge($problems['fail'], $problems['warn']);

		return array(
			'status'   => $counts['fail'] > 0 ? 'fail' : ($counts['warn'] > 0 ? 'warn' : 'ok'),
			'counts'   => $counts,
			'problems' => array_slice($ordered, 0, 3),
			// Liczba wszystkich problemów, żeby kafelek mógł uczciwie powiedzieć
			// „i tyle dalszych" zamiast udawać, że lista jest kompletna.
			'total'    => count($ordered),
		);
	}

	private static function render_connected(): void {
		$plan      = (string) Calmfox_Watch_Settings::get('plan');
		$last_poll = (int) Calmfox_Watch_Settings::get('last_poll_at');

		echo '<div class="card cw-card cw-status"><h2>'.esc_html__('Połączono z Calmfox Watch', 'calmfox-watch').'</h2><p>';
		/* translators: 1: domena, 2: pakiet */
		echo esc_html(sprintf(__('Strona %1$s · pakiet %2$s.', 'calmfox-watch'), (string) wp_parse_url(home_url(), PHP_URL_HOST), '' !== $plan ? ucfirst($plan) : '—')).' ';
		echo $last_poll > 0
			/* translators: %s: ile czasu temu */
			? esc_html(sprintf(__('Ostatnie odpytanie monitoringu: %s temu.', 'calmfox-watch'), human_time_diff($last_poll)))
			: esc_html__('Monitoring jeszcze nie odpytał endpointu — pierwsze sprawdzenie w ciągu kilku minut.', 'calmfox-watch');
		echo '</p><p><a class="button button-primary" href="'.esc_url(Calmfox_Watch_Settings::panel_link()).'" target="_blank" rel="noopener">'.esc_html__('Otwórz tę stronę w panelu', 'calmfox-watch').'</a> ';

		echo '<a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=calmfox_watch_rotate'), 'calmfox_watch_rotate')).'" onclick="return confirm('.esc_attr(wp_json_encode(__('Wymienić klucz zabezpieczający adres kontrolny? Monitoring zostanie przełączony na nowy adres.', 'calmfox-watch'))).');">'.esc_html__('Wymień klucz', 'calmfox-watch').'</a> ';
		echo '<a class="button cw-danger" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=calmfox_watch_disconnect'), 'calmfox_watch_disconnect')).'" onclick="return confirm('.esc_attr(wp_json_encode(__('Zakończyć monitoring wnętrza WordPressa? Sprawdzenia w panelu zostaną wstrzymane.', 'calmfox-watch'))).');">'.esc_html__('Rozłącz', 'calmfox-watch').'</a>';
		echo '</p></div>';

		self::render_score_card();
		self::render_plan_card($plan);

		$health = Calmfox_Watch_Endpoint::payload('health');
		self::render_checks(__('Stan usług', 'calmfox-watch'), $health['status'], $health['checks']);

		$security = Calmfox_Watch_Endpoint::payload('security');
		self::render_checks(__('Bezpieczeństwo', 'calmfox-watch'), $security['status'], $security['checks']);

		self::render_history(array_slice(Calmfox_Watch_History::all(), 0, 10));
	}

	/**
	 * Kondycja strony liczona przez panel: ocena 0–100 z pięciu obszarów wraz z POKRYCIEM
	 * pomiaru. Liczba nigdy nie stoi tu sama — ocena z dwóch obszarów i z pięciu to nie ta
	 * sama wiadomość, a ten ekran ogląda się bez dostępu do panelu.
	 *
	 * Szczegółów odjęć tu nie ma i mieć nie będzie (patrz Calmfox_Watch_Score): ekran
	 * administracyjny widzi też ten, kto stronę przejął.
	 */
	private static function render_score_card(): void {
		$score = Calmfox_Watch_Score::get();
		if (null === $score) {
			return;
		}

		echo '<div class="card cw-card cw-score">';
		echo '<h2>'.esc_html__('Kondycja strony', 'calmfox-watch').'</h2>';
		echo '<p class="cw-score-value '.esc_attr(Calmfox_Watch_Score::tone_class((string) ($score['tone'] ?? 'muted'))).'">';
		echo esc_html((string) ($score['overall'] ?? '—')).'<span class="cw-score-max">/100</span></p>';

		$coverage = (int) ($score['coverage'] ?? 0);
		$measured = 0;
		$areas    = is_array($score['areas'] ?? null) ? $score['areas'] : array();
		foreach ($areas as $area) {
			if (!empty($area['measured']) && empty($area['stale'])) {
				++$measured;
			}
		}
		echo '<p class="cw-score-meta">'.esc_html(sprintf(
			/* translators: 1: ile obszarow zmierzonych, 2: ile obszarow w ogole, 3: podpis pasma */
			__('ocena z %1$d z %2$d obszarów · %3$s', 'calmfox-watch'),
			$measured,
			max(1, count($areas)),
			(string) ($score['bandLabel'] ?? '')
		)).'</p>';

		if (empty($score['complete'])) {
			echo '<p class="cw-score-note">'.esc_html__('Ocena niepełna: obszarów, których nie mierzymy, nie liczymy jako zero — one zwykle wynik obniżają. Co je włącza, piszemy przy każdym obszarze.', 'calmfox-watch').'</p>';
		}
		if (!empty($score['cap']['reason'])) {
			echo '<p class="cw-score-cap">'.esc_html((string) $score['cap']['reason']).'</p>';
		}

		echo '<ul class="cw-score-areas">';
		foreach ($areas as $area) {
			$known = !empty($area['measured']) && empty($area['stale']);
			echo '<li><span class="cw-score-area">'.esc_html((string) ($area['label'] ?? '')).'</span>';
			echo '<span class="cw-score-points">'.($known ? esc_html((string) $area['score']) : esc_html__('nie mierzymy', 'calmfox-watch')).'</span>';
			if (!$known && !empty($area['missingGroups'])) {
				$unlocks = array();
				foreach ($area['missingGroups'] as $group) {
					if (!empty($group['unlock'])) {
						$unlocks[] = (string) $group['unlock'];
					}
				}
				if (array() !== $unlocks) {
					echo '<span class="cw-score-unlock">'.esc_html(implode(' · ', $unlocks)).'</span>';
				}
			}
			echo '</li>';
		}
		echo '</ul>';

		echo '<p><a class="button" href="'.esc_url(Calmfox_Watch_Settings::panel_link()).'" target="_blank" rel="noopener">'.esc_html__('Zobacz, co zabiera punkty', 'calmfox-watch').'</a></p>';
		echo '</div>';
	}

	/**
	 * Pakiet strony i droga wyżej. Zakup dzieje się w panelu (PayU, rozliczenie
	 * per strona) — wtyczka tylko prowadzi do właściwego miejsca w kontekście
	 * TEJ strony. Pakiet pokazujemy z zastrzeżeniem, bo znamy go z chwili
	 * połączenia; źródłem prawdy jest panel.
	 */
	private static function render_plan_card(string $plan): void {
		$is_free = '' === $plan || 'free' === $plan;
		// Panel ma osobny ekran pakietu; „/app/settings" to progi i okna serwisowe,
		// więc przycisk „Zobacz pakiety" lądował dotąd obok tego, co obiecywał.
		$link    = Calmfox_Watch_Settings::panel_link('/app/plan');

		echo '<div class="card cw-card cw-plan">';
		echo '<h2>'.esc_html($is_free ? __('Pakiet Free — co dokłada wyższy próg', 'calmfox-watch') : __('Pakiet i płatności', 'calmfox-watch')).'</h2>';

		if ($is_free) {
			echo '<p>'.esc_html__('Na Free pilnujemy dostępności strony, certyfikatu i zdrowia WordPressa. Wyższe pakiety dokładają to, czego Free nie obejmuje:', 'calmfox-watch').'</p>';
			echo '<ul class="cw-plan-list">';
			foreach (array(
				__('sondy co 30–60 s zamiast co 5 minut — awarię widać od razu, nie po kwadransie', 'calmfox-watch'),
				__('pełny crawl podstron: urwane treści, błędy 404 i zepsute linki zanim zauważy je klient', 'calmfox-watch'),
				__('monitoring wybranych podstron i formularzy, nie tylko strony głównej', 'calmfox-watch'),
				__('powiadomienia SMS i Telegram bez miesięcznego limitu', 'calmfox-watch'),
				__('raporty okresowe i dłuższa historia statystyk', 'calmfox-watch'),
				__('ocena kondycji strony: jedna liczba 0–100 z pięciu obszarów, z listą tego, co zabiera punkty', 'calmfox-watch'),
			) as $item) {
				echo '<li>'.esc_html($item).'</li>';
			}
			echo '</ul>';
			echo '<p><a class="button button-primary" href="'.esc_url($link).'" target="_blank" rel="noopener">'.esc_html__('Zobacz pakiety dla tej strony', 'calmfox-watch').'</a></p>';
			echo '<p class="cw-detail">'.esc_html__('Pakiet kupujesz w panelu (płatność kartą, subskrypcja miesięczna albo roczna) — osobno dla każdej strony, więc landing może zostać na Free, a sklep pójść wyżej.', 'calmfox-watch').'</p>';
		} else {
			echo '<p>'.esc_html(sprintf(
				/* translators: %s: nazwa pakietu */
				__('Ta strona jest w pakiecie %s (stan z chwili połączenia — bieżący pakiet i płatności zobaczysz w panelu).', 'calmfox-watch'), ucfirst($plan))).'</p>';
			echo '<p><a class="button" href="'.esc_url($link).'" target="_blank" rel="noopener">'.esc_html__('Zarządzaj pakietem i płatnościami', 'calmfox-watch').'</a></p>';
		}
		echo '</div>';
	}

	/** @param array<int, array<string, mixed>> $checks */
	private static function render_checks(string $title, string $status, array $checks): void {
		echo '<div class="card cw-card"><h2>'.esc_html($title).' '.self::badge($status).'</h2>';
		if (empty($checks)) {
			echo '<p>'.esc_html__('Brak sprawdzeń do pokazania.', 'calmfox-watch').'</p></div>';

			return;
		}
		$catalog = Calmfox_Watch_Repairs::catalog();
		echo '<table class="widefat striped cw-table"><tbody>';
		foreach ($checks as $check) {
			$id = (string) $check['id'];
			echo '<tr><td class="cw-dot-cell">'.self::badge((string) $check['status']).'</td>';
			echo '<td><strong>'.esc_html((string) ($check['label'] ?? $id)).'</strong>';
			if (!empty($check['detail'])) {
				echo '<br><span class="cw-detail">'.esc_html((string) $check['detail']).'</span>';
			}
			// Konkret naprawczy: co ma być ustawione i przykładowe polecenie. Polecenie
			// zostaje zaznaczalne w osobnym bloku, bo zwykle idzie stąd prosto do
			// terminala albo do wiadomości do hostingodawcy.
			if (!empty($check['fix'])) {
				echo '<div class="cw-fix"><span class="cw-fix-label">'.esc_html__('Jak naprawić', 'calmfox-watch').'</span>';
				echo '<span class="cw-detail">'.esc_html((string) $check['fix']).'</span>';
				if (!empty($check['command'])) {
					echo '<code class="cw-fix-command">'.esc_html((string) $check['command']).'</code>';
					echo '<span class="cw-detail">'.esc_html__('Przykład do wykonania na serwerze, w katalogu strony. Sprawdź go u siebie przed użyciem.', 'calmfox-watch').'</span>';
				}
				echo '</div>';
			}
			// Naprawa tylko przy realnym problemie — przy „ok" nie ma czego naprawiać.
			if (isset($catalog[$id]) && 'ok' !== $check['status']) {
				echo '<div class="cw-repair"><span class="cw-detail">'.esc_html($catalog[$id]['note']).'</span> ';
				echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
				wp_nonce_field('calmfox_watch_repair');
				echo '<input type="hidden" name="action" value="calmfox_watch_repair">';
				echo '<input type="hidden" name="check" value="'.esc_attr($id).'">';
				echo '<button type="submit" class="button button-secondary">'.esc_html($catalog[$id]['label']).'</button>';
				echo '</form></div>';
			}
			// Naprawy odwracalne: gdy już włączone, dajemy uczciwą drogę powrotu.
			if (('xmlrpc' === $id && Calmfox_Watch_Settings::get('disable_xmlrpc'))
				|| ('file_editor' === $id && Calmfox_Watch_Settings::get('disallow_file_edit'))) {
				echo '<div class="cw-repair"><span class="cw-detail">'.esc_html__('Naprawione przez wtyczkę.', 'calmfox-watch').'</span> ';
				echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
				wp_nonce_field('calmfox_watch_repair');
				echo '<input type="hidden" name="action" value="calmfox_watch_repair">';
				echo '<input type="hidden" name="check" value="'.esc_attr($id).'">';
				echo '<input type="hidden" name="revert" value="1">';
				echo '<button type="submit" class="button-link cw-revert">'.esc_html__('cofnij', 'calmfox-watch').'</button>';
				echo '</form></div>';
			}
			echo '</td><td class="cw-ms">'.(isset($check['ms']) && null !== $check['ms'] ? esc_html($check['ms'].' ms') : '').'</td></tr>';
		}
		echo '</tbody></table>';

		if (__('Stan usług', 'calmfox-watch') === $title) {
			self::render_quota_form();
		}
		echo '</div>';
	}

	/**
	 * Limit dyskowy konta: na hostingu współdzielonym PHP widzi cały serwer,
	 * więc jedyną prawdziwą liczbę ma klient — z panelu hostingu albo umowy.
	 */
	private static function render_quota_form(): void {
		$quota = (float) Calmfox_Watch_Settings::get('disk_quota_gb');
		echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="cw-quota">';
		wp_nonce_field('calmfox_watch_quota');
		echo '<input type="hidden" name="action" value="calmfox_watch_quota">';
		echo '<label for="cw-quota">'.esc_html__('Limit dysku na Twoim koncie hostingowym (GB)', 'calmfox-watch').'</label> ';
		echo '<input type="text" inputmode="decimal" id="cw-quota" name="quota" class="small-text" value="'.esc_attr($quota > 0 ? (string) $quota : '').'" placeholder="np. 20"> ';
		echo '<button type="submit" class="button">'.esc_html__('Zapisz limit', 'calmfox-watch').'</button>';
		echo '<p class="cw-detail">'.esc_html__('Znajdziesz go w panelu hostingu (albo w umowie). Zostaw puste, jeśli nie wiesz — wtedy pokazujemy tylko rozmiar strony, bez zgadywania.', 'calmfox-watch').'</p>';
		echo '</form>';
	}

	/** @param array<int, array<string, mixed>> $entries */
	private static function render_history(array $entries): void {
		echo '<div class="card cw-card"><h2>'.esc_html__('Historia aktualizacji', 'calmfox-watch').'</h2>';
		echo '<p class="cw-detail">'.esc_html__('Zbierana od instalacji wtyczki — wcześniejszych zmian nie da się odtworzyć.', 'calmfox-watch').'</p>';
		if (empty($entries)) {
			echo '<p>'.esc_html__('Brak wpisów — pierwszy pojawi się przy najbliższej aktualizacji.', 'calmfox-watch').'</p></div>';

			return;
		}
		echo '<table class="widefat striped cw-table"><tbody>';
		foreach ($entries as $entry) {
			$when = mysql2date(get_option('date_format').' '.get_option('time_format'), get_date_from_gmt((string) $entry['at']));
			$kind = 'core' === $entry['kind'] ? 'WordPress' : ('theme' === $entry['kind'] ? __('motyw', 'calmfox-watch') : __('wtyczka', 'calmfox-watch'));
			echo '<tr><td>'.esc_html($when).'</td>';
			echo '<td><strong>'.esc_html((string) $entry['name']).'</strong> <span class="cw-detail">('.esc_html($kind).')</span></td>';
			echo '<td>'.esc_html(($entry['from'] ? $entry['from'].' → ' : '').(string) $entry['to']).'</td>';
			echo '<td class="cw-detail">'.esc_html('auto' === $entry['mode'] ? __('automatyczna', 'calmfox-watch') : ((string) ($entry['by'] ?? '') !== '' ? sprintf(__('ręczna (%s)', 'calmfox-watch'), (string) $entry['by']) : __('ręczna', 'calmfox-watch'))).'</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function badge(string $status): string {
		$labels = array('ok' => __('OK', 'calmfox-watch'), 'warn' => __('uwaga', 'calmfox-watch'), 'fail' => __('awaria', 'calmfox-watch'));

		return '<span class="cw-badge cw-'.esc_attr($status).'">'.esc_html(isset($labels[$status]) ? $labels[$status] : $status).'</span>';
	}

	// ── Wspólne ──────────────────────────────────────────────────────────

	private static function guard(string $nonce_action): void {
		if (!current_user_can(self::CAP)) {
			wp_die(esc_html__('Brak uprawnień.', 'calmfox-watch'));
		}
		check_admin_referer($nonce_action);
	}

	private static function finish(string $type, string $message): void {
		set_transient('calmfox_watch_notice_'.get_current_user_id(), array('type' => $type, 'message' => $message), MINUTE_IN_SECONDS);
		wp_safe_redirect(self::screen_url());
		exit;
	}

	public static function notices(): void {
		$notice = get_transient('calmfox_watch_notice_'.get_current_user_id());
		if (!is_array($notice)) {
			return;
		}
		delete_transient('calmfox_watch_notice_'.get_current_user_id());
		echo '<div class="notice notice-'.esc_attr('success' === $notice['type'] ? 'success' : 'error').' is-dismissible"><p>'.esc_html((string) $notice['message']).'</p></div>';
	}
}

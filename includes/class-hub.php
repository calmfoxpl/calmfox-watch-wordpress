<?php
defined('ABSPATH') || exit;

/**
 * Rozmowa z API Calmfox Watch. Podczas register/pair hub wykonuje challenge:
 * pobiera nasz endpoint health i oczekuje echa nonce'a — dowód, że wtyczka
 * naprawdę działa na tej domenie. Dlatego nonce trafia do transientu PRZED
 * wysłaniem żądania, a znika zaraz po odpowiedzi.
 */
final class Calmfox_Watch_Hub {

	/**
	 * Aktywacja pakietu Free wprost z wtyczki: konto + strona + sondy po stronie
	 * huba, mail z linkiem logowania na podany adres.
	 *
	 * @return array{ok: bool, message: string, data?: array<string, mixed>}
	 */
	public static function register(string $email): array {
		$result = self::call('/api/public/plugin/register', array(
			'domain'    => self::domain(),
			'email'     => $email,
			'healthUrl' => Calmfox_Watch_Settings::health_url(),
			'nonce'     => Calmfox_Watch_Settings::make_pairing_nonce(),
			'cms'       => 'WordPress',
			'sitemap'   => Calmfox_Watch_Settings::sitemap_url(),
		), 201);
		Calmfox_Watch_Settings::clear_pairing_nonce();

		if ($result['ok']) {
			self::save_paired($result['data']);
			$result['message'] = __('Konto Free aktywne! Sprawdź skrzynkę — wysłaliśmy link logowania do panelu.', 'calmfox-watch');
		}

		return $result;
	}

	/**
	 * Parowanie z istniejącą stroną tokenem z ekranu Integracje
	 * (fxp_live_…). Używane też do rotacji sekretu — hub podmienia URL.
	 *
	 * @return array{ok: bool, message: string, data?: array<string, mixed>}
	 */
	public static function pair(string $token = ''): array {
		$token = '' !== $token ? $token : (string) Calmfox_Watch_Settings::get('install_token');
		if ('' === $token) {
			return array('ok' => false, 'message' => __('Brak tokenu instalacji — skopiuj go z ekranu Integracje w panelu.', 'calmfox-watch'));
		}

		$result = self::call('/api/public/plugin/pair', array(
			'token'     => $token,
			'healthUrl' => Calmfox_Watch_Settings::health_url(),
			'nonce'     => Calmfox_Watch_Settings::make_pairing_nonce(),
			'cms'       => 'WordPress',
			'sitemap'   => Calmfox_Watch_Settings::sitemap_url(),
		), 200);
		Calmfox_Watch_Settings::clear_pairing_nonce();

		if ($result['ok']) {
			self::save_paired($result['data']);
			$result['message'] = __('Połączono z Calmfox Watch — monitoring wnętrza WordPressa działa.', 'calmfox-watch');
		}

		return $result;
	}

	/**
	 * Rozłączenie (deaktywacja/przycisk). Sam token jest jawny, więc hub żąda
	 * też sekretu z URL-a — zna go wyłącznie ta instalacja. Best effort:
	 * przy braku sieci hub i tak zauważy głuchy endpoint.
	 */
	public static function disconnect(): void {
		$token = (string) Calmfox_Watch_Settings::get('install_token');
		if ('' === $token) {
			return;
		}
		wp_remote_post(calmfox_watch_api_url().'/api/public/plugin/disconnect', array(
			'timeout' => 8,
			'headers' => array('Content-Type' => 'application/json'),
			'body'    => wp_json_encode(array('token' => $token, 'key' => (string) Calmfox_Watch_Settings::get('secret'))),
		));
	}

	/**
	 * Samokontrola endpointu pętlą zwrotną — wyłapuje wtyczki security tnące
	 * REST API zanim użytkownik utknie na parowaniu. Uczciwie: to test od środka
	 * serwera; zewnętrzny dostęp ostatecznie weryfikuje challenge huba.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public static function loopback_check(): array {
		$response = wp_remote_get(Calmfox_Watch_Settings::health_url(), array('timeout' => 5, 'sslverify' => false));
		if (is_wp_error($response)) {
			return array('ok' => false, 'message' => $response->get_error_message());
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		$body = json_decode((string) wp_remote_retrieve_body($response), true);
		if (in_array($code, array(200, 503), true) && is_array($body) && isset($body['status'])) {
			return array('ok' => true, 'message' => '');
		}

		/* translators: %d: kod HTTP */
		return array('ok' => false, 'message' => sprintf(__('endpoint odpowiada kodem %d albo obcym formatem', 'calmfox-watch'), $code));
	}

	private static function domain(): string {
		return (string) wp_parse_url(home_url(), PHP_URL_HOST);
	}

	/** @param array<string, mixed> $data */
	private static function save_paired($data): void {
		$data = is_array($data) ? $data : array();
		Calmfox_Watch_Settings::update(array(
			'connected'     => true,
			'install_token' => (string) ($data['installToken'] ?? Calmfox_Watch_Settings::get('install_token')),
			'site_id'       => (string) ($data['siteId'] ?? ''),
			'plan'          => (string) ($data['plan'] ?? ''),
			'panel_url'     => '' !== (string) ($data['panelUrl'] ?? '') ? (string) $data['panelUrl'] : (string) Calmfox_Watch_Settings::get('panel_url'),
			'paired_at'     => gmdate('c'),
		));
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array{ok: bool, message: string, data?: array<string, mixed>}
	 */
	private static function call(string $path, array $body, int $expected): array {
		$response = wp_remote_post(calmfox_watch_api_url().$path, array(
			'timeout' => 25, // hub w trakcie robi challenge na nasz endpoint
			'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
			'body'    => wp_json_encode($body),
		));

		if (is_wp_error($response)) {
			/* translators: %s: błąd */
			return array('ok' => false, 'message' => sprintf(__('Nie udało się połączyć z Calmfox Watch: %s', 'calmfox-watch'), $response->get_error_message()));
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$data = json_decode((string) wp_remote_retrieve_body($response), true);
		$data = is_array($data) ? $data : array();

		if ($code === $expected) {
			return array('ok' => true, 'message' => '', 'data' => $data);
		}

		$message = '';
		foreach (array('detail', 'message', 'error') as $key) {
			if (!empty($data[$key]) && is_string($data[$key])) {
				$message = $data[$key];
				break;
			}
		}
		if ('' === $message) {
			/* translators: %d: kod HTTP */
			$message = sprintf(__('Serwer Calmfox Watch odpowiedział kodem %d.', 'calmfox-watch'), $code);
		}

		return array('ok' => false, 'message' => $message);
	}
}

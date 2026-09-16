<?php
defined('ABSPATH') || exit;

/**
 * Sekretny endpoint stanu: GET /wp-json/calmfox/v1/health?key=<sekret>
 * (bez ładnych permalinków: /?rest_route=/calmfox/v1/health&key=…).
 * Kontrakt z hubem: HTTP 200 = ok/warn, 503 = fail; sekcja `security`
 * przez ?section=security. Odpowiedź no-store + noindex — to nie jest
 * strona, tylko kanał danych; bez ważnego klucza oddajemy suche 403.
 */
final class Calmfox_Watch_Endpoint {

	const HEALTH_CACHE   = 'calmfox_watch_health_payload';
	const SECURITY_CACHE = 'calmfox_watch_security_payload';

	public static function register_routes(): void {
		register_rest_route('calmfox/v1', '/health', array(
			'methods'             => 'GET',
			'callback'            => array(__CLASS__, 'handle'),
			'permission_callback' => '__return_true', // autoryzacja niżej: sekret w query + hash_equals
		));
	}

	/** @param WP_REST_Request $request */
	public static function handle($request) {
		if (!Calmfox_Watch_Settings::key_is_valid((string) $request->get_param('key'))) {
			return self::respond(array('error' => 'forbidden'), 403);
		}
		// Znacznik jednorazowy huba: podpis liczony nad NIM i nad treścią dowodzi,
		// że odpowiedź powstała teraz w tym WordPressie. Podstawiony plik statyczny
		// (albo kopia sprzed przejęcia strony) nie ma jak go podrobić.
		$proofNonce = (string) $request->get_param('nonce');
		Calmfox_Watch_Settings::touch_last_poll();

		$section = 'security' === (string) $request->get_param('section') ? 'security' : 'health';
		$payload = self::payload($section);

		// Echo nonce'a parowania czytamy na żywo (poza cache), inaczej challenge
		// mógłby dostać payload sprzed wygenerowania nonce'a.
		$nonce = Calmfox_Watch_Settings::pairing_nonce();
		if ('' !== $nonce) {
			$payload['pairing'] = $nonce;
		}

		return self::respond($payload, 'fail' === $payload['status'] ? 503 : 200, $proofNonce);
	}

	/**
	 * Payload sekcji z krótkim cache (health 60 s, security 10 min) — sonda,
	 * hub i podgląd w adminie nie młócą checków przy każdym odpytaniu.
	 *
	 * @return array<string, mixed>
	 */
	public static function payload(string $section, bool $fresh = false): array {
		$transient = 'security' === $section ? self::SECURITY_CACHE : self::HEALTH_CACHE;
		if (!$fresh) {
			$cached = get_transient($transient);
			if (is_array($cached)) {
				return $cached;
			}
		}

		if ('security' === $section) {
			Calmfox_Watch_History::reconcile_snapshot(); // leczy dryf wersji sprzed zmian poza updaterem
			$result  = Calmfox_Watch_Security::run();
			$payload = array(
				'schema'  => 1,
				'plugin'  => CALMFOX_WATCH_VERSION,
				'status'  => $result['status'],
				'checks'  => $result['checks'],
				'history' => Calmfox_Watch_History::all(),
			);
			set_transient($transient, $payload, 10 * MINUTE_IN_SECONDS);
		} else {
			$result  = Calmfox_Watch_Checks::run();
			$payload = array(
				'schema' => 1,
				'plugin' => CALMFOX_WATCH_VERSION,
				'status' => $result['status'],
				'checks' => $result['checks'],
				'site'   => Calmfox_Watch_Checks::site_info(),
				// Sygnały do wykrywania zmian po stronie huba (nowy administrator).
				'signals' => Calmfox_Watch_Checks::security_signals(),
			);
			set_transient($transient, $payload, MINUTE_IN_SECONDS);
		}

		return $payload;
	}

	/**
	 * Odpowiedź podpisana kluczem instalacji. Podpis idzie NAGŁÓWKIEM, nie w treści:
	 * dzięki temu liczymy go nad dokładnie tymi bajtami, które wychodzą na łącze,
	 * bez zgadywania, jak WordPress poskłada JSON.
	 *
	 * @param array<string, mixed> $payload
	 */
	private static function respond(array $payload, int $status, string $nonce = '') {
		$response = new WP_REST_Response($payload, $status);
		$response->header('Cache-Control', 'no-store, max-age=0');
		$response->header('X-Robots-Tag', 'noindex, nofollow');

		if ('' !== $nonce) {
			$generated = gmdate('c');
			$body      = wp_json_encode($payload);
			$response->header('X-Calmfox-Generated-At', $generated);
			$response->header('X-Calmfox-Proof', hash_hmac('sha256',
				$nonce."\n".$generated."\n".(string) $body, (string) Calmfox_Watch_Settings::get('secret')));
		}

		return $response;
	}
}

<?php
/**
 * Kondycja strony z panelu: jedna liczba 0–100 z pięciu obszarów.
 *
 * To JEDYNE miejsce, w którym wtyczka pyta hub o coś dla siebie. Reszta kontraktu jest
 * pull — hub odpytuje nas — ale ocena powstaje po jego stronie (bierze pod uwagę uptime,
 * przeglądy podstron i pomiary wydajności, o których ta instalacja nie ma pojęcia), więc
 * musi przyjechać stąd. Odpowiedź trzymamy w transiencie przez godzinę: ekran administracyjny
 * nie może czekać na sieć, a ocena i tak przelicza się raz na dobę.
 *
 * Odpowiedź jest świadomie okrojona po stronie huba: bez listy wykryć integralności
 * i bez powodu sufitu, gdy powodem jest podejrzana zmiana na stronie. Kto przejął tę
 * instalację, ma dostęp do tego ekranu — i nie dowie się z niego, że go widzimy.
 *
 * @package Calmfox_Watch
 */

defined('ABSPATH') || exit;

final class Calmfox_Watch_Score {

	private const TRANSIENT = 'calmfox_watch_score';
	private const TTL       = HOUR_IN_SECONDS;
	private const TIMEOUT   = 8;

	/**
	 * Ocena z panelu albo null, gdy jej nie ma (brak połączenia, hub milczy, nic jeszcze
	 * nie policzone). Null znaczy „nie wiemy" — sekcja się wtedy nie pokazuje, bo pusta
	 * ramka z zerem kłamałaby o stanie strony.
	 *
	 * @param bool $force Pominąć cache (po ręcznym odświeżeniu ekranu).
	 * @return array<string, mixed>|null
	 */
	public static function get(bool $force = false): ?array {
		if (!Calmfox_Watch_Settings::get('connected')) {
			return null;
		}

		if (!$force) {
			$cached = get_transient(self::TRANSIENT);
			if (is_array($cached)) {
				return empty($cached['available']) ? null : $cached;
			}
		}

		$token = (string) Calmfox_Watch_Settings::get('install_token');
		if ('' === $token) {
			return null;
		}

		$response = wp_remote_post(calmfox_watch_api_url().'/api/public/plugin/score', array(
			'timeout' => self::TIMEOUT,
			'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
			'body'    => wp_json_encode(array('token' => $token)),
		));
		if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
			// Cisza huba nie jest oceną: zapamiętujemy ją na krótko, żeby nie pukać co odsłonę.
			set_transient(self::TRANSIENT, array('available' => false), 5 * MINUTE_IN_SECONDS);

			return null;
		}

		$data = json_decode((string) wp_remote_retrieve_body($response), true);
		if (!is_array($data) || empty($data['available'])) {
			set_transient(self::TRANSIENT, array('available' => false), self::TTL);

			return null;
		}

		set_transient(self::TRANSIENT, $data, self::TTL);

		return $data;
	}

	/** Po rozłączeniu i po wymianie klucza stara ocena nie ma prawa zostać na ekranie. */
	public static function forget(): void {
		delete_transient(self::TRANSIENT);
	}

	/** Klasa CSS dla barwy oceny — pasmo liczy hub, wtyczka go nie powtarza. */
	public static function tone_class(string $tone): string {
		switch ($tone) {
			case 'ok':
				return 'cw-score-ok';
			case 'warn':
				return 'cw-score-warn';
			case 'bad':
				return 'cw-score-bad';
			default:
				return 'cw-score-muted';
		}
	}
}

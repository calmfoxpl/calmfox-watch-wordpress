<?php
/**
 * Geometria pierścienia kondycji: z obszarów oceny liczy łuki SVG do narysowania.
 *
 * Pierścień jest tym samym rysunkiem, co w panelu (`src/app/shared/score-ring.ts`, wariant
 * `card`) i w aplikacji mobilnej. Klient ogląda oba tego samego dnia, więc liczby geometrii
 * i barwy są tu PRZEPISANE Z PANELU, a nie dobrane na oko. Zmiana którejkolwiek z tych
 * stałych bez zmiany w panelu rozjeżdża rysunek między ekranami.
 *
 * Bliźniak tej klasy stoi w rdzeniu pakietów Sylius, Neos i Magento. Pakiety nie dzielą
 * biblioteki — kod jest świadomie powielony, tak samo jak przy normalizacji sprawdzeń —
 * więc poprawka geometrii idzie do wszystkich czterech naraz.
 *
 * @package Calmfox_Watch
 */

defined('ABSPATH') || exit;

final class Calmfox_Watch_Score_Ring {

	/** Pole rysunku SVG (viewBox), kwadrat. Środek pierścienia leży w jego środku. */
	const BOX = 220;

	/** Promień linii środkowej łuku i grubość kreski — razem dają zewnętrzną krawędź. */
	const RADIUS = 92;
	const WIDTH  = 22;

	/**
	 * WIDOCZNA przerwa między łukami, w stopniach. Sama różnica kątów nie wystarczy:
	 * zaokrąglony koniec łuku wystaje poza swój kąt o pół grubości kreski i zjada odstęp,
	 * aż sąsiednie obszary zlewają się w jeden pierścień. Dlatego każdy łuk jest dodatkowo
	 * skracany o ten naddatek (patrz self::cap()) po obu stronach.
	 */
	const GAP = 6.0;

	/** Tor łuku, czyli „ile mogło być punktów". */
	const TRACK_COLOR = '#e5e7eb';

	/** Obszar spoza listy dostaje szarość, a nie błąd: hub może dołożyć nowy przed wtyczką. */
	const COLOR_FALLBACK = '#8a8db0';

	/**
	 * Barwy obszarów na JASNYM tle — panel WordPressa jest biały. To wariant
	 * `SCORE_AREA_COLORS_LIGHT` z panelu: przygaszone i ciemniejsze odcienie w tym samym
	 * miejscu koła barw, więc legenda z panelu zgadza się z tą tutaj.
	 *
	 * TA SAMA PALETA stoi w panelu, w aplikacji mobilnej (`mobile/src/theme.ts`), w widżecie
	 * zegarka (`mobile/targets/watch/index.swift`) i w rdzeniu każdego pakietu CMS. Zmiana
	 * barwy obszaru idzie do WSZYSTKICH tych miejsc naraz albo do żadnego.
	 *
	 * @return array<string, string>
	 */
	private static function colors(): array {
		return array(
			'availability' => '#3b9c90',
			'security'     => '#b4658f',
			'updates'      => '#5b93d3',
			'correctness'  => '#b86bc0',
			'performance'  => '#8f7dd6',
		);
	}

	/**
	 * Pierścień ZASTĘPCZY: pięć torów w prawdziwych proporcjach, żaden nie wypełniony.
	 *
	 * Stoi tam, gdzie na progu Free nie ma oceny. Pokazuje KSZTAŁT tego, co klient dostanie,
	 * i nie udaje pomiaru: wszystkie łuki są niezmierzone, więc rysują się kreskowanym torem
	 * i bez liczby w środku. To jedyne miejsce, w którym wtyczka rysuje pierścień bez danych
	 * z huba — i wolno jej, bo nie pokazuje wtedy ŻADNEJ liczby o stanie strony.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function placeholder(): array {
		return self::segments(array(
			array('area' => 'availability', 'weight' => 30, 'measured' => false),
			array('area' => 'security', 'weight' => 25, 'measured' => false),
			array('area' => 'updates', 'weight' => 20, 'measured' => false),
			array('area' => 'correctness', 'weight' => 15, 'measured' => false),
			array('area' => 'performance', 'weight' => 10, 'measured' => false),
		));
	}

	/** Barwa obszaru — dla legendy, żeby nie powtarzać tablicy w widoku. */
	public static function color(string $area): string {
		$colors = self::colors();

		return isset($colors[$area]) ? $colors[$area] : self::COLOR_FALLBACK;
	}

	/**
	 * Łuki do narysowania, w kolejności podanych obszarów.
	 *
	 * Każdy element: `d` (ścieżka toru), `length` (długość łuku), `fill` (długość wypełnienia),
	 * `color`, `label`, `area`, `score`, `measured`. Widok rysuje tor ścieżką `d`, a wypełnienie
	 * tą samą ścieżką z `stroke-dasharray="{fill} {length+1}"` — jedno przejście, bez JavaScriptu.
	 *
	 * Obszar NIEZMIERZONY zostaje samym torem (`fill` = 0.0): kropka w barwie obszaru
	 * sugerowałaby, że coś tam zmierzono i wyszło zero.
	 *
	 * @param array<int, array<string, mixed>> $areas Obszary z odpowiedzi huba.
	 * @return array<int, array<string, mixed>>
	 */
	public static function segments(array $areas): array {
		if (array() === $areas) {
			return array();
		}

		$total = 0.0;
		foreach ($areas as $area) {
			$total += max(0.0, (float) (isset($area['weight']) ? $area['weight'] : 0));
		}

		// Same zera w wagach (starszy hub albo obszar bez wagi) dają równe łuki zamiast
		// dzielenia przez zero: rysunek jest wtedy mniej dokładny, ale ekran się nie wywraca.
		$equal = $total <= 0.0;
		if ($equal) {
			$total = (float) count($areas);
		}

		$usable = 360.0 - self::GAP * count($areas);
		$cap    = self::cap();
		$angle  = -90.0 + self::GAP / 2;

		$segments = array();
		foreach ($areas as $area) {
			$weight = $equal ? 1.0 : max(0.0, (float) (isset($area['weight']) ? $area['weight'] : 0));
			$span   = ($weight / $total) * $usable;

			$from   = $angle + $cap;
			$to     = $angle + $span - $cap;
			$drawn  = max(0.0, $to - $from);
			$length = ($drawn * M_PI * self::RADIUS) / 180;

			// Pomiar PRZETERMINOWANY nie jest pomiarem: hub liczy tak samo, gdy zasila
			// pierścień w panelu, a pierścień ma być w obu miejscach ten sam rysunek.
			$measured = !empty($area['measured']) && empty($area['stale']);
			$score    = min(100.0, max(0.0, (float) (isset($area['score']) ? $area['score'] : 0)));

			$segments[] = array(
				'area'     => (string) (isset($area['area']) ? $area['area'] : ''),
				'label'    => (string) (isset($area['label']) ? $area['label'] : (isset($area['area']) ? $area['area'] : '')),
				'score'    => $measured ? (int) round($score) : null,
				'measured' => $measured,
				'd'        => self::arc($from, $to),
				'length'   => round($length, 2),
				'fill'     => $measured ? round($length * $score / 100, 2) : 0.0,
				'color'    => self::color((string) (isset($area['area']) ? $area['area'] : '')),
			);

			$angle += $span + self::GAP;
		}

		return $segments;
	}

	/** O ile stopni zaokrąglony koniec kreski wystaje poza kąt łuku. */
	private static function cap(): float {
		return asin(self::WIDTH / 2 / self::RADIUS) * 180 / M_PI;
	}

	private static function arc(float $from, float $to): string {
		$start = self::point($from);
		$end   = self::point($to);
		$large = ($to - $from) > 180 ? 1 : 0;

		return sprintf('M %s %s A %d %d 0 %d 1 %s %s', $start[0], $start[1], self::RADIUS, self::RADIUS, $large, $end[0], $end[1]);
	}

	/** @return array{0: string, 1: string} */
	private static function point(float $angle): array {
		$rad    = $angle * M_PI / 180;
		$centre = self::BOX / 2;

		return array(
			number_format($centre + self::RADIUS * cos($rad), 2, '.', ''),
			number_format($centre + self::RADIUS * sin($rad), 2, '.', ''),
		);
	}
}

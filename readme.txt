=== Calmfox Watch ===
Contributors: calmfox
Tags: monitoring, uptime, health, security, updates
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.9.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Monitoring wnętrza WordPressa dla Calmfox Watch: zdrowie usług, higiena bezpieczeństwa i historia aktualizacji.

== Description ==

Wtyczka łączy Twoją stronę z panelem Calmfox Watch (watch.calmfox.net):

* **Zdrowie usług** — baza danych, miejsce na dysku, połączenie z serwerem poczty (SMTP), harmonogram WP-Cron, cache obiektowy (Redis/Memcached), Elasticsearch (ElasticPress). Wyniki publikuje sekretny endpoint odpytywany przez monitoring; awaria usługi otwiera incydent w panelu.
* **Higiena bezpieczeństwa** — proste, uczciwe sprawdzenia konfiguracji (sole, edytor plików, XML-RPC, uprawnienia plików, wersja PHP, zaległe aktualizacje). To NIE jest audyt bezpieczeństwa ani skaner malware.
* **Historia aktualizacji** — każda aktualizacja core/wtyczek/motywów z wersją przed i po, zbierana od instalacji wtyczki. W panelu spina się z osią incydentów („co się zmieniło przed awarią").
* **Konto Free od ręki** — aktywacja wprost z wtyczki, logowanie linkiem z e-maila, bez hasła.

Własne usługi (RabbitMQ, kolejki, inne demony) dorzucisz filtrem `calmfox_watch_health_checks` — przykład w kodzie wtyczki.

= Prywatność =
Wtyczka wysyła do Calmfox wyłącznie: domenę strony, podany adres e-mail (przy aktywacji konta) oraz dane diagnostyczne opisane wyżej (statusy usług, wersje, liczby zaległych aktualizacji, historię aktualizacji, nazwy aktywnych wtyczek i stan automatycznych aktualizacji). Nazwy aktywnych wtyczek są potrzebne po to, żeby panel mógł napisać, która wtyczka została wyłączona, zamiast ogólnego „coś się zmieniło". Loginów administratorów nie wysyłamy: panel dostaje ich liczbę i jednokierunkowy odcisk zbioru kont, po którym widać zmianę składu, ale nie da się odczytać, kto to jest. Żadnych treści, użytkowników ani haseł. Sekretny endpoint wymaga klucza; bez niego odpowiada 403.

= Multisite =
Sieć multisite jest obsługiwana: każda podstrona łączy się z panelem osobno (własny sekret, własny adres, własna strona w panelu), a liczba kont z pełnymi uprawnieniami obejmuje też super-adminów sieci, bo mają dostęp do wszystkich podstron. Historia aktualizacji jest wspólna dla sieci, tak jak wspólne są wtyczki i motywy. Zalecamy aktywację sieciową: przy aktywacji tylko na wybranej podstronie historia zapisze wyłącznie te aktualizacje, które akurat poszły w jej kontekście. Rozmiar instalacji na multisite dotyczy całej sieci i tak jest opisany.

= Ograniczenia =
* Test SMTP sprawdza połączenie z serwerem poczty, nie faktyczne doręczenie.
* Miejsce na dysku: hosting współdzielony nie ujawnia limitu konta, więc podajesz go w ustawieniach wtyczki.

== Installation ==

1. Wgraj i aktywuj wtyczkę.
2. Wejdź w Calmfox Watch w menu kokpitu (pozycja z ikoną, zaraz pod „Kokpitem").
3. Aktywuj pakiet Free (nowe konto) albo wklej token instalacji z ekranu Integracje w panelu.

== Changelog ==

= 1.8.1 =
* Parowanie kluczem z panelu działa znowu na stronach zakładanych od sierpnia. Klucz instalacji urósł po stronie huba z 16 do 32 znaków (128 bitów zamiast 64), a wtyczka wpuszczała nadal wyłącznie krótszy: świeżo dodana strona odbijała się od komunikatu „Token z panelu ma zły format", choć klucz z panelu był poprawny. Teraz wtyczka przyjmuje obie długości, dokładnie tak jak przyjmuje je hub, więc strony sprzed zmiany parują się dalej swoim krótszym kluczem.

= 1.8.0 =
* Kafelek na pulpicie pokazuje teraz PARAMETRY monitoringu, czyli to, co na tej stronie w ogóle pilnujemy, każdy z własnym stanem. Bez tej listy „nic nie wymaga uwagi" nie mówiło, czego właściwie nic nie wymaga.
* Strona w pakiecie Free dostaje na pulpicie i na ekranie wtyczki uczciwe zaproszenie wyżej: co Free obejmuje, czego nie, i przycisk prowadzący prosto do wyboru pakietu dla TEJ strony.
* Przycisk „Zobacz pakiety" prowadził dotąd do ekranu ustawień (progi i okna serwisowe), bo pakiety mieszkały tam, zanim panel dostał własny ekran pakietu. Teraz otwiera właściwe miejsce.

= 1.7.0 =
* Calmfox Watch ma własną pozycję w menu kokpitu, z ikoną, zaraz pod „Kokpitem". Dotąd ekran wisiał w Ustawieniach, czyli zaglądał do niego wyłącznie ten, kto go szukał. Stary adres (Ustawienia → Calmfox Watch) przekierowuje na nowy razem z parametrami, więc zakładki i linki z maili działają dalej.
* Pulpit kokpitu dostaje kafelek z kondycją strony: status, liczby sprawdzeń i najwyżej trzy najpilniejsze sprawy (awarie przed ostrzeżeniami), z odnośnikiem do pełnego ekranu. Chodzi o osobę, która weszła do kokpitu po czymś innym i właśnie mija awarię.

= 1.6.0 =
* Sprawdzenie, które zapala się na czerwono, mówi teraz także, CO ma być zamiast tego: docelowe uprawnienia pliku, wartość ustawienia, adres w konfiguracji. Dotąd kończyło się na opisie problemu, więc „uprawnienia 666" trzeba było samemu przetłumaczyć na „ustaw 640 plikowi wp-config.php".
* Tam, gdzie naprawa mieści się w jednym poleceniu, wtyczka pokazuje je gotowe do skopiowania (chmod z konkretną ścieżką, wp config set, composer install --no-dev). Kto nie ma dostępu do serwera, przekazuje tę linijkę hostingodawcy zamiast opisywać sprawę własnymi słowami. Polecenia niczego nie uruchamiają same — to przykład dla człowieka, a jednym kliknięciem wtyczka naprawia dalej tylko to, co potrafi cofnąć.
* Podpowiedzi widać w kokpicie i w panelu Calmfox Watch, bo do wtyczki zagląda zwykle kto inny niż do panelu.

= 1.5.1 =
* Panel Calmfox Watch przeprowadził się na watch.calmfox.net. Wtyczka bierze nowy adres sama, także na stronach sparowanych wcześniej — adres zapisany przy parowaniu przestawia się przy pierwszym wczytaniu ustawień. Stary adres nadal działa (przekierowanie zachowujące metodę żądania), więc strona, która nie zaktualizuje wtyczki, monitoringu nie traci. Adres wpisany ręcznie, np. do środowiska testowego, zostaje nietknięty.

= 1.5.0 =
* Wyłączenie automatycznych aktualizacji przestaje być niewidoczne. Dotąd sprawdzaliśmy wyłącznie stałą WP_AUTO_UPDATE_CORE, więc wtyczka blokująca aktualizacje (robi to filtrem) i wyłączenie auto-aktualizacji per wtyczka w kokpicie przechodziły bez śladu. Teraz czytamy wszystkie trzy źródła: stałą, filtr wyłączający i opcje auto_update_plugins / auto_update_themes.
* Zmiana zestawu aktywnych wtyczek trafia do panelu. Do sekcji zdrowia dochodzi lista aktywnych wtyczek i odcisk jej składu, więc panel otwiera zdarzenie, gdy wtyczka zostanie wyłączona albo dojdzie nowa. Deaktywacja wtyczki zabezpieczającej to klasyczny pierwszy krok po przejęciu kokpitu.
* Zdarzenie, które okazało się zaplanowaną zmianą, zamkniesz w panelu jednym kliknięciem. Nie pomniejsza wtedy dostępności ani czasów reakcji, ale zostaje widoczne w historii i w raporcie.

= 1.4.0 =
* Podpisywanie odpowiedzi: przy każdym odpytaniu panel wysyła jednorazowy znacznik, a wtyczka podpisuje nim odpowiedź kluczem instalacji (HMAC-SHA256 w nagłówku). Dzięki temu widać, czy wynik powstał naprawdę teraz na tej stronie, czy ktoś podstawił pod ten adres statyczny plik albo powtarza starą kopię. Odpowiedź bez ważnego podpisu nie nadpisuje stanu strony i otwiera zdarzenie w panelu.

= 1.3.0 =
* Obsługa multisite: super-adminowie sieci wliczani do kont z pełnymi uprawnieniami (mają dostęp do wszystkich podstron), historia aktualizacji wspólna dla sieci, rozmiar instalacji opisany jako sieciowy.
* Wyłączenie wtyczki nie jest już ciche: panel otwiera zdarzenie i powiadamia. Wyciszenie monitoringu bywa pierwszym krokiem po przejęciu kokpitu, więc klient ma o tym wiedzieć.

= 1.2.0 =
* Miejsce na dysku bez bajek: na hostingu współdzielonym PHP raportuje cały wolumen serwera (widzieliśmy „wolne 4,8 TB” na koncie z kilkoma GB limitu), więc takiej liczby już nie pokazujemy — mierzymy rozmiar instalacji, a limit konta możesz podać w ustawieniach wtyczki i wtedy pilnujemy zajętości.
* Napraw jednym kliknięciem: uprawnienia wp-config.php i katalogów zapisywalnych dla wszystkich, wyłączenie XML-RPC i edytora plików w kokpicie. Każda naprawa opisana przed kliknięciem, wyłączenia odwracalne.
* Pakiet strony w kokpicie: co dokłada wyższy próg i link prosto do zakupu w panelu — w kontekście tej konkretnej strony.
* Linki do panelu prowadzą teraz do właściwej strony (panel sam przełącza kontekst).

= 1.1.2 =
* „Sprawdź ponownie" w kokpicie (i `wp plugin update`) omija cache manifestu — świeże wydanie widać od razu, bez czekania na wygaśnięcie 12-godzinnego cache'u.

= 1.1.1 =
* Identyfikacja wizualna Calmfox Watch: logo na ekranie ustawień (wariant na jasny i ciemny kokpit), znak marki w kartach aktualizacji i oknie „Więcej informacji", skrót do panelu i stan połączenia w wierszu wtyczki na liście.

= 1.1.0 =
* Połącz przez watch.calmfox.net: jeden przycisk zamiast ręcznego tokenu — logujesz się (albo rejestrujesz) w panelu, wybierasz organizację i wracasz do kokpitu ze sparowaną wtyczką. Strona nie musi wcześniej istnieć w panelu.
* Ręczne wklejanie tokenu zostaje jako opcja awaryjna.

= 1.0.0 =
* Start: endpoint zdrowia, higiena bezpieczeństwa, historia aktualizacji, aktywacja Free i parowanie tokenem.

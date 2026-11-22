# Instalacja platformy kursów: instrukcja dla admina serwera

Dokument dla osoby, która stawia platformę na serwerze (hosting współdzielony LiteSpeed/Apache albo własny VPS). Nie trzeba roota ani Dockera. Całość zajmuje ok. 30–45 minut (bez czekania na DNS).

## 1. Co jest potrzebne

| Wymaganie | Uwagi |
|---|---|
| PHP **8.1 lub nowszy** | rozszerzenia: `pdo_mysql`, `mbstring`, `dom`, `fileinfo`, `zip`, `curl`, `openssl` (instalator sprawdza) |
| MySQL 5.7+ / MariaDB 10.3+ | baza w `utf8mb4`; użytkownik z pełnymi prawami do tej jednej bazy |
| Cron | jedno zadanie co minutę |
| Skrzynka/usługa SMTP | np. Amazon SES, Postmark, Mailgun, Resend (wszystkie mają SMTP). **Nie** zwykłe `mail()` |
| Poddomena | np. `nowa.ogarnijgo.pl` na czas testów; później `kursy.ogarnijgo.pl` |
| HTTPS | certyfikat (Let's Encrypt na hostingu jest zwykle jednym kliknięciem) |

Dane do zebrania przed startem (punkt 13 wymagań): rodzaj serwera, wersje PHP i MySQL, dostęp do crona, dane SMTP, limit wgrywanych plików, sposób kopii zapasowych, gdzie stoi poddomena.

## 2. Wgranie plików

1. Wgraj cały katalog `platforma/` na serwer (FTP/SFTP/menedżer plików lub `git clone`).
2. **Najlepiej:** w panelu hostingu ustaw katalog główny poddomeny na podkatalog **`public/`** (np. `/home/konto/platforma/public`). Wtedy kod, pliki klientów i konfiguracja leżą poza katalogiem publicznym.
3. **Jeśli hosting nie pozwala zmienić katalogu głównego:** wgraj całość do katalogu domeny (`public_html`). Plik `.htaccess` z korzenia projektu kieruje ruch do `public/` i blokuje dostęp do `src/`, `config/`, `storage/`, `bin/` itd. Sprawdź po instalacji, czy `https://twoja-domena/config/config.php` i `https://twoja-domena/storage/` zwracają błąd 403/404.
4. Uprawnienia: katalogi `config/` i `storage/` (z podkatalogami) muszą być zapisywalne dla PHP (zwykle 755 lub 775). Pliki użytkowników zapisują się w `storage/uploads/` pod losowymi nazwami **bez rozszerzeń** i są wydawane wyłącznie przez PHP (`Content-Disposition: attachment`); nigdy nie są wykonywane. `storage/.htaccess` dodatkowo blokuje dostęp bezpośredni.

## 3. Baza danych

Utwórz pustą bazę i użytkownika w panelu hostingu (lub poleceniami `CREATE DATABASE kursy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`). Zapisz: host, nazwę bazy, użytkownika, hasło. Tabele utworzy instalator.

## 4. Instalacja (jedna z dwóch dróg)

### A. Przez przeglądarkę
Wejdź na `https://poddomena/instalator.php`. Formularz sprawdzi wymagania, połączy się z bazą, utworzy tabele, konto administratora i plik `config/config.php`. Pierwsze konto administratora to **Marek** (adres `latuszekmarek@gmail.com` po imporcie dostaje rolę administratora; hasło ustawiasz w instalatorze). **Po instalacji usuń `public/instalator.php`.** Uwaga: między wgraniem plików a instalacją instalator jest dostępny dla każdego, kto zna adres, więc zainstaluj od razu po wgraniu (albo użyj instalacji z SSH). Po udanej instalacji instalator sam się blokuje (`storage/installed.lock`).

### B. Z linii poleceń (SSH)
```bash
php bin/instaluj.php --db-host=localhost --db-name=kursy --db-user=UŻYTKOWNIK --db-pass='HASŁO' \
  --app-url=https://nowa.ogarnijgo.pl --admin-email=latuszekmarek@gmail.com --admin-name="Marek" \
  --smtp-host=smtp.twojdostawca.pl --smtp-port=587 --smtp-enc=tls --smtp-user=LOGIN --smtp-pass='HASŁO' \
  --mail-from=kursy@ogarnijgo.pl
```
Bez `--admin-password` skrypt zapyta o hasło (min. 10 znaków). Instalacja jest powtarzalna bezpiecznie: nie kasuje bazy.

## 5. Cron (obowiązkowy)

Jedno zadanie, co minutę (w cPanelu: „Zadania Cron”; na VPS: `crontab -e`):

```bash
* * * * * /usr/bin/php /ŚCIEŻKA/DO/platforma/bin/cron.php >> /ŚCIEŻKA/DO/platforma/storage/logs/cron.log 2>&1
```
(ścieżkę do PHP sprawdzisz poleceniem `which php`; na hostingu bywa `/usr/local/bin/php` lub `/opt/alt/php81/usr/bin/php`.) Cron wysyła kolejkę e-maili w tempie z ustawień, dokańcza importy klientów i sprząta stare dane. Bez crona reset hasła nadal działa (próbuje wysłać od razu), ale masowe zaproszenia i dokończenie importu po zamknięciu karty nie zadziałają.

## 6. E-mail: SMTP, SPF, DKIM, DMARC

1. Dane SMTP wpisuje instalator do `config/config.php` (sekcja `mail`). Zmienisz je tam później: `transport` (`smtp` | `log` do testów), `host`, `port`, `encryption` (`tls` = 587, `ssl` = 465), `user`, `pass`.
2. Adres nadawcy i nazwę ustawiasz w panelu: **Ustawienia → Hasła i e-mail**.
3. U dostawcy poczty zweryfikuj domenę nadawcy i dodaj w DNS rekordy **SPF, DKIM i DMARC** (podaje je dostawca). Bez nich wiadomości trafią do spamu. DNS jest po stronie Marka.
4. Test: na ekranie logowania kliknij „Pierwsze logowanie / nie pamiętam hasła”, wpisz własny adres. Błędy wysyłki widać w tabeli `mail_queue` (kolumna `last_error`) i w **Ustawienia** (liczniki kolejki).
5. Tempo masowej wysyłki: **Ustawienia → „E-maile na minutę”** (domyślnie 60, czyli ok. 8 850 zaproszeń w ~2,5 godziny; dopasuj do limitów dostawcy). Link do hasła powstaje **w chwili wysyłki** i jest ważny 30/60/120 minut (ustawienie), więc nie wygasa w kolejce.

## 7. Migracja danych z Publigo

Paczka: `kursy.json`, `quizy.json`, `kursanci.csv`, `dostepy.csv`. Wgraj ją do katalogu **poza publicznym** (np. `storage/imports/paczka/`) i uruchom:

```bash
php bin/import-publigo.php --dir=storage/imports/paczka
```
Import trwa kilka sekund i jest bezpieczny do powtórzenia (kursy już w bazie są pomijane, konta i przypisania uzupełniane; `--replace-content --yes` odtwarza treść kursów już w bazie i kasuje przy tym postęp kursantów w lekcjach oraz przepięte linki do plików; zwykły ponowny import niczego nie nadpisuje, daty dostępu zostają). Na końcu powstaje **raport zgodności** w `storage/reports/`: plik HTML do przeczytania przez Marka, JSON oraz CSV z listą plików do pobrania. W panelu: **Migracja → Otwórz najnowszy raport**. Samo odświeżenie raportu bez importu: `--report-only`.

Co zawiera raport: liczby w źródle i w bazie (kursy, moduły, lekcje, bloki wideo Vimeo/YouTube, obrazy, pliki, quizy i ich pytania, konta, przypisania, daty dostępu) z różnicami; listę elementów pominiętych lub zmienionych (14 pustych lekcji, 9× `[trenerka]`, 6 osadzeń Spotify, osadzenia innych serwisów zamienione na linki, puste bloki); 9 kont o roli innej niż „Subskrybent”; kursy bez kursantów; **losową próbę 20 lekcji** do ręcznego porównania z Publigo; inwentaryzację plików (domeny i rozszerzenia).

Decyzje przyjęte przy imporcie: konto `latuszekmarek@gmail.com` dostaje rolę administratora, pozostałe 8 kont spoza „Subskrybentów” trafia jako klienci i jest wypisane w raporcie do weryfikacji. Żadne hasło nie jest przenoszone z importu: klient ustawia je z linku. 6 quizów jest w bibliotece, do podpięcia w lekcjach (źródło nie wiąże quizów z lekcjami). Stary procent postępu zostaje jako informacja przy przypisaniu. Stare adresy `/?p=ID` mają przekierowania 301 na nowe.

## 8. Pliki z cdn.idealms.net (zrób PRZED wyłączeniem Publigo)

W treści lekcji jest ok. 300 unikalnych adresów (głównie cdn.idealms.net: PDF, JPG, PNG, ZIP). Po rezygnacji z Publigo linki przestaną działać.

```bash
php bin/pobierz-pliki.php --probe     # próba na 5 plikach
php bin/pobierz-pliki.php             # pełne pobieranie, wielokrotnego użytku
```
* `--probe` sprawdza, czy serwer CDN wydaje pliki. Jeśli zwraca **403/401** (pliki wymagają zalogowania do Publigo) lub brak połączenia, skrypt **zatrzymuje się** (kod wyjścia 2). Wtedy pobierz pliki przeglądarką po zalogowaniu, wgraj je ręcznie jako bloki „Plik”/„Obraz” w edytorze albo skontaktuj się z programistą.
* Pobieranie pomija to, co już pobrane, ponawia błędy przy kolejnym uruchomieniu i zapisuje raport niepowodzeń do `storage/reports/pobieranie-bledy-….csv`. Pliki są weryfikowane po zawartości (strona HTML pod adresem pliku jest odrzucana). Linki w blokach i w tekście lekcji są przepinane na własny serwer.
* Opcje: `--hosts=cdn.idealms.net,obiblog.pl` (inne domeny, np. stare obrazy z obiblog.pl), `--allow-ext=gif,webp` (rozszerzenia spoza listy), `--sleep-ms=200` (pauza między plikami).
* **Gdy CDN Publigo już nie działa:** użyj archiwum `pliki-publigo.zip` (kopia wszystkich plików z lekcji, z `indeks.csv`). Rozpakuj je i uruchom `php bin/wczytaj-pliki-z-eksportu.php --dir=/sciezka/pliki-publigo`. Skrypt wczytuje pliki bez sięgania do sieci i przepina linki w lekcjach (sprawdzone: 195 plików, 662 bloki).
* Stan: **Migracja** w panelu pokazuje, ile plików jest już na własnym serwerze, a ile nadal zdalnych.

## 9. Vimeo i YouTube

* **Vimeo:** w ustawieniach prywatności Vimeo (Settings → Privacy → „Where can this video be embedded”) dodaj domenę platformy (`nowa.ogarnijgo.pl`, potem `kursy.ogarnijgo.pl`). Inaczej filmy się nie odtworzą. Linki prywatne `vimeo.com/ID/HASH` są obsługiwane.
* YouTube jest osadzany przez `youtube-nocookie.com`.
* Platforma wysyła nagłówek Content-Security-Policy dopuszczający ramki tylko z Vimeo, YouTube (nocookie) i Spotify.

## 10. Limity wgrywania plików

Limit w panelu (**Ustawienia → Limit wgrywanego pliku**, domyślnie 100 MB) nie może przekraczać limitów PHP: `upload_max_filesize` i `post_max_size` (w cPanelu: „Wybierz wersję PHP” / „MultiPHP INI Editor”; na VPS `php.ini`). Panel pokazuje faktycznie obowiązujący limit. Obrazy: do 20 MB.

## 11. Kopie zapasowe (do ustalenia z adminem)

Wymaganie: codziennie, min. 14 dni wstecz, baza **i** `storage/uploads/`. Przykład na VPS (cron, 3:15):

```bash
15 3 * * * mysqldump --single-transaction -u UŻYTKOWNIK -p'HASŁO' kursy | gzip > /kopie/kursy-$(date +\%F).sql.gz && tar czf /kopie/pliki-$(date +\%F).tgz -C /ŚCIEŻKA/DO/platforma storage/uploads && find /kopie -mtime +14 -delete
```
Na hostingu współdzielonym włącz kopie dostawcy i sprawdź ich retencję.

## 12. Bezpieczeństwo i RODO: lista kontrolna

* Usuń `public/instalator.php`. Sprawdź, że `config/`, `storage/`, `src/` nie są dostępne z przeglądarki.
* Włącz HTTPS (ciasteczko sesji dostaje flagę `Secure` automatycznie na HTTPS; za reverse proxy ustaw w `config.php` `'trust_proxy' => true`). Nagłówek HSTS włącza się automatycznie, gdy `app_url` zaczyna się od `https://`.
* Wbudowane: CSRF we wszystkich formularzach, `HttpOnly` + `SameSite=Lax`, zapytania parametryzowane, oczyszczanie HTML przy zapisie i escapowanie przy wyświetlaniu, limity prób (logowanie: 8 na 15 min na adres, 40 na IP; link do hasła: 3 na godzinę na adres, 10 na IP, min. 60 s odstępu), dziennik zdarzeń (`security_log`), unieważnianie sesji i linków po zmianie hasła, hasła w Argon2id (bcrypt, gdy brak), identyczna odpowiedź niezależnie od istnienia adresu.
* **Nie ma** (świadomie, wersja 1): drugiego składnika logowania dla admina (zalecane na przyszłość; do czasu wdrożenia użyj długiego, unikalnego hasła i ogranicz dostęp do `/admin` regułą serwera po IP, jeśli to możliwe).
* Dane osobowe: potrzebna **umowa powierzenia przetwarzania danych** między Markiem a Tobą (jeśli to Ty prowadzisz serwer) oraz z dostawcą e-mail.
* Po zakończeniu migracji usuń paczkę (`storage/imports/paczka`) i tymczasowe kopie z danymi klientów.

## 12a. Sposób logowania klientów

W **Ustawienia → Sposób logowania klientów** są trzy tryby:
* **Tylko adres e-mail** (domyślny; nic nie jest wysyłane): klient wpisuje adres i wchodzi. Każdy, kto zna cudzy adres, wejdzie na jego konto (zobaczy kursy i postęp, nie zobaczy haseł ani danych płatniczych, których platforma nie przechowuje). Chroni przed zgadywaniem limit prób na IP (25 na 15 min). Wygodne na okres przejściowy.
* **Link z e-maila:** klient wpisuje adres i dostaje link logujący (jednorazowy, ważny 30/60/120 min). Wymaga działającego SMTP z SPF/DKIM/DMARC.
* **E-mail i hasło:** klasycznie; hasło z linku lub z importu starych haszy.

Konta **administratorów** zawsze logują się hasłem we wszystkich trybach. Zmianę trybu zrobisz w sekundę, bez ponownej instalacji. Na stałe zalecamy tryb „Link z e-maila”.

## 13. Przełączenie (skrót)

1. Instalacja i testy na poddomenie tymczasowej, Publigo działa bez zmian.
2. Pełny import, raport zgodności, ręczna weryfikacja próby 20 lekcji, pobranie plików z CDN (punkt 8).
3. Zaproszenie testowe dla kilku zaufanych klientów (ustawienie hasła, logowanie, lekcje, quiz). Dodaj ich w **Klienci → Dodaj klienta** lub wyślij link z karty klienta.
4. Dopiero po weryfikacji przekieruj `kursy.ogarnijgo.pl` (rekord A/CNAME) na nowy serwer, zmień `app_url` w `config/config.php`, dodaj domenę do Vimeo i włącz komunikat na logowaniu (jest domyślnie włączony).
5. Masowe zaproszenia: **Import** z listy klientów z opcją e-maila albo przycisk „Wyślij link” na karcie klienta. Publigo wyłącz dopiero po weryfikacji.

## 14. Codzienna praca

* **Kursy:** lista, edytor (moduły, lekcje, bloki przeciąganiem; zapis automatyczny), czas dostępu kursu.
* **Klienci:** wyszukiwanie, filtr kursu / „Wygasły dostęp” / „Bez kursu”, stronicowanie po 20, hurtowe przypisywanie i odbieranie kursu (zaznaczone osoby albo wszyscy pasujący do filtra), edycja kursów i dat pojedynczego klienta, wysyłka linku do hasła.
* **Import:** CSV/TSV/XLSX do 10 000 wierszy, kolumny `email`, `imie`, `nazwisko`, `kurs` (nazwy po przecinku), `dostep_do`; raport przed importem (nowe / już w bazie / błędne adresy z plikiem do pobrania); import działa w tle z paskiem postępu. Stary format XLS zapisz jako XLSX lub CSV.
* **Quizy / Wyniki:** biblioteka quizów, oceny ręczne pytań opisowych i z plikiem.
* **Ustawienia:** komunikat logowania (włącznik i treść), ważność linku, nadawca, tempo wysyłki, limit plików, adres przycisku „Przedłuż dostęp”.

## 15. Rozwiązywanie problemów

| Objaw | Co sprawdzić |
|---|---|
| Biała strona / „Coś poszło nie tak” | `storage/logs/error.log`; tymczasowo `'debug' => true` w `config.php` |
| Brak e-maili | `storage/logs/cron.log`, tabela `mail_queue.last_error`; dane SMTP; czy cron działa |
| Wideo Vimeo się nie odtwarza | domena platformy na liście dozwolonych w Vimeo |
| Nie da się wgrać dużego pliku | `upload_max_filesize` i `post_max_size` w PHP |
| Instalator „nie może zapisać config” | uprawnienia katalogu `config/` |
| Import utknął po zamknięciu karty | czy cron działa (kończy import w tle) |

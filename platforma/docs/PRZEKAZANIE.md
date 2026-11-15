# Przekazanie: stan prac, decyzje i ograniczenia

## Co jest zrobione i przetestowane

| Obszar | Stan | Jak sprawdzone |
|---|---|---|
| Schemat bazy, instalator (WWW i CLI) | gotowe | instalacja od zera na MariaDB 10.11 |
| Import paczki Publigo | gotowe | pełny import ~2 s; raport: **0 różnic** w 16 wierszach porównania (85 kursów, 531 modułów, 1 698 lekcji, 1 452 Vimeo, 668 YouTube, 6 Spotify, 523 obrazy, 103 pliki, 6 quizów/49 pytań/124 odpowiedzi, 8 857 kont, 31 322 przypisania, 5 wygasłych); 0 brakujących przypisań i 0 różnych dat |
| Raport zgodności | gotowe | HTML + JSON + CSV, losowa próba 20 lekcji (20/20 zgodnych) |
| Logowanie, reset hasła, sesje | gotowe | przepływ z wymagań (jednorazowy link, skrót tokenu w bazie, min. 10 znaków, unieważnienie sesji i linków, jednakowa odpowiedź) sprawdzony curlem |
| Limity prób, CSRF, 403 dla klienta w panelu | gotowe | sprawdzone curlem |
| Widok klienta (lista kursów, odtwarzacz, postęp, quiz) | gotowe | Playwright, w tym 375 px (bez poziomego przewijania) |
| Czas dostępu (wygasły, „Przedłuż dostęp”, 30 dni) | gotowe | Playwright |
| Edytor kursu (drag-and-drop, bloki, quiz, uploady) | gotowe | Playwright; fałszywe pliki (.pdf/.png z kodem PHP) odrzucone po zawartości |
| Klienci (wyszukiwanie, filtry, stronicowanie po 20, hurt, edycja dat) | gotowe | strony renderowane na 8 857 kontach; hurt sprawdzony tylko na poziomie kodu i renderu (patrz niżej) |
| Import CSV/XLSX w tle z paskiem postępu | gotowe | CSV w Windows-1250 (1 203 wiersze), błędy i ostrzeżenia, XLSX w teście jednostkowym |
| Kolejka e-mail, SMTP, tempo, token przy wysyłce | gotowe | cron + atrapa serwera SMTP (AUTH LOGIN) |
| Pobieranie plików z CDN | gotowe, sprawdzone na prawdziwym CDN | `cdn.idealms.net` wydaje pliki bez logowania: pobrano 195 z 195 unikalnych adresów (73 PDF, 66 JPG/JPEG, 54 PNG, 1 ZIP; ok. 367 MB), 0 odwołań do CDN w bazie po przepięciu; adresy `http://` są ponawiane przez `https://` |
| Testy | `php tests/run.php` (57 asercji), scenariusze `tests/e2e/*.js` | |

## Decyzje podjęte z właścicielem

* `[trenerka]`: usuwany z treści i wypisany w raporcie (9 wystąpień).
* Konto `latuszekmarek@gmail.com` = administrator; 8 pozostałych kont spoza „Subskrybentów” = klienci, wypisani w raporcie.
* Hasła nie są przenoszone. Importer nie ustawia żadnych haseł; administrator ma hasło z instalatora.
* E-mail: własny klient SMTP (bez bibliotek).
* Pliki z CDN: osobny skrypt z próbą i zatrzymaniem przy 403.

## Czego nie sprawdziłem (wprost)

1. **Pliki z innych domen** (obiblog.pl ok. 56 adresów, ogarnij-go.pl, ogarnijgo.pl i inne) nie są pobierane domyślnie; użyj `--hosts=...`.
2. **Apache/LiteSpeed**: całość testowana na wbudowanym serwerze PHP. Reguły `.htaccess` (korzeń i `public/`) nie były uruchamiane na prawdziwym serwerze WWW; sprawdź po instalacji, że `config/` i `storage/` zwracają 403/404.
3. **Prawdziwy dostawca SMTP**, STARTTLS i SPF/DKIM/DMARC: testowane tylko na atrapie bez szyfrowania.
4. **Odtwarzanie Vimeo/YouTube**: w sesji brak dostępu do tych serwisów; sprawdzono tylko generowanie osadzenia (z hashem linków prywatnych). Wymaga dodania domeny w Vimeo.
5. **Hurtowe przypisanie kursu przyciskiem w przeglądarce** (zaznaczanie wszystkich pasujących) i **ocena ręczna quizu**: kod i strony działają, ale nie przeszły scenariusza klikanego.
6. **Wydajność** przy 8 850 kontach: listy klienckie działają na pełnych danych (strona listy renderuje się w ułamku sekundy lokalnie); wyszukiwanie to `LIKE '%…%'` po e-mailu i nazwie, wystarczające na tę skalę.

## Znane ograniczenia i pomysły na później

* Brak drugiego składnika logowania admina (zalecenie z wymagań; nie wdrożone).
* Import klientów: tylko CSV/TSV/XLSX (nie XLS/ODS). Istniejące konta w pliku są pomijane (bez zmian przypisań), zgodnie z wymaganiem.
* Opcjonalny import haszy WordPressa: weryfikacja phpass (`$P$`, `$wp$`) jest w logowaniu (pole `users.legacy_hash`, po pierwszym poprawnym logowaniu przeliczane na Argon2id), ale **nie ma jeszcze polecenia wczytującego hashe ze zrzutu**. Dodatek, nie warunek.
* Przekierowania 301: zapisane dla `/?p=ID` (id wpisów WordPress lekcji i kursów). Pełne stare adresy (slugi) wymagają listy z Publigo; tabela `redirects` jest gotowa (`old_path` → kurs/lekcja).
* Brakujące `module_id` (74 lekcje luzem) → moduł „Lekcje” w 23 kursach.
* Quizy z Publigo nie mają progu ani limitu podejść: ustawiono 70% i bez limitu (do zmiany w edytorze). Pytania „Wybierz typ pytania” → opisowe (2 sztuki, wypisane w danych importu).
* Daty dostępu przechowywane jako data (bez godziny); dostęp obowiązuje przez cały dzień daty końcowej.
* Przycisk „Przedłuż dostęp”: bez płatności (poza zakresem). Otwiera adres z Ustawień albo e-mail do nadawcy.
* Sortowanie hurtowe bez progu bezpieczeństwa: „Odbierz kurs” wymaga drugiego kliknięcia.

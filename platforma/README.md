# Platforma kursów online

Własna, prosta platforma kursów (zastępuje Publigo): panel admina, widok klienta, quizy, czas dostępu, import klientów i migracja danych z Publigo z raportem zgodności. PHP 8.1+, MySQL/MariaDB, bez Composera, bez roota i bez Dockera.

* **Instrukcja instalacji dla admina:** [docs/INSTALACJA.md](docs/INSTALACJA.md)
* **Przekazanie (stan prac, decyzje, ograniczenia):** [docs/PRZEKAZANIE.md](docs/PRZEKAZANIE.md)

```
public/        katalog główny domeny (index.php, assets, instalator.php)
src/           kod (Controllers, Migration, Jobs, Mail, ...)
views/         szablony
bin/           polecenia: instaluj, import-publigo, pobierz-pliki, cron
storage/       pliki użytkowników, logi, raporty (poza publicznym katalogiem)
config/        config.php (tworzy instalator)
schema.sql     schemat bazy
tests/         testy (php tests/run.php) i scenariusze e2e (Playwright)
```

Testy: `php tests/run.php` (jednostkowe, bez bazy).

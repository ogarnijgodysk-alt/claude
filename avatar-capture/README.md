# Avatar Capture – AI Models Partners

Wtyczka WordPress do nagrywania ujęć klientów pod avatary AI (HeyGen).

## Stan

**v0.1.0 – test kamery.** Strona `/record-test` sprawdza na telefonie:

- tylną kamerę (1080p / 4K) i nagrywanie (MediaRecorder),
- pytania głosowe zadawane w trakcie nagrania (po 3 s ciszy i testowe po 10 s),
- wyciszanie mikrofonu w nagraniu na czas pytania (WebAudio), żeby w pliku nie było głosu pytania,
- raport do skopiowania z parametrami telefonu i nagrania.

## Struktura

```
plugin/amp-avatar-capture/      kod wtyczki (to trafia do WordPressa)
  amp-avatar-capture.php        rejestracja strony /record-test
  templates/test-page.php       strona testowa
  assets/test.js, test.css      logika i wygląd
  assets/audio/q*.mp3           pytania testowe (głos syntetyczny)
tools/build.sh                  pakuje wtyczkę do dist/*.zip
tools/make-test-audio.sh        generuje pliki z pytaniami (espeak-ng)
```

## Budowanie

```
tools/build.sh   # -> dist/amp-avatar-capture-<wersja>.zip
```

## Instalacja

WordPress → Wtyczki → Dodaj nową → Wyślij wtyczkę na serwer → wybierz plik .zip → Zainstaluj → Włącz.
Na liście wtyczek pojawi się link „Otwórz stronę testową”.

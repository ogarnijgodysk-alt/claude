# Avatar Capture – AI Models Partners

Wtyczka WordPress do nagrywania ujęć klientów pod avatary AI (HeyGen).

## Stan: v0.2.0

- **/record** – aplikacja dla klientów (po angielsku), **/record-test** – to samo z panelem diagnostyki.
- Klient wpisuje imię i nazwisko → instrukcja → kamera (główny tylny obiektyw, pion).
- Kontrola kadru na żywo (MediaPipe Pose w przeglądarce): zbliżenie od klatki i cała postać,
  ramka na ekranie, podpowiedzi głosem lektora (ElevenLabs), światło, poziom telefonu.
- Gdy kadr jest dobry: „Say Start” → rozpoznawanie hasła (Web Speech API, zapasowo: dowolne krótkie słowo)
  albo przycisk → odliczanie 3-2-1 → nagrywanie.
- Tryb wywiadu: pytania głosowe po 3 s ciszy; mikrofon w nagraniu wyciszany na czas pytania.
- Wyjście z kadru: ostrzeżenie po 1 s, przerwanie i powtórka po 3 s. Koniec: podniesiona ręka po minimum,
  przycisk albo limit czasu.
- Sprawdzenie nagrania (długość, kadr, głos, światło, jakość) → wysyłka na Dysk Google w kawałkach po 8 MB
  z wznawianiem (Google Apps Script) → drugie ujęcie (cała postać) → koniec.
- Tryb testowy zapisuje na Dysku raport z każdego nagrania.

## Struktura

```
plugin/amp-avatar-capture/        kod wtyczki (to trafia do WordPressa)
  amp-avatar-capture.php          adresy /record i /record-test, konfiguracja
  includes/settings.php           Ustawienia → Avatar Capture (adres skryptu, czasy, instrukcja)
  templates/app.php               ekrany aplikacji
  assets/app.js, app.css          logika i wygląd
  assets/audio/*.mp3              głos lektora (podpowiedzi i pytania)
  assets/keepawake.mp4            zapobiega wygaszaniu ekranu
  apps-script/Code.gs             skrypt Google: odbiór nagrań na Dysk
tools/build.sh                    pakuje wtyczkę do dist/*.zip
tools/voice-lines.txt             teksty lektora
tools/split-voice.sh              dzieli nagranie lektora na pliki
```

## Budowanie

```
tools/build.sh   # -> dist/amp-avatar-capture-<wersja>.zip
```

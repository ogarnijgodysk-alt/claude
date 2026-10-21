# CloneStudio — klony AI, wideo i asystent na stronę dla firm

Aplikacja webowa (MVP) dla firm, które chcą robić treści do social mediów z AI:

- **Klon AI** realnej osoby (CEO, handlowiec, ekspert) albo **wirtualny influencer** marki.
- **Studio nagrań**: klon nagrywa film z napisanego skryptu albo powtarza to, co powiesz do mikrofonu.
- **Asystent na stronę**: klon rozmawia z klientami na stronie firmy — wideo na żywo (Tavus) albo głosowo.
- **Asystent treści**: skrypty, opisy, hashtagi i tygodniowy plan filmów dopasowane do profilu marki.
- **Sesje zdjęciowe AI**: zdjęcia klona w dowolnej scenerii (jak mysnapface).
- **Obsługa głosowa**: dyktowanie w polach tekstowych, odsłuch skryptów, rozmowa głosowa z asystentem — także na telefonie.

Interfejs jest po polsku. Każda integracja działa w **trybie demo** bez klucza API, więc całą aplikację da się przeklikać od razu.

## Funkcje

| Moduł | Co robi | Pod spodem |
|---|---|---|
| **Cyfrowy bliźniak** (jak HeyGen) | Nagrywasz 30 s–3 min wideo kamerą w przeglądarce (z prompterem) albo wgrywasz plik. Klon mówi Twoim głosem. | HeyGen API v3 `POST /v3/avatars` (`digital_twin`) + weryfikacja zgody `POST /v3/avatars/{group_id}/consent` |
| **Klon ze zdjęć** (jak mysnapface) | Wgrywasz 10–20 selfie. Sesje zdjęciowe działają od razu, bez treningu. | kie.ai `google/nano-banana-edit` (zdjęcia referencyjne); opcjonalnie fal.ai LoRA |
| **AI influencer** | Opisujesz postać. Claude projektuje personę, model obrazu generuje 4 twarze do wyboru. | Claude + kie.ai `google/nano-banana` |
| **Głos** | Z wideo bliźniaka, z nagranej próbki albo z gotowego ID głosu. | HeyGen / ElevenLabs Instant Voice Clone + TTS |
| **Studio nagrań** | „Skrypt” (klon czyta tekst) i „Mój głos” (nagrywasz lub wgrywasz audio, klon synchronizuje usta). Formaty 9:16 / 1:1 / 16:9. | Bliźniak: HeyGen `POST /v3/videos`. Klony ze zdjęcia: kie.ai `kling/ai-avatar-standard` (zdjęcie + audio) |
| **Asystent na stronę** | Dymek „Porozmawiaj z …” na stronie firmy. Wideo na żywo z klonem albo rozmowa głosowa. Odpowiada z wiedzy firmy, nie wie → kieruje do kontaktu. | Tavus CVI (`/v2/replicas`, `/v2/personas`, `/v2/conversations`); bez Tavus: rozpoznawanie mowy w przeglądarce + Claude + głos klona (ElevenLabs) lub urządzenia |
| **Plan treści** | 5–14 pomysłów na filmy; każdy otwiera się w studiu z wypełnionym tematem. | Claude |
| **Biblioteka** | Podgląd, pobieranie MP4, kopiowanie opisu z hashtagami. Gotowe wideo kopiujemy do własnego magazynu (linki dostawców wygasają). | — |
| **Profil marki** | Branża, oferta, grupa docelowa, ton, CTA, zakazane słowa, oznaczanie treści AI. | Wstrzykiwany do każdego promptu |

### Dlaczego kie.ai zamiast fal.ai

kie.ai to pośrednik z niższymi cenami tych samych modeli i jednym kluczem do wszystkiego. Dla klonów ze zdjęć daje dwie rzeczy naraz:

- **sesje zdjęciowe bez treningu**: Nano Banana Edit trzyma twarz na podstawie kilku zdjęć referencyjnych, więc nie trzeba trenować LoRA (oszczędność czasu i ok. 2 USD na klona),
- **mówiące wideo ze zdjęcia**: Kling AI Avatar animuje zdjęcie klona do nagrania głosu, taniej niż awatar ze zdjęcia w HeyGen.

fal.ai zostaje jako opcja (`FAL_KEY`), gdy potrzebne jest najwyższe podobieństwo z własnego modelu LoRA. Cyfrowe bliźniaki z nagrania wideo nadal robi HeyGen — kie.ai nie ma odpowiednika.

## Wersje do testowania

| Wersja | Gdzie | Co działa |
|---|---|---|
| **Podgląd na claude.ai** | Link z rozmowy z Claude (`npm run build:preview` buduje jeden plik HTML) | Wszystkie ekrany, telefon i desktop, teksty i odpowiedzi asystenta pisze Claude. Bez kamery i mikrofonu — przeglądarka w claude.ai ich nie udostępnia |
| **Wersja testowa w internecie** (Vercel, za darmo) | Twój adres `*.vercel.app` | To samo plus **nagrywanie kamerą, dyktowanie i rozmowa głosowa na telefonie**. Dane zostają w przeglądarce testującego |
| **Pełna wersja** (Docker) | Własny serwer / Railway / Render | Wszystkie integracje: prawdziwe klony, wideo, Tavus, kod asystenta do wklejenia na stronę |

### Wersja testowa na Vercel (ok. 3 minuty)

1. Zaloguj się na <https://vercel.com> kontem GitHub.
2. **Add New → Project → Import** repozytorium `ogarnijgodysk-alt/claude`.
3. **Root Directory**: `clone-studio`.
4. **Environment Variables**: `NEXT_PUBLIC_STORAGE` = `browser` (opcjonalnie `ANTHROPIC_API_KEY`, żeby teksty pisał Claude).
5. **Deploy**. Link `https://…vercel.app` otwórz na telefonie — mikrofon i kamera działają, bo to HTTPS.

Wdrożenia z gałęzi innych niż główna Vercel domyślnie chroni logowaniem. Żeby link działał dla innych osób, wyłącz **Settings → Deployment Protection** albo scal zmiany do gałęzi głównej.

Publiczne endpointy AI mają prosty limit zapytań na adres IP; przy kluczu Claude w publicznej wersji testowej licz się z kosztem zapytań testujących.

### Pełna wersja (Docker, trwałe dane)

```bash
docker build -t clonestudio clone-studio
docker run -p 3000:3000 -v clonestudio-data:/data --env-file clone-studio/.env.local clonestudio
```

Na Railway / Render / Fly.io: wdroż z `Dockerfile`, podłącz wolumen pod `/data`, ustaw klucze i `PUBLIC_BASE_URL` na adres aplikacji (HeyGen, kie.ai i Tavus pobierają z niego nagrania).

## Szybki start lokalnie

```bash
cd clone-studio
npm install
cp .env.example .env.local   # klucze są opcjonalne
npm run dev                   # http://localhost:3000
```

Nagrywanie kamerą i mikrofonem wymaga `localhost` albo HTTPS. Rozpoznawanie mowy działa w Chrome, Edge i Safari (także iOS); w Firefoksie zostaje pisanie.

### Klucze API (`.env.local`)

| Zmienna | Do czego |
|---|---|
| `ANTHROPIC_API_KEY` | Skrypty, opisy, plan treści, persony, odpowiedzi asystenta (model `claude-opus-5-5`) |
| `KIE_API_KEY` | Sesje zdjęciowe, twarze influencerów, mówiące wideo ze zdjęcia |
| `KIE_AVATAR_MODEL` | `kling/ai-avatar-standard` (720p) albo `kling/ai-avatar-pro` (1080p) |
| `HEYGEN_API_KEY` | Cyfrowe bliźniaki z wideo i render filmów z nimi |
| `HEYGEN_DEFAULT_VOICE_ID` | Głos zapasowy dla klonów bez własnego głosu |
| `TAVUS_API_KEY` | Rozmowy wideo na żywo z klonem na stronie |
| `TAVUS_DEFAULT_REPLICA_ID` | Gotowa twarz Tavus, gdy nie nagrywasz własnej |
| `ELEVENLABS_API_KEY` | Klonowanie głosu, mowa klona w filmach i w asystencie |
| `FAL_KEY` | Opcjonalnie: własny model twarzy (LoRA) |
| `PUBLIC_BASE_URL` | Publiczny adres aplikacji (pobieranie nagrań przez dostawców) |
| `DATA_DIR` | Katalog bazy JSON i plików (domyślnie `.data`) |
| `NEXT_PUBLIC_STORAGE` | `browser` = wersja testowa z danymi w przeglądarce |

## Asystent na stronę

1. **Asystent na stronę → Nowy asystent**: wybierz klona, wpisz wiedzę (oferta, ceny, godziny, FAQ) i kontakt dla trudnych pytań.
2. Z `TAVUS_API_KEY`: użyj gotowej twarzy Tavus albo nagraj osobę z firmy (nagranie zaczyna się od oświadczenia zgody wymaganego przez Tavus; trening trwa kilka godzin).
3. Wklej kod na stronę firmy:

```html
<script src="https://TWOJA-APLIKACJA/embed.js" data-assistant="ID_ASYSTENTA" async></script>
```

Na stronie pojawia się dymek. Na telefonie rozmowa otwiera się na pełnym ekranie.

## Architektura

```
src/
  app/                    strony (Next.js App Router) i API (app/api/*)
  components/             UI, nagrywarka (MediaRecorder + prompter), rozmowa głosowa, widget
  lib/
    providers/            adaptery: claude, kie, heygen, tavus, elevenlabs, fal, demo
    services/             logika: klony, wideo, sesje zdjęciowe, asystenci
    browser/              ten sam interfejs /api/* w przeglądarce (IndexedDB) dla wersji testowej
    content.ts            prompty i treści demo wspólne dla serwera i przeglądarki
preview/                  wejście i zamienniki next/* dla jednoplikowego podglądu
public/embed.js           widget asystenta dla stron klientów
```

Zadania u dostawców (trening awatara, render wideo, obrazy) są asynchroniczne. Serwer nie trzyma workerów — przy każdym `GET` odświeża status u dostawcy, a frontend odpytuje co kilka sekund.

## Co dalej, żeby zrobić z tego produkt SaaS

1. **Konta i firmy (multi-tenant)**: logowanie, zespoły, role (twórca / akceptujący), workspace na firmę. Teraz jest jedna baza na instancję.
2. **Baza i pliki**: Postgres zamiast JSON, S3/R2 zamiast dysku, webhooki dostawców zamiast pollingu, prawdziwy limiter zapytań (np. Redis).
3. **Płatności**: Stripe + kredyty (minuty wideo, minuty rozmów Tavus, obrazy), bo koszty dostawców liczone są od sekundy i od obrazu.
4. **Publikacja**: bezpośrednie wysyłanie i planowanie postów (TikTok, Instagram/Facebook, LinkedIn, YouTube).
5. **Asystent**: zapis rozmów i leadów, przekazanie do CRM, statystyki.
6. **Zgodność**: twarz i głos to dane biometryczne (RODO art. 9). Potrzebne są regulamin, umowy powierzenia z dostawcami (zwłaszcza z pośrednikami jak kie.ai), polityka retencji, usuwanie danych na żądanie oraz oznaczanie treści AI (AI Act art. 50). Aplikacja już wymaga potwierdzenia zgody, zapisuje nagranie oświadczenia, oznacza asystenta i opisy jako AI.

## Uwagi

- Integracje kie.ai, HeyGen v3 i Tavus są napisane według dokumentacji, ale nie były jeszcze uruchomione z prawdziwymi kluczami. Pola odpowiedzi parsujemy defensywnie — przy pierwszym uruchomieniu sprawdź je z aktualną dokumentacją: <https://docs.kie.ai>, <https://developers.heygen.com>, <https://docs.tavus.io>.
- HeyGen wyłącza API v1/v2 1 listopada 2026, dlatego integracja od razu używa v3.
- Cyfrowy bliźniak w HeyGen nagrywa dopiero po weryfikacji zgody przez osobę klonowaną (link w szczegółach klona, ważny 24 h).
- Kling AI Avatar (kie.ai) dopasowuje kadr do zdjęcia klona — do pionowych filmów najlepiej sprawdza się pionowe zdjęcie okładki. Nagrania głosu z Chrome na komputerze mają format WebM; jeśli kie.ai go odrzuci, wgraj MP3/M4A.

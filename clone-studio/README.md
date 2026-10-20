# CloneStudio — klony AI i wideo do social mediów dla firm

Aplikacja webowa (MVP) dla firm, które chcą robić treści do social mediów z AI:

- **Klon AI** realnej osoby (CEO, handlowiec, ekspert) albo **wirtualny influencer** marki.
- **Studio nagrań**: klon nagrywa film z napisanego skryptu albo powtarza to, co powiesz do mikrofonu.
- **Asystent treści**: skrypty, opisy, hashtagi i tygodniowy plan filmów dopasowane do profilu marki.
- **Sesje zdjęciowe AI**: zdjęcia klona w dowolnej scenerii (jak mysnapface).

Interfejs jest po polsku. Każda integracja działa w **trybie demo** bez klucza API, więc całą aplikację da się przeklikać od razu.

## Funkcje

| Moduł | Co robi | Pod spodem |
|---|---|---|
| **Cyfrowy bliźniak** (jak HeyGen) | Nagrywasz 30 s–3 min wideo kamerą w przeglądarce (z prompterem) albo wgrywasz plik. Klon mówi Twoim głosem. | HeyGen API v3 `POST /v3/avatars` (`digital_twin`) + link weryfikacji zgody `POST /v3/avatars/{group_id}/consent` |
| **Klon ze zdjęć** (jak mysnapface) | Wgrywasz 10–20 selfie. Powstaje model twarzy do sesji zdjęciowych i mówiący awatar ze zdjęcia. | fal.ai `flux-lora-portrait-trainer` (LoRA) + HeyGen `photo` avatar |
| **AI influencer** | Opisujesz postać. Claude projektuje personę i prompt, FLUX generuje 4 twarze do wyboru. | Claude (structured output) + fal.ai `flux/dev` + HeyGen `photo` avatar |
| **Głos** | Z wideo bliźniaka, z nagranej próbki albo z gotowego ID głosu. | HeyGen / ElevenLabs Instant Voice Clone + TTS |
| **Studio nagrań** | Tryb „Skrypt” (klon czyta tekst) i „Mój głos” (nagrywasz audio, klon synchronizuje usta). Formaty 9:16 / 1:1 / 16:9 pod platformę. | HeyGen `POST /v3/videos` (script + voice_id albo audio_url), polling `GET /v3/videos/{id}` |
| **Plan treści** | 5–14 pomysłów na filmy; każdy otwiera się w studiu z wypełnionym tematem. | Claude |
| **Sesje zdjęciowe** | 8 gotowych scenerii + własny opis. | fal.ai LoRA klona albo PuLID (twarz z okładki) |
| **Biblioteka** | Podgląd, pobieranie MP4, kopiowanie opisu z hashtagami. Gotowe wideo kopiujemy do własnego magazynu (linki HeyGen wygasają). | — |
| **Profil marki** | Branża, oferta, grupa docelowa, ton, CTA, zakazane słowa, oznaczanie treści AI. | Wstrzykiwany do każdego promptu |

## Szybki start

```bash
cd clone-studio
npm install
cp .env.example .env.local   # klucze są opcjonalne
npm run dev                   # http://localhost:3000
```

Nagrywanie kamerą i mikrofonem wymaga `localhost` albo HTTPS.

### Klucze API (`.env.local`)

| Zmienna | Do czego |
|---|---|
| `ANTHROPIC_API_KEY` | Skrypty, opisy, plan treści, persony (model `claude-opus-5-5`) |
| `HEYGEN_API_KEY` | Klony wideo i renderowanie filmów |
| `HEYGEN_DEFAULT_VOICE_ID` | Głos zapasowy dla klonów bez własnego głosu |
| `ELEVENLABS_API_KEY` | Klonowanie głosu i synteza mowy |
| `FAL_KEY` | Trening modelu twarzy, sesje zdjęciowe, twarze influencerów |
| `PUBLIC_BASE_URL` | Publiczny adres aplikacji — HeyGen pobiera z niego nagrane audio (bez niego plik idzie do magazynu fal.ai) |
| `DATA_DIR` | Katalog bazy JSON i plików (domyślnie `.data`) |

## Architektura

```
src/
  app/                    strony (Next.js App Router) i API (app/api/*)
  components/             UI, nagrywarka (MediaRecorder + prompter), odtwarzacz
  lib/
    providers/            adaptery: claude.ts, heygen.ts, elevenlabs.ts, fal.ts, demo.ts
    services/             logika: klony, wideo, sesje zdjęciowe (start zadania + odświeżanie statusu)
    db.ts, files.ts       baza JSON i magazyn plików
```

Zadania u dostawców (trening awatara, LoRA, render wideo) są asynchroniczne. Serwer nie trzyma workerów — przy każdym `GET` odświeża status u dostawcy, a frontend odpytuje co kilka sekund. Dzięki temu aplikacja działa też na hostingu serverless.

## Co dalej, żeby zrobić z tego produkt SaaS

1. **Konta i firmy (multi-tenant)**: logowanie, zespoły, role (twórca / akceptujący), workspace na firmę. Teraz jest jedna baza na instancję.
2. **Baza i pliki**: Postgres zamiast JSON, S3/R2 zamiast dysku, kolejka i webhooki HeyGen/fal zamiast pollingu.
3. **Płatności**: Stripe + system kredytów (minuty wideo, treningi, zdjęcia), bo koszty dostawców liczone są od minuty i od obrazu.
4. **Publikacja**: bezpośrednie wysyłanie i planowanie postów (TikTok, Instagram/Facebook, LinkedIn, YouTube).
5. **Montaż**: napisy, B-roll, intro/outro z brand kitu, tłumaczenie wideo na inne języki.
6. **Zgodność**: twarz i głos to dane biometryczne (RODO art. 9). Potrzebne są regulamin, umowy powierzenia z dostawcami, polityka retencji, usuwanie danych na żądanie oraz oznaczanie treści AI (AI Act art. 50). Aplikacja już wymaga potwierdzenia zgody, zapisuje nagranie oświadczenia i dopisuje informację o AI do opisów.

## Uwagi

- HeyGen wyłącza API v1/v2 1 listopada 2026, dlatego integracja od razu używa v3. Pola odpowiedzi (status, `video_url`, `consent_url`) parsujemy defensywnie — przy pierwszym uruchomieniu z prawdziwym kluczem sprawdź je z aktualną dokumentacją: <https://developers.heygen.com>.
- Cyfrowy bliźniak w HeyGen zaczyna nagrywać dopiero po weryfikacji zgody przez osobę klonowaną (link w szczegółach klona, ważny 24 h).

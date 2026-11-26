# Lejek – wtyczka WordPress (wersja 0.1.0)

Zastępuje OptimizePress w tym, czego potrzebujemy: produkty z płatnością Stripe, zamówienia na żywo,
licznik miejsc liczony z prawdziwych zamówień, statystyki lejka, piksel Meta i Microsoft Clarity.

Makieta całości: https://claude.ai/artifact/DRj7mReKv6QaAv3gy4k1WG

## Co jest w tej wersji

- **Produkty** (Lejek → Produkty): cena, waluta, limit sprzedaży na miesiąc, rezerwacje na kolejny miesiąc.
  Produkt i cena zakładają się w Stripe same (osobno w trybie testowym i na żywo).
- **Kasa Stripe**: link `/?lejek_checkout=adres-produktu` otwiera płatność Stripe (karta, Apple Pay, Google Pay,
  Link). Zbieramy telefon klienta, bo do niego dzwonicie. Karta zapisuje się w Stripe pod upsell jednym kliknięciem.
- **Zamówienia na żywo** (Lejek → Zamówienia): lista odświeża się sama, powiadomienie w przeglądarce i e-mail
  o każdym zamówieniu, statusy „do telefonu / po rozmowie / zrealizowane / zwrot”, notatki, zwrot przez Stripe
  jednym przyciskiem, eksport CSV.
- **Strona podziękowania**: krótki kod `[lejek_thankyou]`. Pokazuje zamówienie, wysyła zdarzenie Purchase
  do piksela Meta i oznacza zakup w Clarity.
- **Statystyki** (Lejek → Statystyki): przychód, zamówienia, odwiedzający, konwersja, lejek (wejścia → kasa →
  płatność), źródła ruchu z `utm_source`, miejsca w miesiącu.
- **Pusta strona**: pole „Lejek: Pusta strona” przy edycji strony usuwa nagłówek, stopkę i style motywu.
  Do stron sprzedażowych wklejonych jako „Własny HTML”.
- **Kilka domen, jedno konto Stripe**: każda domena ma swój webhook, a wtyczka ignoruje płatności z innych domen.

## Instalacja

1. Pobierz `lejek-0.1.0.zip`.
2. WordPress → **Wtyczki → Dodaj nową → Wyślij wtyczkę na serwer** → wybierz plik → **Zainstaluj** → **Włącz**.
3. **Ustawienia → Ogólne → Strefa czasowa**: ustaw *Nowy Jork*. Licznik miejsc liczy miesiące według tej strefy,
   tak jak strona sprzedażowa.

Wymagania: WordPress 6.0+, PHP 7.4+. Wersję sprawdzisz w **Narzędzia → Zdrowie witryny → Informacje**
(sekcje „WordPress” i „Serwer”).

## Stripe – zacznij od trybu testowego

1. Stripe → **Developers → API keys** (przełącznik „Test mode” włączony) → skopiuj *Secret key* `sk_test_…`
   → wklej w **Lejek → Ustawienia → Klucz tajny – test**.
2. Stripe → **Developers → Webhooks → Add endpoint**. Adres skopiuj z Lejek → Ustawienia (sekcja Webhook).
   Zdarzenia: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `charge.refunded`.
   Skopiuj *Signing secret* `whsec_…` → **Sekret webhooka – test**.
3. **Zapisz ustawienia**, potem **Sprawdź połączenie ze Stripe**. Zobaczysz nazwę konta, kraj i walutę wypłat.
4. Stripe → **Settings → Customer emails** → włącz e-maile o udanych płatnościach (klient dostaje potwierdzenie).

## Produkt „Done For You: AI Creator”

Lejek → Produkty → **Dodaj produkt**:

- tytuł: `Done For You: AI Creator`
- cena: `47.00`, waluta `USD`
- adres w linku: `ai-creator`
- limit sprzedaży na miesiąc: `7`, rezerwacje na następny miesiąc: tak

Opublikuj. Pod spodem pojawi się link do kasy.

## Strona podziękowania

1. **Strony → Dodaj**: tytuł np. `Thank you`, dodaj blok **Krótki kod** z treścią `[lejek_thankyou]`. Opublikuj.
2. **Lejek → Ustawienia → Strona podziękowania**: wybierz tę stronę.
3. Wpisz Wasz numer: `+1 (332) 241-5600`. Klient zobaczy go jako „Save our number”.

Własna treść (opcjonalnie):

```
[lejek_thankyou]
<h2>You're in, {first_name}!</h2>
<p>Order #{order}: {product}, {amount}. Receipt sent to {email}.</p>
<p>We'll call you from {company_phone} to schedule your Model Reveal Call.</p>
[/lejek_thankyou]
```

## Strona sprzedażowa (przeniesienie z OptimizePress)

1. **Strony → Dodaj** → tytuł → blok **Własny HTML** → wklej kod strony od `START` do `END`.
2. Ostatni blok `<script> … </script>` zamień na ten z pliku `przyklady/aivirtualmodelagency-skrypt.html`
   (link do kasy i licznik miejsc biorą się wtedy z wtyczki).
3. W panelu bocznym strony zaznacz **Lejek: Pusta strona**. Opublikuj.
4. **Ustawienia → Czytanie → Strona główna wyświetla: Statyczną stronę** → wybierz tę stronę.

Piksel Meta: jeśli nie masz go w innej wtyczce, wpisz ID w Lejek → Ustawienia i zaznacz „Wstaw kod piksela”.

## Test przed uruchomieniem

Otwórz stronę, kliknij przycisk i zapłać kartą `4242 4242 4242 4242` (dowolna przyszła data, CVC `123`).
Po płatności:

- wracasz na stronę podziękowania z numerem zamówienia,
- przychodzi e-mail o zamówieniu (jeśli nie przychodzi, zainstaluj np. WP Mail SMTP),
- zamówienie jest w Lejek → Zamówienia,
- licznik miejsc na stronie pokazuje o jedno miejsce mniej.

## Uruchomienie na żywo

Klucz `sk_live_…` i osobny webhook w trybie live (te same zdarzenia, sekret do pola „Sekret webhooka – na żywo”),
potem w Ustawieniach przełącz na **Na żywo**.

Przed startem uzupełnij w stopce linki `[LINK-TERMS]`, `[LINK-PRIVACY]`, `[LINK-REFUND]`. Stripe przy weryfikacji
konta sprawdza, czy strona ma regulamin i politykę zwrotów.

## Następne kroki

- upsell jednym kliknięciem (np. wideo od zespołu) po płatności,
- połączenie z Claude (zarządzanie produktami, stronami i statystykami z czatu, bez hasła admina),
- wskaźniki z Microsoft Clarity przez API w Statystykach,
- kreator lejków i strony (lead magnet, order bump, downsell),
- aktualizacje wtyczki prosto z GitHuba.

## Budowanie pliku ZIP

```bash
./build.sh
```

Plik pojawi się w `dist/`.

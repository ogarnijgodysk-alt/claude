"use client";

import { useEffect, useState } from "react";
import { api } from "@/components/api";
import { Card, ConfirmButton, PageHeader, cx } from "@/components/ui";
import type { ProviderStatus } from "@/lib/providers/status";

type Key = Exclude<keyof ProviderStatus, "browser">;

const PROVIDERS: { key: Key; name: string; env: string; what: string; demo: string; link: string }[] = [
  {
    key: "claude",
    name: "Claude (Anthropic)",
    env: "ANTHROPIC_API_KEY",
    what: "Skrypty wideo, opisy postów, hashtagi, plan treści, persony influencerów, odpowiedzi asystenta na stronie.",
    demo: "Przykładowe, szablonowe teksty.",
    link: "https://platform.claude.com",
  },
  {
    key: "kie",
    name: "kie.ai (zalecane, najtańsze)",
    env: "KIE_API_KEY",
    what: "Sesje zdjęciowe klona bez treningu (Nano Banana Edit), twarze AI influencerów, mówiące wideo ze zdjęcia i głosu (Kling AI Avatar).",
    demo: "Ilustracje zastępcze i symulowany podgląd wideo.",
    link: "https://kie.ai",
  },
  {
    key: "heygen",
    name: "HeyGen",
    env: "HEYGEN_API_KEY",
    what: "Cyfrowe bliźniaki z nagrania wideo (najwyższa jakość, głos klonowany z nagrania), render filmów z bliźniakiem (API v3).",
    demo: "Symulowany podgląd wideo ze zdjęciem klona i napisami.",
    link: "https://www.heygen.com",
  },
  {
    key: "tavus",
    name: "Tavus",
    env: "TAVUS_API_KEY",
    what: "Rozmowa wideo na żywo z klonem na Twojej stronie (asystent). Opcjonalnie TAVUS_DEFAULT_REPLICA_ID — gotowa twarz bez treningu.",
    demo: "Asystent rozmawia głosowo ze zdjęciem klona.",
    link: "https://www.tavus.io",
  },
  {
    key: "elevenlabs",
    name: "ElevenLabs",
    env: "ELEVENLABS_API_KEY",
    what: "Klonowanie głosu z nagrania, mowa klona w filmach ze zdjęcia i w rozmowach asystenta.",
    demo: "Próbka głosu jest zapisywana, asystent mówi głosem urządzenia.",
    link: "https://elevenlabs.io",
  },
  {
    key: "fal",
    name: "fal.ai (opcjonalnie)",
    env: "FAL_KEY",
    what: "Trening własnego modelu twarzy (LoRA) dla najwyższego podobieństwa w sesjach zdjęciowych.",
    demo: "—",
    link: "https://fal.ai",
  },
  {
    key: "publicUrl",
    name: "Publiczny adres aplikacji",
    env: "PUBLIC_BASE_URL",
    what: "Adres, z którego HeyGen, kie.ai i Tavus pobierają nagrania. Bez niego pliki trafiają do tymczasowego magazynu kie.ai lub fal.ai.",
    demo: "—",
    link: "https://vercel.com",
  },
];

export default function SettingsPage() {
  const [status, setStatus] = useState<ProviderStatus | null>(null);
  useEffect(() => {
    api<ProviderStatus>("/api/status").then(setStatus, () => undefined);
  }, []);

  if (status?.browser) {
    return (
      <>
        <PageHeader title="Integracje" subtitle="To jest wersja testowa: wszystko działa w Twojej przeglądarce." />
        <Card className="space-y-3 text-sm text-zinc-600">
          <p>
            <b className="text-zinc-900">Co jest prawdziwe:</b> nagrywanie kamerą i mikrofonem, dyktowanie głosem, rozmowa głosowa z asystentem, układ i wszystkie ekrany.
            {status.claude ? " Teksty i odpowiedzi asystenta pisze Claude." : " Teksty są przykładowe."}
          </p>
          <p>
            <b className="text-zinc-900">Co jest symulowane:</b> trening klonów, render wideo i zdjęcia AI. W pełnej wersji robią to kie.ai, HeyGen i Tavus po dodaniu kluczy API na serwerze.
          </p>
          <p>Twoje dane (nagrania, klony, ustawienia) zostają tylko w tej przeglądarce i nigdzie nie są wysyłane.</p>
          <ConfirmButton
            confirmLabel="Na pewno? Usuń wszystkie dane testowe"
            onConfirm={async () => {
              await api("/api/reset", { method: "POST" });
              window.location.reload();
            }}
          >
            Wyczyść dane i zacznij od nowa
          </ConfirmButton>
        </Card>
      </>
    );
  }

  return (
    <>
      <PageHeader title="Integracje" subtitle="Klucze API ustawiasz w pliku .env.local (lokalnie) albo w zmiennych środowiskowych hostingu. Po zmianie zrestartuj aplikację." />
      <div className="space-y-3">
        {PROVIDERS.map((p) => {
          const on = status?.[p.key];
          return (
            <Card key={p.key} className="flex flex-col gap-2 sm:flex-row sm:items-start">
              <div className="flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <a href={p.link} target="_blank" rel="noreferrer" className="font-semibold hover:underline">
                    {p.name}
                  </a>
                  <span className={cx("rounded-full px-2 py-0.5 text-xs font-medium", on ? "bg-emerald-50 text-emerald-700" : "bg-amber-50 text-amber-700")}>
                    {status === null ? "…" : on ? "połączono" : p.demo === "—" ? "nieustawione" : "tryb demo"}
                  </span>
                </div>
                <p className="mt-1 text-sm text-zinc-600">{p.what}</p>
                {!on && p.demo !== "—" && <p className="mt-1 text-xs text-zinc-500">Bez klucza: {p.demo}</p>}
              </div>
              <code className="self-start rounded bg-zinc-100 px-2 py-1 text-xs">{p.env}</code>
            </Card>
          );
        })}
      </div>
      <Card className="mt-6 text-sm text-zinc-600">
        <p className="font-semibold text-zinc-800">Odpowiedzialne klonowanie</p>
        <ul className="mt-2 list-disc space-y-1 pl-5">
          <li>Klon realnej osoby tworzymy tylko za jej zgodą — zapisujemy oświadczenie, a HeyGen i Tavus weryfikują zgodę po swojej stronie.</li>
          <li>Wirtualni influencerzy są fikcyjni — nie twórz postaci łudząco podobnych do znanych osób.</li>
          <li>Treści i asystent na stronie są oznaczone jako AI (ustawienie w profilu marki, informacja w widgecie).</li>
        </ul>
      </Card>
    </>
  );
}

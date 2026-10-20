"use client";

import { useEffect, useState } from "react";
import { api } from "@/components/api";
import { Card, PageHeader, cx } from "@/components/ui";
import type { ProviderStatus } from "@/lib/providers/status";

const PROVIDERS: { key: keyof ProviderStatus; name: string; env: string; what: string; demo: string; link: string }[] = [
  {
    key: "claude",
    name: "Claude (Anthropic)",
    env: "ANTHROPIC_API_KEY",
    what: "Skrypty wideo, opisy postów, hashtagi, plan treści, projektowanie person influencerów.",
    demo: "Przykładowe, szablonowe teksty.",
    link: "https://platform.claude.com",
  },
  {
    key: "heygen",
    name: "HeyGen",
    env: "HEYGEN_API_KEY",
    what: "Cyfrowe bliźniaki z wideo, mówiące awatary ze zdjęcia, renderowanie filmów z klonem (API v3).",
    demo: "Symulowany podgląd wideo ze zdjęciem klona i napisami.",
    link: "https://www.heygen.com",
  },
  {
    key: "elevenlabs",
    name: "ElevenLabs",
    env: "ELEVENLABS_API_KEY",
    what: "Klonowanie głosu z nagrania i synteza mowy dla klonów ze zdjęć i influencerów.",
    demo: "Próbka głosu jest zapisywana, ale nie klonowana.",
    link: "https://elevenlabs.io",
  },
  {
    key: "fal",
    name: "fal.ai",
    env: "FAL_KEY",
    what: "Trening modelu twarzy ze zdjęć (LoRA), sesje zdjęciowe AI, twarze wirtualnych influencerów.",
    demo: "Ilustracje zastępcze zamiast zdjęć.",
    link: "https://fal.ai",
  },
  {
    key: "publicUrl",
    name: "Publiczny adres aplikacji",
    env: "PUBLIC_BASE_URL",
    what: "Pozwala HeyGen pobrać nagrany przez Ciebie głos (tryb „Mój głos”). Bez niego plik trafia do magazynu fal.ai.",
    demo: "—",
    link: "https://ngrok.com",
  },
];

export default function SettingsPage() {
  const [status, setStatus] = useState<ProviderStatus | null>(null);
  useEffect(() => {
    api<ProviderStatus>("/api/status").then(setStatus, () => undefined);
  }, []);

  return (
    <>
      <PageHeader title="Integracje" subtitle="Klucze API ustawiasz w pliku .env.local (lokalnie) albo w zmiennych środowiskowych hostingu. Po zmianie zrestartuj aplikację." />
      <div className="space-y-3">
        {PROVIDERS.map((p) => {
          const on = status?.[p.key];
          return (
            <Card key={p.key} className="flex flex-col gap-2 sm:flex-row sm:items-start">
              <div className="flex-1">
                <div className="flex items-center gap-2">
                  <a href={p.link} target="_blank" rel="noreferrer" className="font-semibold hover:underline">
                    {p.name}
                  </a>
                  <span className={cx("rounded-full px-2 py-0.5 text-xs font-medium", on ? "bg-emerald-50 text-emerald-700" : "bg-amber-50 text-amber-700")}>
                    {status === null ? "…" : on ? "połączono" : "tryb demo"}
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
          <li>Klon realnej osoby tworzymy tylko za jej zgodą — zapisujemy oświadczenie i (dla bliźniaków) przechodzi ona weryfikację w HeyGen.</li>
          <li>Wirtualni influencerzy są fikcyjni — nie twórz postaci łudząco podobnych do znanych osób.</li>
          <li>Treści oznaczamy jako wygenerowane przez AI (ustawienie w profilu marki).</li>
        </ul>
      </Card>
    </>
  );
}

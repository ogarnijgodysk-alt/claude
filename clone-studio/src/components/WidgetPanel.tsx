"use client";

import { useEffect, useState } from "react";
import { api } from "./api";
import { VoiceChat } from "./VoiceChat";
import { cx } from "./ui";

export interface PublicAssistant {
  id: string;
  name: string;
  greeting: string;
  company: string;
  cover?: string;
  /** A Tavus replica is ready: offer a live video call. */
  video: boolean;
  voice: boolean;
  language?: string;
}

/**
 * What a website visitor sees: a live video call with the clone (Tavus) when available,
 * otherwise a voice conversation with the clone's photo. Used by /widget/[id] and the in-app preview.
 */
export function WidgetPanel({ assistantId, onClose, className }: { assistantId: string; onClose?: () => void; className?: string }) {
  const [info, setInfo] = useState<PublicAssistant | null>(null);
  const [call, setCall] = useState<{ url: string } | null>(null);
  const [mode, setMode] = useState<"choose" | "video" | "voice">("choose");
  const [error, setError] = useState<string | null>(null);
  const [starting, setStarting] = useState(false);

  useEffect(() => {
    api<PublicAssistant>(`/api/assistants/${assistantId}/public`).then(
      (a) => {
        setInfo(a);
        if (!a.video) setMode("voice");
      },
      (e) => setError(e.message),
    );
  }, [assistantId]);

  async function startVideo() {
    setStarting(true);
    setError(null);
    try {
      const res = await api<{ mode: "tavus"; url: string } | { mode: "voice" }>(`/api/assistants/${assistantId}/conversation`, { method: "POST" });
      if (res.mode === "tavus") {
        setCall({ url: res.url });
        setMode("video");
      } else {
        setMode("voice");
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      setStarting(false);
    }
  }

  return (
    <div className={cx("flex h-full min-h-0 flex-col bg-white", className)}>
      <header className="flex items-center gap-3 border-b border-zinc-100 px-4 py-3">
        {info?.cover ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={info.cover} alt="" className="h-9 w-9 rounded-full object-cover" />
        ) : (
          <span className="h-9 w-9 rounded-full bg-gradient-to-br from-brand-400 to-pink-400" />
        )}
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-semibold">{info?.name ?? "Asystent"}</p>
          <p className="flex items-center gap-1 text-xs text-emerald-600">
            <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" /> online{info?.company && ` · ${info.company}`}
          </p>
        </div>
        {onClose && (
          <button onClick={onClose} aria-label="Zamknij" className="grid h-8 w-8 place-items-center rounded-full text-zinc-500 hover:bg-zinc-100">
            ✕
          </button>
        )}
      </header>

      <div className="min-h-0 flex-1 p-4">
        {error && <p className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>}
        {!info && !error && <p className="text-center text-sm text-zinc-400">Łączenie…</p>}

        {info && mode === "choose" && (
          <div className="flex h-full flex-col items-center justify-center gap-4 text-center">
            {info.cover && (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={info.cover} alt={info.name} className="h-28 w-28 rounded-full object-cover shadow-lg" />
            )}
            <p className="max-w-xs text-sm text-zinc-600">{info.greeting}</p>
            <button onClick={startVideo} disabled={starting} className="w-full max-w-xs rounded-full bg-brand-600 py-3 text-sm font-semibold text-white shadow hover:bg-brand-700 disabled:opacity-60">
              {starting ? "Łączę…" : "🎥 Rozmowa wideo na żywo"}
            </button>
            <button onClick={() => setMode("voice")} className="text-sm text-zinc-600 underline">
              Wolę rozmowę głosową lub czat
            </button>
          </div>
        )}

        {info && mode === "video" && call && (
          <iframe
            src={call.url}
            title={`Rozmowa z ${info.name}`}
            allow="camera; microphone; autoplay; fullscreen; display-capture"
            className="h-full w-full rounded-xl border-0 bg-black"
          />
        )}

        {info && mode === "voice" && (
          <VoiceChat assistantId={info.id} name={info.name} cover={info.cover} greeting={info.greeting} language={info.language} compact />
        )}
      </div>
      <p className="pb-2 text-center text-[10px] text-zinc-400">Rozmawiasz z asystentem AI</p>
    </div>
  );
}

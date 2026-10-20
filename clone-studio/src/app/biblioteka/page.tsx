"use client";

import { useEffect, useState } from "react";
import { api, usePolling } from "@/components/api";
import { VideoPreview } from "@/components/media";
import { Button, Card, DemoBadge, Empty, ErrorNote, LinkButton, PageHeader, StatusBadge } from "@/components/ui";
import { PLATFORMS, type Clone, type Video } from "@/lib/types";

export default function LibraryPage() {
  const { data: videos, setData, error } = usePolling<Video[]>("/api/videos", (vs) => vs.some((v) => v.status === "processing"), 5000);
  const [clones, setClones] = useState<Record<string, Clone>>({});
  const [copied, setCopied] = useState<string | null>(null);

  useEffect(() => {
    api<Clone[]>("/api/clones").then((cs) => setClones(Object.fromEntries(cs.map((c) => [c.id, c]))), () => undefined);
  }, []);

  async function copy(v: Video) {
    const tags = v.hashtags.map((h) => `#${h}`).join(" ");
    await navigator.clipboard.writeText([v.caption, tags].filter(Boolean).join("\n\n"));
    setCopied(v.id);
    setTimeout(() => setCopied(null), 1500);
  }

  async function remove(v: Video) {
    if (!confirm(`Usunąć „${v.title}”?`)) return;
    await api(`/api/videos/${v.id}`, { method: "DELETE" });
    setData((videos ?? []).filter((x) => x.id !== v.id));
  }

  return (
    <>
      <PageHeader title="Biblioteka" subtitle="Wszystkie nagrania Twoich klonów — gotowe do pobrania i publikacji." actions={<LinkButton href="/studio">● Nowe wideo</LinkButton>} />
      <ErrorNote error={error} />
      {videos?.length === 0 && (
        <Empty title="Biblioteka jest pusta">
          <LinkButton href="/studio">Nagraj pierwsze wideo</LinkButton>
        </Empty>
      )}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {videos?.map((v) => (
          <Card key={v.id} className="flex flex-col gap-3 p-4">
            <VideoPreview video={v} clone={clones[v.cloneId]} fit="height" />
            <div className="flex flex-wrap items-center gap-2">
              <StatusBadge status={v.status} label={v.status === "processing" ? "Renderowanie…" : undefined} />
              {v.demo && <DemoBadge />}
              <span className="text-xs text-zinc-500">
                {PLATFORMS[v.platform].label} · {clones[v.cloneId]?.name ?? "usunięty klon"} · {v.mode === "voice" ? "Twój głos" : "skrypt"}
              </span>
            </div>
            <p className="font-medium">{v.title}</p>
            <ErrorNote error={v.error} />
            {v.caption && <p className="line-clamp-3 whitespace-pre-line text-sm text-zinc-600">{v.caption}</p>}
            {v.hashtags.length > 0 && <p className="text-xs text-brand-700">{v.hashtags.map((h) => `#${h}`).join(" ")}</p>}
            <div className="mt-auto flex flex-wrap gap-2 pt-1">
              <Button variant="secondary" onClick={() => copy(v)} className="px-3 py-1.5 text-xs">
                {copied === v.id ? "✓ Skopiowano" : "Kopiuj opis"}
              </Button>
              {v.videoUrl && (
                <a href={v.videoUrl} download={`${v.title}.mp4`} className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-700">
                  Pobierz MP4
                </a>
              )}
              <Button variant="ghost" onClick={() => remove(v)} className="ml-auto px-3 py-1.5 text-xs">
                Usuń
              </Button>
            </div>
          </Card>
        ))}
      </div>
    </>
  );
}

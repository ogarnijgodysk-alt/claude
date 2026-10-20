"use client";

import { useSearchParams } from "next/navigation";
import { Suspense, useEffect, useState } from "react";
import { api, usePolling } from "@/components/api";
import { CloneAvatar } from "@/components/media";
import { Button, Card, DemoBadge, Empty, ErrorNote, Field, Input, LinkButton, PageHeader, Segmented, StatusBadge, cx } from "@/components/ui";
import type { AspectRatio, Clone, PhotoSet } from "@/lib/types";

const SCENES = [
  { id: "biuro", label: "Biuro / headshot" },
  { id: "konferencja", label: "Scena konferencji" },
  { id: "produkt", label: "Z produktem" },
  { id: "kawiarnia", label: "Kawiarnia" },
  { id: "plener", label: "Miasto, złota godzina" },
  { id: "studio", label: "Studio, okładka" },
  { id: "podcast", label: "Podcast" },
  { id: "sport", label: "Siłownia" },
];

export default function PhotoShootPage() {
  return (
    <Suspense>
      <PhotoShoot />
    </Suspense>
  );
}

function PhotoShoot() {
  const params = useSearchParams();
  const [clones, setClones] = useState<Clone[] | null>(null);
  const [cloneId, setCloneId] = useState(params.get("clone") ?? "");
  const [scene, setScene] = useState("biuro");
  const [prompt, setPrompt] = useState("");
  const [aspect, setAspect] = useState<AspectRatio>("1:1");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const { data: sets, reload } = usePolling<PhotoSet[]>("/api/photos", (s) => s.some((p) => p.status === "processing"), 5000);

  useEffect(() => {
    api<Clone[]>("/api/clones").then((cs) => {
      setClones(cs);
      setCloneId((cur) => cur || cs[0]?.id || "");
    }, (e) => setError(e.message));
  }, []);

  const clone = clones?.find((c) => c.id === cloneId);
  const cloneById = Object.fromEntries((clones ?? []).map((c) => [c.id, c]));

  async function generate() {
    setSubmitting(true);
    setError(null);
    try {
      await api<PhotoSet>("/api/photos", { method: "POST", json: { cloneId, scene, prompt, aspect, count: 4 } });
      reload();
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      setSubmitting(false);
    }
  }

  if (clones && clones.length === 0) {
    return (
      <>
        <PageHeader title="Sesje zdjęciowe AI" />
        <Empty title="Najpierw stwórz klona">
          <LinkButton href="/klony/nowy">Stwórz klona</LinkButton>
        </Empty>
      </>
    );
  }

  return (
    <>
      <PageHeader title="Sesje zdjęciowe AI" subtitle="Zdjęcia Twojego klona w dowolnej scenerii — na posty, okładki, LinkedIn i reklamy. Bez fotografa." />
      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="min-w-0 space-y-4 lg:col-span-1">
          <Field label="Klon">
            <div className="flex gap-2 overflow-x-auto pb-1">
              {clones?.map((c) => (
                <button key={c.id} onClick={() => setCloneId(c.id)} className={cx("w-16 shrink-0 overflow-hidden rounded-lg ring-2", cloneId === c.id ? "ring-brand-500" : "ring-transparent")} title={c.name}>
                  <CloneAvatar clone={c} className="aspect-square w-full" />
                </button>
              ))}
            </div>
          </Field>
          {clone?.kind === "photo_clone" && clone.loraStatus === "processing" && (
            <p className="rounded-lg bg-amber-50 p-3 text-xs text-amber-800">Model tego klona jeszcze się trenuje. Możesz generować już teraz (na podstawie okładki), ale po treningu podobieństwo będzie dużo lepsze.</p>
          )}
          <Field label="Sceneria">
            <div className="grid grid-cols-2 gap-2">
              {SCENES.map((s) => (
                <button key={s.id} onClick={() => setScene(s.id)} className={cx("rounded-lg px-2 py-2 text-left text-xs ring-1", scene === s.id ? "bg-brand-50 font-medium text-brand-700 ring-brand-300" : "ring-zinc-200 hover:bg-zinc-50")}>
                  {s.label}
                </button>
              ))}
            </div>
          </Field>
          <Field label="Dodatkowy opis (opcjonalnie)" hint="Najlepiej po angielsku, np. wearing a navy blazer, smiling">
            <Input value={prompt} onChange={(e) => setPrompt(e.target.value)} />
          </Field>
          <Field label="Format">
            <Segmented value={aspect} onChange={setAspect} options={[{ value: "1:1", label: "1:1" }, { value: "9:16", label: "9:16" }, { value: "16:9", label: "16:9" }]} />
          </Field>
          <ErrorNote error={error} />
          <Button className="w-full" onClick={generate} loading={submitting} disabled={!cloneId}>
            Zrób 4 zdjęcia
          </Button>
        </Card>

        <div className="min-w-0 space-y-4 lg:col-span-2">
          {sets?.length === 0 && <Empty title="Tu pojawią się Twoje sesje" />}
          {sets?.map((s) => (
            <Card key={s.id} className="space-y-3">
              <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm font-medium">{cloneById[s.cloneId]?.name ?? "Klon"}</span>
                <span className="text-xs text-zinc-500">{SCENES.find((x) => x.id === s.scene)?.label}</span>
                <StatusBadge status={s.status} />
                {s.demo && <DemoBadge />}
              </div>
              <ErrorNote error={s.error} />
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                {s.images.map((src) => (
                  <a key={src} href={src} target="_blank" rel="noreferrer" download>
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img src={src} alt="" className="w-full rounded-lg object-cover" />
                  </a>
                ))}
                {s.status === "processing" &&
                  s.images.length === 0 &&
                  Array.from({ length: 4 }, (_, i) => <div key={i} className="aspect-square animate-pulse rounded-lg bg-zinc-100" />)}
              </div>
            </Card>
          ))}
        </div>
      </div>
    </>
  );
}

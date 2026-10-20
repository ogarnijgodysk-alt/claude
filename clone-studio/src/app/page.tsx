"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { api } from "@/components/api";
import { CloneAvatar, VideoPreview } from "@/components/media";
import { Card, LinkButton, PageHeader, StatusBadge } from "@/components/ui";
import type { Brand, Clone, Video } from "@/lib/types";
import { PLATFORMS } from "@/lib/types";

const ACTIONS = [
  { href: "/klony/nowy", title: "Stwórz klona", text: "Cyfrowy bliźniak z nagrania, klon ze zdjęć albo wirtualny influencer.", tone: "from-brand-500 to-pink-500" },
  { href: "/studio", title: "Nagraj wideo klonem", text: "Wpisz lub wygeneruj skrypt — albo powiedz go swoim głosem.", tone: "from-sky-500 to-indigo-500" },
  { href: "/pomysly", title: "Zaplanuj tydzień treści", text: "AI przygotuje pomysły na filmy dopasowane do Twojej marki.", tone: "from-amber-500 to-rose-500" },
];

export default function Dashboard() {
  const [brand, setBrand] = useState<Brand | null>(null);
  const [clones, setClones] = useState<Clone[]>([]);
  const [videos, setVideos] = useState<Video[]>([]);

  useEffect(() => {
    api<Brand>("/api/brand").then(setBrand, () => undefined);
    api<Clone[]>("/api/clones").then(setClones, () => undefined);
    api<Video[]>("/api/videos").then(setVideos, () => undefined);
  }, []);

  const steps = [
    { done: Boolean(brand?.name), label: "Uzupełnij profil marki", href: "/marka" },
    { done: clones.length > 0, label: "Stwórz pierwszego klona", href: "/klony/nowy" },
    { done: videos.length > 0, label: "Nagraj pierwsze wideo", href: "/studio" },
  ];
  const cloneById = Object.fromEntries(clones.map((c) => [c.id, c]));

  return (
    <>
      <PageHeader title={brand?.name ? `Cześć, ${brand.name} 👋` : "Witaj w CloneStudio"} subtitle="Twoje klony AI nagrywają treści do social mediów, kiedy Ty prowadzisz firmę." />

      <div className="grid gap-4 md:grid-cols-3">
        {ACTIONS.map((a) => (
          <Link key={a.href} href={a.href} className="group overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-zinc-200/70 transition hover:-translate-y-0.5 hover:shadow-md">
            <div className={`h-1.5 bg-gradient-to-r ${a.tone}`} />
            <div className="p-5">
              <p className="font-semibold">{a.title} →</p>
              <p className="mt-1 text-sm text-zinc-500">{a.text}</p>
            </div>
          </Link>
        ))}
      </div>

      {steps.some((s) => !s.done) && (
        <Card className="mt-6">
          <p className="mb-3 font-semibold">Pierwsze kroki</p>
          <ol className="space-y-2">
            {steps.map((s, i) => (
              <li key={s.label}>
                <Link href={s.href} className="flex items-center gap-3 text-sm">
                  <span className={`grid h-6 w-6 place-items-center rounded-full text-xs font-semibold ${s.done ? "bg-emerald-500 text-white" : "bg-zinc-100 text-zinc-500"}`}>{s.done ? "✓" : i + 1}</span>
                  <span className={s.done ? "text-zinc-400 line-through" : "text-zinc-800 hover:underline"}>{s.label}</span>
                </Link>
              </li>
            ))}
          </ol>
        </Card>
      )}

      <div className="mt-8 grid gap-6 lg:grid-cols-3">
        <section className="lg:col-span-1">
          <div className="mb-3 flex items-center justify-between">
            <h2 className="font-semibold">Twoje klony</h2>
            <Link href="/klony" className="text-sm text-brand-700 hover:underline">
              Wszystkie
            </Link>
          </div>
          <div className="space-y-2">
            {clones.slice(0, 4).map((c) => (
              <Link key={c.id} href={`/klony/${c.id}`} className="flex items-center gap-3 rounded-xl bg-white p-3 ring-1 ring-zinc-200/70 hover:ring-brand-200">
                <CloneAvatar clone={c} className="h-11 w-11 rounded-lg" />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">{c.name}</p>
                  <StatusBadge status={c.status} />
                </div>
              </Link>
            ))}
            {clones.length === 0 && <p className="text-sm text-zinc-500">Brak klonów.</p>}
          </div>
        </section>
        <section className="lg:col-span-2">
          <div className="mb-3 flex items-center justify-between">
            <h2 className="font-semibold">Ostatnie wideo</h2>
            <Link href="/biblioteka" className="text-sm text-brand-700 hover:underline">
              Biblioteka
            </Link>
          </div>
          {videos.length === 0 ? (
            <Card className="text-sm text-zinc-500">
              Jeszcze nic nie nagrano. <LinkButton href="/studio" variant="ghost" className="px-1 py-0 text-brand-700">Przejdź do studia →</LinkButton>
            </Card>
          ) : (
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              {videos.slice(0, 4).map((v) => (
                <div key={v.id}>
                  <VideoPreview video={v} clone={cloneById[v.cloneId]} />
                  <p className="mt-1 truncate text-xs font-medium">{v.title}</p>
                  <p className="text-xs text-zinc-500">{PLATFORMS[v.platform].label}</p>
                </div>
              ))}
            </div>
          )}
        </section>
      </div>
    </>
  );
}

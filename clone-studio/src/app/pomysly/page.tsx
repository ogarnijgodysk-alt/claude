"use client";

import { useState } from "react";
import { appendText, DictateButton } from "@/components/DictateButton";
import { api } from "@/components/api";
import { Button, Card, ErrorNote, Field, Input, LinkButton, PageHeader, Select, cx } from "@/components/ui";
import type { Ideas } from "@/lib/content";
import { PLATFORMS, type Platform } from "@/lib/types";

const DAYS = ["Pon", "Wt", "Śr", "Czw", "Pt", "Sob", "Nd"];

export default function IdeasPage() {
  const [count, setCount] = useState(7);
  const [platforms, setPlatforms] = useState<Platform[]>(["tiktok", "reels", "linkedin"]);
  const [focus, setFocus] = useState("");
  const [ideas, setIdeas] = useState<Ideas["ideas"]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const toggle = (p: Platform) => setPlatforms((cur) => (cur.includes(p) ? cur.filter((x) => x !== p) : [...cur, p]));

  async function generate() {
    setLoading(true);
    setError(null);
    try {
      const res = await api<Ideas>("/api/ai/ideas", { method: "POST", json: { count, platforms, focus } });
      setIdeas(res.ideas);
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      setLoading(false);
    }
  }

  return (
    <>
      <PageHeader title="Plan treści" subtitle="AI przygotuje pomysły na filmy na podstawie profilu marki. Każdy pomysł otworzysz w studiu jednym kliknięciem." />
      <Card className="space-y-4">
        <div className="grid gap-4 md:grid-cols-4">
          <Field label="Liczba pomysłów">
            <Select value={count} onChange={(e) => setCount(Number(e.target.value))}>
              {[5, 7, 10, 14].map((n) => (
                <option key={n} value={n}>
                  {n}
                </option>
              ))}
            </Select>
          </Field>
          <div className="md:col-span-3">
            <Field label="Na czym się skupić (opcjonalnie)">
              <div className="flex gap-2">
                <Input value={focus} onChange={(e) => setFocus(e.target.value)} placeholder="np. premiera nowej usługi, Black Friday, rekrutacja handlowców" />
                <DictateButton onText={(t) => setFocus((v) => appendText(v, t))} />
              </div>
            </Field>
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          {(Object.keys(PLATFORMS) as Platform[]).map((p) => (
            <button
              key={p}
              onClick={() => toggle(p)}
              className={cx("rounded-full px-3 py-1 text-sm ring-1 transition", platforms.includes(p) ? "bg-brand-600 text-white ring-brand-600" : "bg-white text-zinc-600 ring-zinc-200 hover:ring-zinc-300")}
            >
              {PLATFORMS[p].label}
            </button>
          ))}
        </div>
        <ErrorNote error={error} />
        <Button onClick={generate} loading={loading} disabled={platforms.length === 0}>
          ✦ Wygeneruj plan
        </Button>
      </Card>

      {ideas.length > 0 && (
        <div className="mt-6 grid gap-4 md:grid-cols-2">
          {ideas.map((idea, i) => {
            const q = new URLSearchParams({ topic: idea.title, platform: idea.platform, notes: `Hook: ${idea.hook}. Format: ${idea.format}. ${idea.outline}`, goal: idea.goal });
            return (
              <Card key={i} className="flex flex-col gap-2">
                <div className="flex items-center gap-2 text-xs">
                  <span className="rounded-full bg-zinc-900 px-2 py-0.5 font-semibold text-white">{DAYS[i % 7]}</span>
                  <span className="text-zinc-500">
                    {PLATFORMS[idea.platform]?.label} · {idea.format} · {idea.goal}
                  </span>
                </div>
                <p className="font-semibold">{idea.title}</p>
                <p className="text-sm italic text-zinc-700">„{idea.hook}”</p>
                <p className="flex-1 text-sm text-zinc-500">{idea.outline}</p>
                <LinkButton href={`/studio?${q}`} variant="secondary" className="self-start">
                  Nagraj w studiu →
                </LinkButton>
              </Card>
            );
          })}
        </div>
      )}
    </>
  );
}

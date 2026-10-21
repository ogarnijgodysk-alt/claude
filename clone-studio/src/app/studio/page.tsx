"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { Suspense, useEffect, useState } from "react";
import { BROWSER_MODE, api, usePolling } from "@/components/api";
import { ASPECT_CLASS, CloneAvatar, VideoPreview, kindLabel, sizeClass } from "@/components/media";
import { appendText, DictateButton } from "@/components/DictateButton";
import { Recorder, type Recording } from "@/components/Recorder";
import { speak, stopSpeaking } from "@/components/voice";
import { Button, Card, DemoBadge, Empty, ErrorNote, Field, Input, LinkButton, PageHeader, Segmented, Select, StatusBadge, Textarea, cx } from "@/components/ui";
import type { Script } from "@/lib/content";
import { PLATFORMS, type Clone, type Platform, type Video } from "@/lib/types";

const GOALS = ["zasięg i nowi obserwujący", "zaufanie i ekspertyza", "sprzedaż / promocja", "rekrutacja", "edukacja klientów"];

export default function StudioPage() {
  return (
    <Suspense>
      <Studio />
    </Suspense>
  );
}

function Studio() {
  const params = useSearchParams();
  const [clones, setClones] = useState<Clone[] | null>(null);
  const [cloneId, setCloneId] = useState(params.get("clone") ?? "");
  const [platform, setPlatform] = useState<Platform>((params.get("platform") as Platform) in PLATFORMS ? (params.get("platform") as Platform) : "reels");
  const [goal, setGoal] = useState(params.get("goal") ?? GOALS[0]);
  const [mode, setMode] = useState<"script" | "voice">("script");

  const [topic, setTopic] = useState(params.get("topic") ?? "");
  const [notes, setNotes] = useState(params.get("notes") ?? "");
  const [seconds, setSeconds] = useState(30);
  const [writing, setWriting] = useState(false);

  const [title, setTitle] = useState(params.get("topic") ?? "");
  const [script, setScript] = useState("");
  const [caption, setCaption] = useState("");
  const [hashtags, setHashtags] = useState("");
  const [onScreen, setOnScreen] = useState<string[]>([]);
  const [listening, setListening] = useState(false);
  const [recording, setRecording] = useState<Recording | null>(null);

  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [videoId, setVideoId] = useState<string | null>(null);
  const { data: video } = usePolling<Video>(videoId ? `/api/videos/${videoId}` : null, (v) => v.status === "processing", 5000);

  useEffect(() => {
    api<Clone[]>("/api/clones").then((cs) => {
      setClones(cs);
      setCloneId((cur) => cur || cs.find((c) => c.status === "ready")?.id || "");
    }, (e) => setError(e.message));
  }, []);

  const clone = clones?.find((c) => c.id === cloneId);
  const words = script.trim() ? script.trim().split(/\s+/).length : 0;

  async function writeWithAi() {
    setWriting(true);
    setError(null);
    try {
      const s = await api<Script>("/api/ai/script", { method: "POST", json: { topic, platform, goal, seconds, cloneId, notes } });
      setTitle(s.title);
      setScript(s.script);
      setCaption(s.caption);
      setHashtags(s.hashtags.map((h) => `#${h.replace(/^#/, "")}`).join(" "));
      setOnScreen(s.onScreenText);
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      setWriting(false);
    }
  }

  async function generate() {
    setSubmitting(true);
    setError(null);
    try {
      const form = new FormData();
      form.set("cloneId", cloneId);
      form.set("title", title || topic);
      form.set("platform", platform);
      form.set("mode", mode);
      form.set("script", script);
      form.set("caption", caption);
      form.set("hashtags", hashtags);
      if (mode === "voice" && recording) form.set("audio", recording.blob, `voice.${recording.blob.type.includes("mp4") ? "m4a" : "webm"}`);
      const v = await api<Video>("/api/videos", { method: "POST", body: form });
      setVideoId(v.id);
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      setSubmitting(false);
    }
  }

  if (clones && clones.length === 0) {
    return (
      <>
        <PageHeader title="Studio nagrań" />
        <Empty title="Najpierw stwórz klona, który będzie nagrywał">
          <LinkButton href="/klony/nowy">Stwórz klona</LinkButton>
        </Empty>
      </>
    );
  }

  const canGenerate = clone?.status === "ready" && (mode === "script" ? script.trim().length > 0 : Boolean(recording));

  return (
    <>
      <PageHeader title="Studio nagrań" subtitle="Twój klon nagra film za Ciebie: z napisanego skryptu albo powtarzając to, co powiesz do mikrofonu." />
      <div className="grid gap-6 lg:grid-cols-5">
        <div className="min-w-0 space-y-6 lg:col-span-3">
          <Card className="space-y-3">
            <p className="font-semibold">1. Kto nagrywa?</p>
            <div className="flex gap-3 overflow-x-auto pb-1">
              {clones?.map((c) => (
                <button
                  key={c.id}
                  onClick={() => setCloneId(c.id)}
                  disabled={c.status !== "ready"}
                  className={cx("w-28 shrink-0 overflow-hidden rounded-xl text-left ring-2 transition disabled:opacity-50", cloneId === c.id ? "ring-brand-500" : "ring-transparent hover:ring-zinc-200")}
                >
                  <CloneAvatar clone={c} className="aspect-square w-full" />
                  <div className="p-2">
                    <p className="truncate text-xs font-medium">{c.name}</p>
                    <p className="truncate text-[11px] text-zinc-500">{c.status === "ready" ? kindLabel(c.kind) : "trenuje się…"}</p>
                  </div>
                </button>
              ))}
            </div>
          </Card>

          <Card className="space-y-4">
            <p className="font-semibold">2. Gdzie publikujesz?</p>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="Platforma">
                <Select value={platform} onChange={(e) => setPlatform(e.target.value as Platform)}>
                  {Object.entries(PLATFORMS).map(([k, p]) => (
                    <option key={k} value={k}>
                      {p.label} ({p.aspect})
                    </option>
                  ))}
                </Select>
              </Field>
              <Field label="Cel filmu">
                <Select value={goal} onChange={(e) => setGoal(e.target.value)}>
                  {[...new Set([goal, ...GOALS])].map((g) => (
                    <option key={g}>{g}</option>
                  ))}
                </Select>
              </Field>
            </div>
          </Card>

          <Card className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <p className="font-semibold">3. Co klon mówi?</p>
              <Segmented
                value={mode}
                onChange={setMode}
                options={[
                  { value: "script", label: "✎ Skrypt" },
                  { value: "voice", label: "🎙 Mój głos" },
                ]}
              />
            </div>

            <div className="space-y-3 rounded-xl bg-brand-50/60 p-4 ring-1 ring-brand-100">
              <p className="text-sm font-medium text-brand-700">✦ Asystent skryptów AI</p>
              <div className="grid gap-3 sm:grid-cols-4">
                <div className="flex gap-2 sm:col-span-3">
                  <Input value={topic} onChange={(e) => setTopic(e.target.value)} placeholder="Temat, np. 3 błędy przy wyborze kredytu hipotecznego" />
                  <DictateButton onText={(t) => setTopic((v) => appendText(v, t))} />
                </div>
                <Select value={seconds} onChange={(e) => setSeconds(Number(e.target.value))}>
                  {[15, 30, 45, 60, 90].map((s) => (
                    <option key={s} value={s}>
                      {s} s
                    </option>
                  ))}
                </Select>
              </div>
              <div className="flex gap-2">
                <Input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Dodatkowe wskazówki (opcjonalnie): promocja, fakty, styl…" />
                <DictateButton onText={(t) => setNotes((v) => appendText(v, t))} />
              </div>
              <Button variant="secondary" onClick={writeWithAi} loading={writing} disabled={!topic.trim()}>
                {script ? "Napisz od nowa" : "Napisz skrypt z AI"}
              </Button>
            </div>

            <Field label="Tytuł roboczy">
              <Input value={title} onChange={(e) => setTitle(e.target.value)} />
            </Field>
            <Field label={mode === "script" ? "Skrypt (tekst mówiony przez klona)" : "Tekst do promptera (opcjonalnie)"} hint={words ? `${words} słów · ok. ${Math.round(words / 2.4)} s` : undefined}>
              <Textarea value={script} onChange={(e) => setScript(e.target.value)} rows={7} placeholder="Wpisz, co ma powiedzieć klon — albo wygeneruj z AI powyżej." />
            </Field>
            <div className="flex flex-wrap gap-2">
              <DictateButton onText={(t) => setScript((v) => appendText(v, t))} />
              {script.trim() && (
                <Button
                  variant="secondary"
                  className="h-9 px-3"
                  onClick={() => {
                    if (listening) {
                      stopSpeaking();
                      setListening(false);
                    } else {
                      setListening(true);
                      speak(script, "pl-PL").then(() => setListening(false));
                    }
                  }}
                >
                  {listening ? "■ Zatrzymaj" : "▶ Odsłuchaj skrypt"}
                </Button>
              )}
            </div>
            {onScreen.length > 0 && (
              <div className="flex flex-wrap gap-2">
                <span className="text-xs text-zinc-500">Napisy na ekran:</span>
                {onScreen.map((t) => (
                  <span key={t} className="rounded-full bg-zinc-100 px-2 py-0.5 text-xs">
                    {t}
                  </span>
                ))}
              </div>
            )}

            {mode === "voice" && (
              <div className="space-y-2">
                <p className="text-sm text-zinc-600">Powiedz tekst swoimi słowami — klon zsynchronizuje usta z Twoim nagraniem. Świetne, gdy chcesz zachować własną intonację, a nie chcesz stawać przed kamerą.</p>
                <Recorder kind="audio" teleprompter={script || undefined} maxSeconds={180} onChange={setRecording} />
                <label className="block text-xs text-zinc-500">
                  …albo wgraj gotowe nagranie (mp3, m4a, wav):
                  <input
                    type="file"
                    accept="audio/*"
                    className="mt-1 block w-full text-xs"
                    onChange={(e) => {
                      const f = e.target.files?.[0];
                      setRecording(f ? { blob: f, url: URL.createObjectURL(f), seconds: 0 } : null);
                    }}
                  />
                </label>
              </div>
            )}
          </Card>

          <Card className="space-y-4">
            <p className="font-semibold">4. Opis posta</p>
            <Textarea value={caption} onChange={(e) => setCaption(e.target.value)} rows={4} placeholder="Opis pod filmem" />
            <Input value={hashtags} onChange={(e) => setHashtags(e.target.value)} placeholder="#hashtagi" />
          </Card>
        </div>

        <div className="lg:col-span-2">
          <div className="space-y-4 lg:sticky lg:top-8">
            <Card className="space-y-4">
              <div className="flex items-center justify-between">
                <p className="font-semibold">Podgląd</p>
                <span className="text-xs text-zinc-500">
                  {PLATFORMS[platform].label} · {PLATFORMS[platform].aspect}
                </span>
              </div>
              {video ? (
                <VideoPreview video={video} clone={clone} fit="height" />
              ) : (
                <div className={cx("relative overflow-hidden rounded-xl bg-zinc-100", ASPECT_CLASS[PLATFORMS[platform].aspect], sizeClass(PLATFORMS[platform].aspect, "height"))}>
                  {clone && <CloneAvatar clone={clone} className="absolute inset-0 h-full w-full opacity-90" />}
                  {script && <p className="absolute inset-x-3 bottom-4 line-clamp-3 text-center text-sm font-semibold text-white drop-shadow">{script.split(/[.!?]/)[0]}</p>}
                </div>
              )}
              {video && (
                <div className="flex items-center gap-2">
                  <StatusBadge status={video.status} label={video.status === "processing" ? "Renderowanie…" : undefined} />
                  {video.demo && <DemoBadge />}
                  {video.status === "ready" && (
                    <Link href="/biblioteka" className="ml-auto text-sm text-brand-700 hover:underline">
                      W bibliotece →
                    </Link>
                  )}
                </div>
              )}
              <ErrorNote error={video?.error ?? error} />
              <Button className="w-full" onClick={generate} loading={submitting} disabled={!canGenerate}>
                {video ? "Wygeneruj kolejne wideo" : "Generuj wideo"}
              </Button>
              {clone && clone.status !== "ready" && <p className="text-xs text-amber-700">Ten klon jeszcze się trenuje.</p>}
              {clone?.demo && (
                <p className="text-xs text-zinc-500">
                  {BROWSER_MODE
                    ? "Wersja testowa — wideo jest symulowanym podglądem (zdjęcie klona + napisy + Twoje nagranie). W pełnej wersji renderują je kie.ai lub HeyGen."
                    : "Klon w trybie demo — wideo będzie symulowanym podglądem. Dodaj KIE_API_KEY lub HEYGEN_API_KEY i stwórz klona ponownie, aby renderować prawdziwe filmy."}
                </p>
              )}
            </Card>
          </div>
        </div>
      </div>
    </>
  );
}

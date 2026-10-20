"use client";

import { useParams, useRouter } from "next/navigation";
import { useState } from "react";
import { api, usePolling } from "@/components/api";
import { CloneAvatar, fileSrc, kindLabel } from "@/components/media";
import { Button, Card, DemoBadge, ErrorNote, Input, LinkButton, PageHeader, Select, StatusBadge } from "@/components/ui";
import type { Clone } from "@/lib/types";

const VOICE_LABEL: Record<Clone["voice"]["provider"], string> = {
  heygen: "HeyGen",
  elevenlabs: "ElevenLabs (klon głosu)",
  none: "brak — ustaw poniżej",
};

export default function CloneDetailPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { data: clone, setData, error } = usePolling<Clone>(`/api/clones/${id}`, (c) => c.status === "processing" || c.loraStatus === "processing", 5000);
  const [voiceProvider, setVoiceProvider] = useState<"heygen" | "elevenlabs">("elevenlabs");
  const [voiceId, setVoiceId] = useState("");
  const [busy, setBusy] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  if (!clone) return <ErrorNote error={error} />;

  async function run(key: string, fn: () => Promise<void>) {
    setBusy(key);
    setActionError(null);
    try {
      await fn();
    } catch (e) {
      setActionError(e instanceof Error ? e.message : String(e));
    } finally {
      setBusy(null);
    }
  }

  const saveVoice = () =>
    run("voice", async () => {
      setData(await api<Clone>(`/api/clones/${id}`, { method: "PATCH", json: { voiceProvider, voiceId } }));
      setVoiceId("");
    });
  const renewConsent = () => run("consent", async () => setData(await api<Clone>(`/api/clones/${id}/consent`, { method: "POST" })));
  const remove = () =>
    run("delete", async () => {
      if (!confirm(`Usunąć klona „${clone.name}”? Wideo w bibliotece zostaną.`)) return;
      await api(`/api/clones/${id}`, { method: "DELETE" });
      router.push("/klony");
    });

  const consentPending = clone.heygen?.consentUrl && clone.heygen.consentStatus !== "approved" && clone.heygen.consentStatus !== "completed";

  return (
    <>
      <PageHeader
        title={clone.name}
        subtitle={kindLabel(clone.kind)}
        actions={
          <>
            <LinkButton href={`/studio?clone=${clone.id}`} className={clone.status !== "ready" ? "pointer-events-none opacity-50" : ""}>
              ● Nagraj wideo
            </LinkButton>
            <LinkButton href={`/sesja?clone=${clone.id}`} variant="secondary">
              Sesja zdjęciowa
            </LinkButton>
          </>
        }
      />
      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="space-y-4">
          <CloneAvatar clone={clone} className="aspect-[4/5] w-full rounded-xl" />
          <div className="flex flex-wrap items-center gap-2">
            <StatusBadge status={clone.status} label={clone.status === "processing" ? "Awatar trenuje się…" : clone.status === "ready" ? "Gotowy do nagrań" : undefined} />
            {clone.demo && <DemoBadge />}
          </div>
          {clone.status === "processing" && <p className="text-sm text-zinc-500">Trening trwa zwykle kilka minut. Możesz zamknąć tę stronę — wrócimy do niego automatycznie.</p>}
          <ErrorNote error={clone.error} />
        </Card>

        <div className="space-y-6 lg:col-span-2">
          {consentPending && (
            <Card className="space-y-3 ring-amber-300">
              <p className="font-semibold">Weryfikacja zgody w HeyGen</p>
              <p className="text-sm text-zinc-600">
                Zanim cyfrowy bliźniak zacznie nagrywać, osoba klonowana musi potwierdzić zgodę przez kamerę. Wyślij jej ten link (ważny 24 h).
              </p>
              <div className="flex flex-wrap gap-2">
                <a href={clone.heygen!.consentUrl} target="_blank" rel="noreferrer" className="rounded-lg bg-amber-500 px-4 py-2 text-sm font-medium text-white hover:bg-amber-600">
                  Otwórz link zgody
                </a>
                <Button variant="secondary" onClick={() => navigator.clipboard.writeText(clone.heygen!.consentUrl!)}>
                  Kopiuj link
                </Button>
                <Button variant="ghost" onClick={renewConsent} loading={busy === "consent"}>
                  Wygeneruj nowy
                </Button>
              </div>
              {clone.heygen?.consentStatus && <p className="text-xs text-zinc-500">Status zgody: {clone.heygen.consentStatus}</p>}
            </Card>
          )}

          <Card className="space-y-3">
            <p className="font-semibold">Persona</p>
            <p className="whitespace-pre-line text-sm text-zinc-700">{clone.description || "—"}</p>
            {clone.consent.subjectName && (
              <p className="text-xs text-zinc-500">
                Zgoda: {clone.consent.subjectName}, {new Date(clone.consent.at).toLocaleString("pl-PL")}
                {clone.consentVideoFile && (
                  <>
                    {" · "}
                    <a href={fileSrc(clone.consentVideoFile)} target="_blank" rel="noreferrer" className="text-brand-700 underline">
                      nagranie zgody
                    </a>
                  </>
                )}
              </p>
            )}
          </Card>

          <Card className="space-y-3">
            <p className="font-semibold">Głos</p>
            <p className="text-sm text-zinc-700">
              {VOICE_LABEL[clone.voice.provider]}
              {clone.voice.voiceId && <span className="ml-2 font-mono text-xs text-zinc-500">{clone.voice.voiceId}</span>}
            </p>
            {clone.voice.sampleFile && <audio src={fileSrc(clone.voice.sampleFile)} controls className="w-full" />}
            <div className="grid gap-2 sm:grid-cols-4">
              <Select value={voiceProvider} onChange={(e) => setVoiceProvider(e.target.value as "heygen" | "elevenlabs")}>
                <option value="elevenlabs">ElevenLabs</option>
                <option value="heygen">HeyGen</option>
              </Select>
              <Input className="sm:col-span-2" value={voiceId} onChange={(e) => setVoiceId(e.target.value)} placeholder="Zmień ID głosu" />
              <Button variant="secondary" onClick={saveVoice} loading={busy === "voice"} disabled={!voiceId.trim()}>
                Zapisz
              </Button>
            </div>
          </Card>

          {clone.kind === "photo_clone" && (
            <Card className="space-y-3">
              <div className="flex items-center justify-between">
                <p className="font-semibold">Model do sesji zdjęciowych</p>
                {clone.loraStatus && <StatusBadge status={clone.loraStatus} label={clone.loraStatus === "processing" ? "Trening LoRA…" : undefined} />}
              </div>
              <p className="text-sm text-zinc-600">Na Twoich zdjęciach trenujemy osobny model, który potrafi „sfotografować” Cię w dowolnej scenerii. Trwa to zwykle 5–20 minut.</p>
              <div className="grid grid-cols-6 gap-2">
                {clone.photoFiles.slice(0, 12).map((f) => (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img key={f} src={fileSrc(f)} alt="" className="aspect-square rounded-lg object-cover" />
                ))}
              </div>
            </Card>
          )}

          {clone.trainingVideoFile && (
            <Card className="space-y-3">
              <p className="font-semibold">Wideo treningowe</p>
              <video src={fileSrc(clone.trainingVideoFile)} controls playsInline className="aspect-video w-full rounded-xl bg-black" />
            </Card>
          )}

          <ErrorNote error={actionError} />
          <Button variant="danger" onClick={remove} loading={busy === "delete"}>
            Usuń klona
          </Button>
        </div>
      </div>
    </>
  );
}

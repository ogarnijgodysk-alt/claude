"use client";

import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { api } from "@/components/api";
import { Recorder, type Recording } from "@/components/Recorder";
import { Button, Card, ErrorNote, Field, Input, PageHeader, Segmented, Select, Textarea, cx } from "@/components/ui";
import type { Brand, Clone, CloneKind } from "@/lib/types";

const KINDS: { kind: CloneKind; title: string; text: string; badge: string }[] = [
  { kind: "digital_twin", title: "Cyfrowy bliźniak", text: "Nagraj 1–2 minuty wideo z kamery. Klon mówi Twoim głosem i gestykuluje jak Ty.", badge: "jak HeyGen" },
  { kind: "photo_clone", title: "Klon ze zdjęć", text: "Wgraj 10–20 selfie. Dostajesz sesje zdjęciowe AI ze sobą i mówiący awatar ze zdjęcia.", badge: "jak mysnapface" },
  { kind: "ai_influencer", title: "AI influencer", text: "Opisz wymyśloną postać. AI zaprojektuje twarz, charakter i głos ambasadora marki.", badge: "wirtualna postać" },
];

const TRAINING_SCRIPT = `Cześć! Nagrywam to wideo, żeby stworzyć mojego cyfrowego klona. Mówię spokojnie i naturalnie, tak jak na co dzień rozmawiam z klientami. Patrzę prosto w kamerę, czasem się uśmiecham i robię krótkie pauzy między zdaniami. Opowiem trochę o tym, czym się zajmuję. Na co dzień pomagam ludziom rozwiązywać konkretne problemy i lubię tłumaczyć rzeczy prosto, bez żargonu. Najbardziej cieszy mnie, kiedy ktoś po rozmowie mówi: teraz wszystko jasne. Zamykam usta na końcu zdań i nie zasłaniam twarzy dłońmi. Dziękuję i do zobaczenia w kolejnych nagraniach.`;

const VOICE_SCRIPT = `Dzień dobry, nazywam się tak jak widać na ekranie. To nagranie posłuży do sklonowania mojego głosu. Mówię swobodnie, w moim naturalnym tempie, z normalną intonacją. Czasem pytam: czy to ma sens? A czasem mówię z entuzjazmem: świetnie, zróbmy to!`;

export default function NewClonePage() {
  const router = useRouter();
  const [kind, setKind] = useState<CloneKind>("digital_twin");
  const [brand, setBrand] = useState<Brand | null>(null);
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [subjectName, setSubjectName] = useState("");
  const [consent, setConsent] = useState(false);

  const [videoSource, setVideoSource] = useState<"record" | "upload">("record");
  const [training, setTraining] = useState<Recording | null>(null);
  const [trainingUpload, setTrainingUpload] = useState<File | null>(null);
  const [consentRec, setConsentRec] = useState<Recording | null>(null);

  const [photos, setPhotos] = useState<File[]>([]);

  const [personaPrompt, setPersonaPrompt] = useState("");
  const [faces, setFaces] = useState<{ ref: string; src: string }[]>([]);
  const [face, setFace] = useState<string | null>(null);
  const [designing, setDesigning] = useState(false);

  const [voiceMode, setVoiceMode] = useState<"auto" | "record" | "id">("auto");
  const [voiceRec, setVoiceRec] = useState<Recording | null>(null);
  const [voiceProvider, setVoiceProvider] = useState<"heygen" | "elevenlabs">("elevenlabs");
  const [voiceId, setVoiceId] = useState("");

  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api<Brand>("/api/brand").then(setBrand, () => undefined);
  }, []);
  useEffect(() => {
    setVoiceMode(kind === "digital_twin" ? "auto" : "record");
  }, [kind]);

  const company = brand?.name || "[nazwa firmy]";
  const consentText = `Ja, ${subjectName || "[imię i nazwisko]"}, wyrażam zgodę na stworzenie mojego cyfrowego klona AI przez ${company} i używanie go do tworzenia treści marketingowych. Mogę tę zgodę w każdej chwili wycofać.`;
  const photoPreviews = useMemo(() => photos.map((p) => URL.createObjectURL(p)), [photos]);
  useEffect(() => () => photoPreviews.forEach(URL.revokeObjectURL), [photoPreviews]);

  async function designInfluencer() {
    setDesigning(true);
    setError(null);
    try {
      const res = await api<{ persona: { name: string; bio: string; voiceDescription: string }; faces: { ref: string; src: string }[] }>("/api/influencer", {
        method: "POST",
        json: { description: personaPrompt },
      });
      setFaces(res.faces);
      setFace(res.faces[0]?.ref ?? null);
      if (!name) setName(res.persona.name);
      setDescription(`${res.persona.bio}\nGłos: ${res.persona.voiceDescription}`);
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      setDesigning(false);
    }
  }

  async function submit() {
    setSubmitting(true);
    setError(null);
    try {
      const form = new FormData();
      form.set("kind", kind);
      form.set("name", name);
      form.set("description", description);
      form.set("subjectName", subjectName);
      form.set("consent", String(consent));
      if (kind === "digital_twin") {
        const video = videoSource === "record" ? training?.blob : trainingUpload;
        if (video) form.set("trainingVideo", video, videoSource === "record" ? `training.${video.type.includes("mp4") ? "mp4" : "webm"}` : trainingUpload!.name);
        if (training?.poster) form.set("cover", training.poster, "cover.jpg");
        if (consentRec) form.set("consentVideo", consentRec.blob, `consent.${consentRec.blob.type.includes("mp4") ? "mp4" : "webm"}`);
      }
      if (kind === "photo_clone") photos.forEach((p) => form.append("photos", p));
      if (kind === "ai_influencer" && face) form.set("coverRef", face);
      if (voiceMode === "record" && voiceRec) form.set("voiceSample", voiceRec.blob, `voice.${voiceRec.blob.type.includes("mp4") ? "m4a" : "webm"}`);
      if (voiceMode === "id") {
        form.set("voiceProvider", voiceProvider);
        form.set("voiceId", voiceId);
      }
      const clone = await api<Clone>("/api/clones", { method: "POST", body: form });
      router.push(`/klony/${clone.id}`);
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
      setSubmitting(false);
    }
  }

  const realPerson = kind !== "ai_influencer";
  const hasMaterial =
    (kind === "digital_twin" && (videoSource === "record" ? training : trainingUpload)) || (kind === "photo_clone" && photos.length >= 8) || (kind === "ai_influencer" && face);
  const canSubmit = name.trim() && hasMaterial && (!realPerson || (consent && subjectName.trim()));

  return (
    <>
      <PageHeader title="Nowy klon AI" subtitle="Wybierz, jak chcesz stworzyć klona. Wszystkie typy mogą potem nagrywać wideo w studiu." />

      <div className="grid gap-3 md:grid-cols-3">
        {KINDS.map((k) => (
          <button
            key={k.kind}
            onClick={() => setKind(k.kind)}
            className={cx("rounded-2xl bg-white p-4 text-left ring-1 transition", kind === k.kind ? "ring-2 ring-brand-500" : "ring-zinc-200 hover:ring-zinc-300")}
          >
            <span className="text-[11px] font-semibold uppercase tracking-wide text-brand-600">{k.badge}</span>
            <p className="mt-1 font-semibold">{k.title}</p>
            <p className="mt-1 text-sm text-zinc-500">{k.text}</p>
          </button>
        ))}
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-5">
        <div className="min-w-0 space-y-6 lg:col-span-3">
          {kind === "digital_twin" && (
            <Card className="space-y-4">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-semibold">1. Wideo treningowe</p>
                <Segmented value={videoSource} onChange={setVideoSource} options={[{ value: "record", label: "Nagraj teraz" }, { value: "upload", label: "Wgraj plik" }]} />
              </div>
              <ul className="list-disc space-y-1 pl-5 text-sm text-zinc-600">
                <li>Dobre, równe światło na twarz, spokojne tło, kamera na wysokości oczu.</li>
                <li>Mów naturalnie min. 30 s (najlepiej 1–2 min). Prompter poniżej podpowie tekst.</li>
                <li>Nie zasłaniaj twarzy, nie ruszaj się gwałtownie, zamykaj usta w pauzach.</li>
              </ul>
              {videoSource === "record" ? (
                <Recorder kind="video" teleprompter={TRAINING_SCRIPT} minSeconds={30} maxSeconds={180} onChange={setTraining} />
              ) : (
                <Input type="file" accept="video/mp4,video/quicktime,video/webm" onChange={(e) => setTrainingUpload(e.target.files?.[0] ?? null)} />
              )}
            </Card>
          )}

          {kind === "photo_clone" && (
            <Card className="space-y-4">
              <p className="font-semibold">1. Zdjęcia twarzy</p>
              <ul className="list-disc space-y-1 pl-5 text-sm text-zinc-600">
                <li>10–20 zdjęć tylko jednej osoby, różne ujęcia, miejsca, ubrania i oświetlenie.</li>
                <li>Twarz wyraźna i ostra; bez okularów przeciwsłonecznych, filtrów i innych osób w kadrze.</li>
                <li>Pierwsze zdjęcie będzie okładką i bazą mówiącego awatara — najlepiej portret na wprost.</li>
              </ul>
              <Input type="file" multiple accept="image/jpeg,image/png,image/webp" onChange={(e) => setPhotos(Array.from(e.target.files ?? []).slice(0, 30))} />
              {photos.length > 0 && (
                <>
                  <p className={cx("text-sm", photos.length >= 8 ? "text-emerald-700" : "text-amber-700")}>
                    Wybrano {photos.length} zdj. {photos.length < 8 && "— potrzeba co najmniej 8."}
                  </p>
                  <div className="grid grid-cols-5 gap-2 sm:grid-cols-8">
                    {photoPreviews.map((src, i) => (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img key={src} src={src} alt="" className={cx("aspect-square rounded-lg object-cover", i === 0 && "ring-2 ring-brand-500")} />
                    ))}
                  </div>
                </>
              )}
            </Card>
          )}

          {kind === "ai_influencer" && (
            <Card className="space-y-4">
              <p className="font-semibold">1. Zaprojektuj postać</p>
              <Field label="Opisz influencera" hint="Wygląd, wiek, styl, osobowość i o czym ma mówić. Postać jest fikcyjna — nie opisuj realnych, znanych osób.">
                <Textarea value={personaPrompt} onChange={(e) => setPersonaPrompt(e.target.value)} rows={4} placeholder="np. Kobieta ok. 30 lat, krótkie ciemne włosy, minimalistyczny styl, ekspertka od zdrowego gotowania, ciepła i zabawna" />
              </Field>
              <Button onClick={designInfluencer} loading={designing} disabled={!personaPrompt.trim()} variant="secondary">
                ✦ Wygeneruj propozycje
              </Button>
              {faces.length > 0 && (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                  {faces.map((f) => (
                    <button key={f.ref} onClick={() => setFace(f.ref)} className={cx("overflow-hidden rounded-xl ring-2 transition", face === f.ref ? "ring-brand-500" : "ring-transparent hover:ring-zinc-300")}>
                      {/* eslint-disable-next-line @next/next/no-img-element */}
                      <img src={f.src} alt="Propozycja twarzy" className="aspect-square w-full object-cover" />
                    </button>
                  ))}
                </div>
              )}
            </Card>
          )}

          <Card className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <p className="font-semibold">2. Głos</p>
              <Segmented
                value={voiceMode}
                onChange={setVoiceMode}
                options={[
                  ...(kind === "digital_twin" ? [{ value: "auto" as const, label: "Z wideo" }] : []),
                  { value: "record" as const, label: "Nagraj próbkę" },
                  { value: "id" as const, label: "Mam ID głosu" },
                ]}
              />
            </div>
            {voiceMode === "auto" && <p className="text-sm text-zinc-600">HeyGen sklonuje głos z wideo treningowego — nic więcej nie trzeba robić.</p>}
            {voiceMode === "record" && (
              <>
                <p className="text-sm text-zinc-600">
                  {kind === "ai_influencer"
                    ? "Nagraj lektora (np. aktora głosowego, który się na to zgodził) — ElevenLabs sklonuje ten głos dla postaci."
                    : "Przeczytaj tekst przez ok. 1 minutę w cichym pomieszczeniu. ElevenLabs sklonuje głos."}
                </p>
                <Recorder kind="audio" teleprompter={VOICE_SCRIPT} minSeconds={20} maxSeconds={180} onChange={setVoiceRec} />
              </>
            )}
            {voiceMode === "id" && (
              <div className="grid gap-3 sm:grid-cols-3">
                <Select value={voiceProvider} onChange={(e) => setVoiceProvider(e.target.value as "heygen" | "elevenlabs")}>
                  <option value="elevenlabs">ElevenLabs</option>
                  <option value="heygen">HeyGen</option>
                </Select>
                <Input className="sm:col-span-2" value={voiceId} onChange={(e) => setVoiceId(e.target.value)} placeholder="ID głosu z panelu dostawcy" />
              </div>
            )}
          </Card>

          {realPerson && kind === "digital_twin" && (
            <Card className="space-y-4">
              <p className="font-semibold">3. Nagranie zgody (zalecane)</p>
              <p className="text-sm text-zinc-600">
                Osoba klonowana odczytuje oświadczenie do kamery. Zachowujemy je jako dowód zgody. HeyGen dodatkowo poprosi o własną weryfikację — link pojawi się po utworzeniu klona.
              </p>
              <Recorder kind="video" teleprompter={consentText} minSeconds={5} maxSeconds={40} onChange={setConsentRec} />
            </Card>
          )}
        </div>

        <div className="lg:col-span-2">
          <Card className="space-y-4 lg:sticky lg:top-8">
            <p className="font-semibold">Szczegóły klona</p>
            <Field label="Nazwa klona">
              <Input value={name} onChange={(e) => setName(e.target.value)} placeholder={kind === "ai_influencer" ? "np. Ola od Zdrowia" : "np. Anna — CEO"} />
            </Field>
            <Field label="Opis / persona" hint="Kim jest i jak mówi. AI użyje tego przy pisaniu skryptów.">
              <Textarea value={description} onChange={(e) => setDescription(e.target.value)} rows={3} />
            </Field>
            {realPerson && (
              <div className="space-y-3 rounded-xl bg-zinc-50 p-4">
                <Field label="Imię i nazwisko osoby klonowanej">
                  <Input value={subjectName} onChange={(e) => setSubjectName(e.target.value)} />
                </Field>
                <label className="flex items-start gap-2 text-sm">
                  <input type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} className="mt-1" />
                  <span>Potwierdzam, że to ja albo mam pisemną zgodę tej osoby na stworzenie jej klona AI i publikowanie treści z jego udziałem.</span>
                </label>
              </div>
            )}
            <ErrorNote error={error} />
            <Button onClick={submit} loading={submitting} disabled={!canSubmit} className="w-full">
              Stwórz klona
            </Button>
            {!canSubmit && <p className="text-xs text-zinc-500">Uzupełnij nazwę, materiał treningowy{realPerson && " i zgodę"}.</p>}
          </Card>
        </div>
      </div>
    </>
  );
}

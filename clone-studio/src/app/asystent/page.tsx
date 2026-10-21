"use client";

import { useEffect, useState } from "react";
import { appendText, DictateButton } from "@/components/DictateButton";
import { api } from "@/components/api";
import { CloneAvatar } from "@/components/media";
import { Recorder, type Recording } from "@/components/Recorder";
import { WidgetPanel } from "@/components/WidgetPanel";
import { Button, Card, ConfirmButton, CopyButton, Empty, ErrorNote, Field, Input, LinkButton, PageHeader, StatusBadge, Textarea, cx } from "@/components/ui";
import type { ProviderStatus } from "@/lib/providers/status";
import type { Assistant, Brand, Clone } from "@/lib/types";

const TAVUS_CONSENT =
  "I, [imię i nazwisko], am currently speaking and give consent to Tavus to create an AI clone of me by using the audio and video samples I provide. I understand that this AI clone can be used to create videos that look and sound like me.";

const TAVUS_TRAINING = `${TAVUS_CONSENT}

(Pauza 2 sekundy, potem mów naturalnie ok. 2 minut.) Cześć, opowiem trochę o naszej firmie i o tym, jak pomagamy klientom. Mówię spokojnie, patrzę w kamerę i robię krótkie pauzy. Na co dzień odpowiadam na pytania o ofertę, ceny i terminy, a najbardziej lubię, gdy po rozmowie wszystko jest jasne. (Na końcu: 15 sekund ciszy z zamkniętymi ustami, patrząc w kamerę.)`;

type Draft = Pick<Assistant, "name" | "greeting" | "knowledge" | "handoff" | "language">;

export default function AssistantPage() {
  const [status, setStatus] = useState<ProviderStatus | null>(null);
  const [brand, setBrand] = useState<Brand | null>(null);
  const [clones, setClones] = useState<Clone[] | null>(null);
  const [assistants, setAssistants] = useState<Assistant[]>([]);
  const [selected, setSelected] = useState<string | "new">("new");
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api<ProviderStatus>("/api/status").then(setStatus, () => undefined);
    api<Brand>("/api/brand").then(setBrand, () => undefined);
    api<Clone[]>("/api/clones").then(setClones, (e) => setError(e.message));
    api<Assistant[]>("/api/assistants").then((as) => {
      setAssistants(as);
      if (as[0]) setSelected(as[0].id);
    }, (e) => setError(e.message));
  }, []);

  const current = assistants.find((a) => a.id === selected);

  if (clones && clones.length === 0) {
    return (
      <>
        <PageHeader title="Asystent na stronę" />
        <Empty title="Najpierw stwórz klona — to on będzie rozmawiał z klientami na Twojej stronie">
          <LinkButton href="/klony/nowy">Stwórz klona</LinkButton>
        </Empty>
      </>
    );
  }

  return (
    <>
      <PageHeader
        title="Asystent na stronę"
        subtitle="Twój klon rozmawia z odwiedzającymi stronę: na żywo przez wideo (Tavus) albo głosowo. Odpowiada na pytania o ofertę i kieruje do kontaktu."
      />
      <ErrorNote error={error} />

      <div className="mb-5 flex flex-wrap gap-2">
        {assistants.map((a) => (
          <button
            key={a.id}
            onClick={() => setSelected(a.id)}
            className={cx("rounded-full px-3 py-1.5 text-sm ring-1", selected === a.id ? "bg-brand-600 text-white ring-brand-600" : "bg-white ring-zinc-200 hover:ring-zinc-300")}
          >
            {a.name}
          </button>
        ))}
        <button
          onClick={() => setSelected("new")}
          className={cx("rounded-full px-3 py-1.5 text-sm ring-1", selected === "new" ? "bg-brand-600 text-white ring-brand-600" : "bg-white ring-zinc-200 hover:ring-zinc-300")}
        >
          + Nowy asystent
        </button>
      </div>

      {selected === "new" || !current ? (
        <NewAssistant
          clones={clones ?? []}
          brand={brand}
          status={status}
          onCreated={(a) => {
            setAssistants((as) => [a, ...as]);
            setSelected(a.id);
          }}
        />
      ) : (
        <AssistantDetail
          key={current.id}
          assistant={current}
          clone={clones?.find((c) => c.id === current.cloneId)}
          status={status}
          onChange={(a) => setAssistants((as) => as.map((x) => (x.id === a.id ? a : x)))}
          onDelete={() => {
            setAssistants((as) => as.filter((x) => x.id !== current.id));
            setSelected("new");
          }}
        />
      )}
    </>
  );
}

function AssistantFields({ draft, setDraft }: { draft: Draft; setDraft: (d: Draft) => void }) {
  const set = (k: keyof Draft) => (v: string) => setDraft({ ...draft, [k]: v });
  return (
    <>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Imię asystenta">
          <Input value={draft.name} onChange={(e) => set("name")(e.target.value)} />
        </Field>
        <Field label="Język rozmowy">
          <Input value={draft.language} onChange={(e) => set("language")(e.target.value)} placeholder="polski" />
        </Field>
      </div>
      <Field label="Powitanie">
        <Input value={draft.greeting} onChange={(e) => set("greeting")(e.target.value)} placeholder="Cześć! W czym mogę pomóc?" />
      </Field>
      <Field label="Wiedza asystenta" hint="Oferta, ceny, godziny otwarcia, dojazd, najczęstsze pytania. Asystent mówi tylko to, co tu jest (plus profil marki).">
        <div className="space-y-2">
          <Textarea value={draft.knowledge} onChange={(e) => set("knowledge")(e.target.value)} rows={7} />
          <DictateButton onText={(t) => set("knowledge")(appendText(draft.knowledge, t))} />
        </div>
      </Field>
      <Field label="Gdy asystent nie wie albo klient chce kupić" hint="Np. telefon, e-mail, link do rezerwacji.">
        <Input value={draft.handoff} onChange={(e) => set("handoff")(e.target.value)} placeholder="Zadzwoń: 600 000 000 albo napisz: kontakt@firma.pl" />
      </Field>
    </>
  );
}

function NewAssistant({ clones, brand, status, onCreated }: { clones: Clone[]; brand: Brand | null; status: ProviderStatus | null; onCreated: (a: Assistant) => void }) {
  const [cloneId, setCloneId] = useState("");
  const [draft, setDraft] = useState<Draft>({ name: "", greeting: "", knowledge: "", handoff: "", language: "polski" });
  const [tavusMode, setTavusMode] = useState<"record" | "stock">("stock");
  const [tavusVideo, setTavusVideo] = useState<Recording | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const first = clones.find((c) => c.status === "ready") ?? clones[0];
    if (first && !cloneId) {
      setCloneId(first.id);
      setDraft((d) => ({ ...d, name: d.name || first.name, greeting: d.greeting || `Cześć! Jestem ${first.name}${brand?.name ? ` z ${brand.name}` : ""}. W czym mogę pomóc?` }));
    }
  }, [clones, cloneId, brand]);

  useEffect(() => {
    if (brand && !draft.knowledge) setDraft((d) => ({ ...d, knowledge: [brand.description, brand.ctas].filter(Boolean).join("\n") }));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [brand]);

  async function create() {
    setBusy(true);
    setError(null);
    try {
      const form = new FormData();
      form.set("cloneId", cloneId);
      for (const [k, v] of Object.entries(draft)) form.set(k, v);
      if (status?.tavus && tavusMode === "record" && tavusVideo) form.set("tavusVideo", tavusVideo.blob, `tavus.${tavusVideo.blob.type.includes("mp4") ? "mp4" : "webm"}`);
      onCreated(await api<Assistant>("/api/assistants", { method: "POST", body: form }));
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="grid gap-6 lg:grid-cols-5">
      <Card className="min-w-0 space-y-4 lg:col-span-3">
        <Field label="Kto rozmawia z klientami?">
          <div className="flex gap-2 overflow-x-auto pb-1">
            {clones.map((c) => (
              <button
                key={c.id}
                onClick={() => {
                  setCloneId(c.id);
                  setDraft((d) => ({ ...d, name: c.name }));
                }}
                className={cx("w-20 shrink-0 overflow-hidden rounded-xl text-left ring-2", cloneId === c.id ? "ring-brand-500" : "ring-transparent hover:ring-zinc-200")}
              >
                <CloneAvatar clone={c} className="aspect-square w-full" />
                <p className="truncate p-1.5 text-[11px] font-medium">{c.name}</p>
              </button>
            ))}
          </div>
        </Field>
        <AssistantFields draft={draft} setDraft={setDraft} />
        {status?.tavus && (
          <div className="space-y-3 rounded-xl bg-zinc-50 p-4">
            <p className="text-sm font-semibold">Twarz do rozmów wideo (Tavus)</p>
            <div className="flex flex-wrap gap-2 text-sm">
              <label className="flex items-center gap-2">
                <input type="radio" checked={tavusMode === "stock"} onChange={() => setTavusMode("stock")} /> Gotowa twarz Tavus (od razu)
              </label>
              <label className="flex items-center gap-2">
                <input type="radio" checked={tavusMode === "record"} onChange={() => setTavusMode("record")} /> Nagraj osobę z firmy (trening kilka godzin)
              </label>
            </div>
            {tavusMode === "record" && (
              <>
                <p className="text-xs text-zinc-600">
                  Tavus wymaga, by nagranie zaczynało się od oświadczenia zgody po angielsku (treść poniżej, sprawdź aktualną wersję w dokumentacji Tavus), a potem ok. 2 minut naturalnej mowy.
                </p>
                <Recorder kind="video" teleprompter={TAVUS_TRAINING} minSeconds={60} maxSeconds={300} onChange={setTavusVideo} />
              </>
            )}
          </div>
        )}
        <ErrorNote error={error} />
        <Button onClick={create} loading={busy} disabled={!cloneId || (status?.tavus && tavusMode === "record" && !tavusVideo)}>
          Utwórz asystenta
        </Button>
      </Card>
      <Card className="space-y-3 text-sm text-zinc-600 lg:col-span-2">
        <p className="font-semibold text-zinc-900">Jak to działa</p>
        <ol className="list-decimal space-y-2 pl-5">
          <li>Wybierasz klona i wpisujesz, co asystent ma wiedzieć.</li>
          <li>Wklejasz jedną linijkę kodu na swoją stronę — pojawia się dymek „Porozmawiaj z …”.</li>
          <li>
            Klient klika i rozmawia: <b>wideo na żywo</b> z Twoim klonem (Tavus), a bez Tavus — <b>głosowo</b> ze zdjęciem klona. Na telefonie też.
          </li>
          <li>Pytania, na które nie ma odpowiedzi, asystent kieruje do Twojego kontaktu.</li>
        </ol>
        {!status?.tavus && <p className="rounded-lg bg-amber-50 p-3 text-xs text-amber-800">Bez klucza TAVUS_API_KEY działa rozmowa głosowa (rozpoznawanie mowy w przeglądarce + Claude + synteza mowy). Wideo na żywo włączy się po podłączeniu Tavus.</p>}
      </Card>
    </div>
  );
}

function AssistantDetail({
  assistant,
  clone,
  status,
  onChange,
  onDelete,
}: {
  assistant: Assistant;
  clone?: Clone;
  status: ProviderStatus | null;
  onChange: (a: Assistant) => void;
  onDelete: () => void;
}) {
  const [draft, setDraft] = useState<Draft>({ name: assistant.name, greeting: assistant.greeting, knowledge: assistant.knowledge, handoff: assistant.handoff, language: assistant.language });
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [open, setOpen] = useState(false);
  const [origin, setOrigin] = useState("https://twoja-aplikacja.pl");

  useEffect(() => setOrigin(window.location.origin), []);

  async function save() {
    setSaving(true);
    setError(null);
    try {
      onChange(await api<Assistant>(`/api/assistants/${assistant.id}`, { method: "PATCH", json: draft }));
      setSaved(true);
      setOpen(false); // reopen the preview so it picks up the new greeting
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      setSaving(false);
    }
  }

  const tavus = assistant.tavus?.replicaStatus;
  const snippet = `<script src="${origin}/embed.js" data-assistant="${assistant.id}" async></script>`;

  return (
    <div className="grid gap-6 lg:grid-cols-5">
      <div className="min-w-0 space-y-6 lg:col-span-3">
        <Card className="space-y-4">
          <div className="flex flex-wrap items-center gap-2">
            {clone && <CloneAvatar clone={clone} className="h-10 w-10 rounded-full" />}
            <p className="font-semibold">{assistant.name}</p>
            {tavus ? (
              <StatusBadge status={tavus} label={tavus === "ready" ? "Wideo na żywo: gotowe" : tavus === "processing" ? "Tavus trenuje twarz…" : "Tavus: błąd"} />
            ) : (
              <span className="rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600">Rozmowa głosowa</span>
            )}
          </div>
          <ErrorNote error={assistant.error} />
          <AssistantFields draft={draft} setDraft={(d) => (setDraft(d), setSaved(false))} />
          <ErrorNote error={error} />
          <div className="flex flex-wrap items-center gap-3">
            <Button onClick={save} loading={saving}>
              Zapisz zmiany
            </Button>
            {saved && <span className="text-sm text-emerald-700">✓ Zapisano</span>}
            <ConfirmButton
              className="ml-auto"
              onConfirm={async () => {
                await api(`/api/assistants/${assistant.id}`, { method: "DELETE" });
                onDelete();
              }}
            >
              Usuń asystenta
            </ConfirmButton>
          </div>
        </Card>

        <Card className="space-y-3">
          <p className="font-semibold">Kod do wklejenia na stronę</p>
          {status?.browser ? (
            <p className="text-sm text-zinc-600">
              W wersji testowej asystent działa w podglądzie obok. Kod na stronę firmy działa po wdrożeniu aplikacji z bazą danych (instrukcja w README).
            </p>
          ) : (
            <>
              <p className="text-sm text-zinc-600">Wklej przed &lt;/body&gt; na każdej stronie, gdzie ma się pojawić asystent (WordPress, Shopify, Wix — w polu „własny kod”).</p>
              <pre className="overflow-x-auto rounded-lg bg-zinc-900 p-3 text-xs text-zinc-100">{snippet}</pre>
              <div className="flex flex-wrap gap-2">
                <CopyButton text={snippet} label="Kopiuj kod" />
                <a href={`/widget/${assistant.id}`} target="_blank" rel="noreferrer" className="rounded-lg px-4 py-2 text-sm font-medium text-brand-700 ring-1 ring-brand-200 hover:bg-brand-50">
                  Otwórz na pełnym ekranie (np. na telefonie)
                </a>
              </div>
            </>
          )}
        </Card>
      </div>

      <div className="lg:col-span-2">
        <Card className="space-y-3 p-0 lg:sticky lg:top-8">
          <p className="px-5 pt-5 font-semibold">Podgląd na Twojej stronie</p>
          <div className="relative mx-3 mb-3 h-[600px] overflow-hidden rounded-xl bg-gradient-to-b from-zinc-50 to-zinc-100 ring-1 ring-zinc-200">
            <div className="flex items-center gap-1.5 border-b border-zinc-200 bg-white px-3 py-2">
              <span className="h-2.5 w-2.5 rounded-full bg-red-300" />
              <span className="h-2.5 w-2.5 rounded-full bg-amber-300" />
              <span className="h-2.5 w-2.5 rounded-full bg-emerald-300" />
              <span className="ml-2 truncate rounded bg-zinc-100 px-2 py-0.5 text-[11px] text-zinc-500">www.twojafirma.pl</span>
            </div>
            <div className="space-y-3 p-5">
              <div className="h-3 w-24 rounded bg-zinc-300" />
              <div className="h-6 w-3/4 rounded bg-zinc-300" />
              <div className="h-3 w-full rounded bg-zinc-200" />
              <div className="h-3 w-5/6 rounded bg-zinc-200" />
              <div className="mt-6 grid grid-cols-3 gap-2">
                <div className="h-16 rounded-lg bg-zinc-200" />
                <div className="h-16 rounded-lg bg-zinc-200" />
                <div className="h-16 rounded-lg bg-zinc-200" />
              </div>
            </div>
            {open ? (
              <div className="absolute inset-2 top-10 overflow-hidden rounded-xl shadow-2xl ring-1 ring-zinc-200">
                <WidgetPanel assistantId={assistant.id} onClose={() => setOpen(false)} />
              </div>
            ) : (
              <button
                onClick={() => setOpen(true)}
                className="absolute bottom-4 right-4 flex items-center gap-2 rounded-full bg-white py-1.5 pl-1.5 pr-4 text-sm font-semibold shadow-lg ring-1 ring-zinc-200 hover:shadow-xl"
              >
                {clone ? <CloneAvatar clone={clone} className="h-10 w-10 rounded-full" /> : <span className="h-10 w-10 rounded-full bg-brand-500" />}
                Porozmawiaj z {assistant.name}
              </button>
            )}
          </div>
        </Card>
      </div>
    </div>
  );
}

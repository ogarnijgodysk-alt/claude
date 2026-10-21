import { finalCaption } from "../captions";
import { demoJobDone, demoPortraitSvg } from "../providers/demo";
import type { Assistant, Brand, ChatMessage, Clone, CloneKind, Database, PhotoSet, Platform, Video } from "../types";
import { PLATFORMS } from "../types";
import { aiAvailable, aiChat, aiIdeas, aiPersona, aiScript } from "./ai";
import { browserFileUrl } from "./registry";
import { boot, db, deleteFiles, putFile, resetAll, save } from "./store";

// The app's /api/* surface, implemented in the browser for browser-storage mode.
// Media jobs are simulated (demo); AI goes through ./ai.

export { boot as ready };

type Init = RequestInit & { json?: unknown };

class HttpError extends Error {}

function newId(prefix: string) {
  return `${prefix}_${crypto.randomUUID().replace(/-/g, "").slice(0, 16)}`;
}

function body(init?: Init) {
  if (init?.json !== undefined) return init.json as Record<string, unknown>;
  if (typeof init?.body === "string") return JSON.parse(init.body) as Record<string, unknown>;
  return {};
}

function form(init?: Init) {
  if (!(init?.body instanceof FormData)) throw new HttpError("Brak danych formularza.");
  const f = init.body;
  return {
    str: (k: string) => (typeof f.get(k) === "string" ? (f.get(k) as string) : ""),
    file: (k: string) => {
      const v = f.get(k);
      return v instanceof Blob && v.size > 0 ? v : undefined;
    },
    files: (k: string) => f.getAll(k).filter((v): v is File => v instanceof Blob && v.size > 0),
  };
}

function svgBlob(svg: string) {
  return new Blob([svg], { type: "image/svg+xml" });
}

const url = (name?: string) => (name ? browserFileUrl(name) : undefined);

// ---------- clones ----------

function tickClone(c: Clone) {
  if (c.status === "processing" && demoJobDone(c.createdAt)) c.status = "ready";
  if (c.loraStatus === "processing" && demoJobDone(c.createdAt)) c.loraStatus = "ready";
  return c;
}

async function createClone(init?: Init) {
  const f = form(init);
  const kind = f.str("kind") as CloneKind;
  const name = f.str("name").trim();
  const consent = f.str("consent") === "true";
  const photos = f.files("photos");
  if (!name) throw new HttpError("Podaj nazwę klona.");
  if (kind !== "ai_influencer" && !consent) throw new HttpError("Klon realnej osoby wymaga jej świadomej zgody.");
  if (kind === "digital_twin" && !f.file("trainingVideo")) throw new HttpError("Nagraj lub dodaj wideo treningowe.");
  if (kind === "photo_clone" && photos.length < 8) throw new HttpError("Dodaj co najmniej 8 zdjęć twarzy (najlepiej 15–20).");
  if (kind === "ai_influencer" && !f.str("coverRef")) throw new HttpError("Wybierz twarz influencera.");

  const id = newId("cl");
  const clone: Clone = {
    id,
    kind,
    name,
    description: f.str("description").trim(),
    status: "processing",
    photoFiles: [],
    voice: { provider: kind === "digital_twin" ? "heygen" : "none" },
    consent: { confirmed: kind === "ai_influencer" || consent, subjectName: f.str("subjectName").trim(), at: new Date().toISOString() },
    demo: true,
    createdAt: new Date().toISOString(),
  };
  const training = f.file("trainingVideo");
  if (training) clone.trainingVideoFile = await putFile(training, "train");
  const consentVideo = f.file("consentVideo");
  if (consentVideo) clone.consentVideoFile = await putFile(consentVideo, "consent");
  for (const p of photos) clone.photoFiles.push(await putFile(p, "photo"));
  const cover = f.file("cover");
  clone.coverFile = f.str("coverRef") || (cover ? await putFile(cover, "cover") : clone.photoFiles[0]) || (await putFile(svgBlob(demoPortraitSvg(id, name)), "cover"));
  const sample = f.file("voiceSample");
  if (sample) clone.voice = { provider: "none", sampleFile: await putFile(sample, "voice") };
  if (f.str("voiceId").trim()) clone.voice = { ...clone.voice, provider: f.str("voiceProvider") === "heygen" ? "heygen" : "elevenlabs", voiceId: f.str("voiceId").trim() };
  if (kind === "photo_clone") clone.loraStatus = "processing";
  db().clones.unshift(clone);
  await save();
  return clone;
}

// ---------- videos & photos ----------

async function createVideo(init?: Init) {
  const f = form(init);
  const d = db();
  const clone = d.clones.find((c) => c.id === f.str("cloneId"));
  if (!clone) throw new HttpError("Nie znaleziono klona.");
  if (tickClone(clone).status !== "ready") throw new HttpError("Klon jeszcze się trenuje — poczekaj, aż będzie gotowy.");
  const mode = f.str("mode") === "voice" ? "voice" : "script";
  const script = f.str("script").trim();
  const audio = f.file("audio");
  if (mode === "script" && !script) throw new HttpError("Wpisz lub wygeneruj skrypt.");
  if (mode === "voice" && !audio) throw new HttpError("Nagraj swój głos.");
  const platform = (f.str("platform") in PLATFORMS ? f.str("platform") : "tiktok") as Platform;
  const video: Video = {
    id: newId("vid"),
    cloneId: clone.id,
    title: f.str("title").trim() || "Nowe wideo",
    platform,
    aspectRatio: PLATFORMS[platform].aspect,
    mode,
    script,
    audioFile: audio ? await putFile(audio, "rec") : undefined,
    caption: finalCaption(d.brand, f.str("caption")),
    hashtags: f.str("hashtags").split(/[\s,]+/).map((h) => h.replace(/^#/, "").trim()).filter(Boolean),
    status: "processing",
    demo: true,
    createdAt: new Date().toISOString(),
  };
  d.videos.unshift(video);
  await save();
  return video;
}

function tickVideo(v: Video) {
  if (v.status === "processing" && demoJobDone(v.createdAt)) v.status = "ready";
  return v;
}

const SCENE_LABEL: Record<string, string> = {
  biuro: "biuro",
  konferencja: "konferencja",
  produkt: "z produktem",
  kawiarnia: "kawiarnia",
  plener: "plener",
  studio: "studio",
  podcast: "podcast",
  sport: "siłownia",
};

async function createPhotos(init?: Init) {
  const b = body(init) as { cloneId?: string; scene?: string; prompt?: string; aspect?: PhotoSet["aspect"]; count?: number };
  const clone = db().clones.find((c) => c.id === b.cloneId);
  if (!clone) throw new HttpError("Nie znaleziono klona.");
  const aspect = b.aspect ?? "1:1";
  const set: PhotoSet = {
    id: newId("ph"),
    cloneId: clone.id,
    scene: b.scene ?? "",
    prompt: [b.scene, b.prompt].filter(Boolean).join(", "),
    aspect,
    status: "processing",
    jobs: [],
    images: [],
    demo: true,
    createdAt: new Date().toISOString(),
  };
  for (let i = 0; i < Math.min(Math.max(Number(b.count) || 4, 1), 4); i++) {
    set.images.push(await putFile(svgBlob(demoPortraitSvg(`${set.id}${i}`, `${clone.name} · ${SCENE_LABEL[set.scene] ?? "sesja"}`, aspect)), "shot"));
  }
  db().photos.unshift(set);
  await save();
  return set;
}

function photoOut(p: PhotoSet) {
  if (p.status === "processing" && demoJobDone(p.createdAt)) p.status = "ready";
  return { ...p, images: p.images.map((n) => url(n) ?? n) };
}

// ---------- assistants ----------

function assistantCtx(a: Assistant) {
  const clone = db().clones.find((c) => c.id === a.cloneId);
  return { clone, name: clone?.name ?? a.name, description: clone?.description ?? "" };
}

async function createAssistant(init?: Init) {
  const f = form(init);
  const clone = db().clones.find((c) => c.id === f.str("cloneId"));
  if (!clone) throw new HttpError("Wybierz klona, który będzie rozmawiał na stronie.");
  const a: Assistant = {
    id: newId("as"),
    cloneId: clone.id,
    name: f.str("name").trim() || clone.name,
    greeting: f.str("greeting").trim() || `Cześć! Jestem ${clone.name}. W czym mogę pomóc?`,
    knowledge: f.str("knowledge").trim(),
    handoff: f.str("handoff").trim(),
    language: f.str("language").trim() || "polski",
    createdAt: new Date().toISOString(),
  };
  db().assistants.unshift(a);
  await save();
  return a;
}

// ---------- router ----------

type Handler = (m: RegExpMatchArray, init?: Init) => unknown | Promise<unknown>;

const routes: [string, RegExp, Handler][] = [
  [
    "GET",
    /^\/api\/status$/,
    async () => ({ claude: await aiAvailable(), heygen: false, elevenlabs: false, fal: false, kie: false, tavus: false, publicUrl: false, browser: true }),
  ],
  ["GET", /^\/api\/brand$/, () => db().brand],
  [
    "PUT",
    /^\/api\/brand$/,
    async (_m, init) => {
      const b = body(init) as Partial<Brand>;
      const brand = db().brand;
      for (const k of ["name", "industry", "description", "audience", "tone", "language", "ctas", "bannedWords"] as const) {
        if (typeof b[k] === "string") brand[k] = b[k];
      }
      if (typeof b.aiDisclosure === "boolean") brand.aiDisclosure = b.aiDisclosure;
      brand.updatedAt = new Date().toISOString();
      await save();
      return brand;
    },
  ],
  ["GET", /^\/api\/clones$/, () => db().clones.map(tickClone)],
  ["POST", /^\/api\/clones$/, (_m, init) => createClone(init)],
  ["POST", /^\/api\/clones\/([^/]+)\/consent$/, () => Promise.reject(new HttpError("Weryfikacja zgody działa po podłączeniu HeyGen."))],
  [
    "GET",
    /^\/api\/clones\/([^/]+)$/,
    (m) => {
      const c = db().clones.find((x) => x.id === m[1]);
      if (!c) throw new HttpError("Nie znaleziono klona.");
      return tickClone(c);
    },
  ],
  [
    "PATCH",
    /^\/api\/clones\/([^/]+)$/,
    async (m, init) => {
      const c = db().clones.find((x) => x.id === m[1]);
      const b = body(init) as { voiceProvider?: string; voiceId?: string };
      if (!c) throw new HttpError("Nie znaleziono klona.");
      if (!b.voiceId?.trim()) throw new HttpError("Podaj ID głosu.");
      c.voice = { ...c.voice, provider: b.voiceProvider === "heygen" ? "heygen" : "elevenlabs", voiceId: b.voiceId.trim() };
      await save();
      return c;
    },
  ],
  [
    "DELETE",
    /^\/api\/clones\/([^/]+)$/,
    async (m) => {
      const d = db();
      const c = d.clones.find((x) => x.id === m[1]);
      d.clones = d.clones.filter((x) => x.id !== m[1]);
      if (c) await deleteFiles([c.trainingVideoFile, c.consentVideoFile, c.voice.sampleFile, ...c.photoFiles.filter((p) => p !== c.coverFile)]);
      await save();
      return { ok: true };
    },
  ],
  [
    "POST",
    /^\/api\/influencer$/,
    async (_m, init) => {
      const { description } = body(init) as { description?: string };
      if (!description?.trim()) throw new HttpError("Opisz, jak ma wyglądać i kim ma być influencer.");
      const persona = await aiPersona(db().brand, description.trim());
      const faces = [];
      for (let i = 0; i < 4; i++) {
        const ref = await putFile(svgBlob(demoPortraitSvg(`${persona.name}${i}${Date.now()}`, persona.name)), "face");
        faces.push({ ref, src: url(ref)! });
      }
      return { persona, faces };
    },
  ],
  [
    "POST",
    /^\/api\/ai\/script$/,
    (_m, init) => {
      const b = body(init) as { topic?: string; platform?: Platform; goal?: string; seconds?: number; cloneId?: string; notes?: string };
      if (!b.topic?.trim()) throw new HttpError("Podaj temat filmu.");
      const clone = db().clones.find((c) => c.id === b.cloneId);
      return aiScript(db().brand, {
        topic: b.topic.trim(),
        platform: b.platform && b.platform in PLATFORMS ? b.platform : "tiktok",
        goal: b.goal || "zasięg",
        seconds: Number(b.seconds) || 30,
        persona: clone ? `${clone.name}. ${clone.description}`.trim() : undefined,
        notes: b.notes,
      });
    },
  ],
  [
    "POST",
    /^\/api\/ai\/ideas$/,
    (_m, init) => {
      const b = body(init) as { count?: number; platforms?: Platform[]; focus?: string };
      return aiIdeas(db().brand, Number(b.count) || 7, b.platforms?.length ? b.platforms : ["tiktok", "reels"], b.focus ?? "");
    },
  ],
  ["GET", /^\/api\/videos$/, () => db().videos.map(tickVideo)],
  ["POST", /^\/api\/videos$/, (_m, init) => createVideo(init)],
  [
    "GET",
    /^\/api\/videos\/([^/]+)$/,
    (m) => {
      const v = db().videos.find((x) => x.id === m[1]);
      if (!v) throw new HttpError("Nie znaleziono wideo.");
      return tickVideo(v);
    },
  ],
  [
    "DELETE",
    /^\/api\/videos\/([^/]+)$/,
    async (m) => {
      const d = db();
      const v = d.videos.find((x) => x.id === m[1]);
      d.videos = d.videos.filter((x) => x.id !== m[1]);
      if (v) await deleteFiles([v.audioFile]);
      await save();
      return { ok: true };
    },
  ],
  ["GET", /^\/api\/photos$/, () => db().photos.map(photoOut)],
  ["POST", /^\/api\/photos$/, async (_m, init) => photoOut(await createPhotos(init))],
  [
    "GET",
    /^\/api\/photos\/([^/]+)$/,
    (m) => {
      const p = db().photos.find((x) => x.id === m[1]);
      if (!p) throw new HttpError("Nie znaleziono sesji.");
      return photoOut(p);
    },
  ],
  ["GET", /^\/api\/assistants$/, () => db().assistants],
  ["POST", /^\/api\/assistants$/, (_m, init) => createAssistant(init)],
  [
    "GET",
    /^\/api\/assistants\/([^/]+)\/public$/,
    (m) => {
      const a = db().assistants.find((x) => x.id === m[1]);
      if (!a) throw new HttpError("Nie znaleziono asystenta.");
      const { clone } = assistantCtx(a);
      return { id: a.id, name: a.name, greeting: a.greeting, company: db().brand.name, cover: url(clone?.coverFile), video: false, voice: false, language: a.language };
    },
  ],
  ["POST", /^\/api\/assistants\/([^/]+)\/conversation$/, () => ({ mode: "voice" })],
  [
    "POST",
    /^\/api\/assistants\/([^/]+)\/chat$/,
    async (m, init) => {
      const a = db().assistants.find((x) => x.id === m[1]);
      if (!a) throw new HttpError("Nie znaleziono asystenta.");
      const { messages } = body(init) as { messages?: ChatMessage[] };
      const history = (messages ?? []).filter((x) => x.content?.trim()).slice(-20);
      while (history[0]?.role === "assistant") history.shift();
      if (!history.length) throw new HttpError("Brak pytania.");
      const { name, description } = assistantCtx(a);
      return { reply: await aiChat(db().brand, a, name, description, history) };
    },
  ],
  [
    "GET",
    /^\/api\/assistants\/([^/]+)$/,
    (m) => {
      const a = db().assistants.find((x) => x.id === m[1]);
      if (!a) throw new HttpError("Nie znaleziono asystenta.");
      return a;
    },
  ],
  [
    "PATCH",
    /^\/api\/assistants\/([^/]+)$/,
    async (m, init) => {
      const a = db().assistants.find((x) => x.id === m[1]);
      if (!a) throw new HttpError("Nie znaleziono asystenta.");
      const b = body(init) as Partial<Assistant>;
      for (const k of ["name", "greeting", "knowledge", "handoff", "language"] as const) if (typeof b[k] === "string") a[k] = b[k];
      await save();
      return a;
    },
  ],
  [
    "DELETE",
    /^\/api\/assistants\/([^/]+)$/,
    async (m) => {
      db().assistants = db().assistants.filter((x) => x.id !== m[1]);
      await save();
      return { ok: true };
    },
  ],
  [
    "POST",
    /^\/api\/reset$/,
    async () => {
      await resetAll();
      return { ok: true };
    },
  ],
];

export async function handle<T>(path: string, init?: Init): Promise<T> {
  await boot();
  const method = (init?.method ?? "GET").toUpperCase();
  const clean = path.split("?")[0];
  for (const [m, re, fn] of routes) {
    const match = m === method ? clean.match(re) : null;
    if (match) {
      const result = await fn(match, init);
      // Hand out copies so components never mutate the stored state.
      return structuredClone(result) as T;
    }
  }
  throw new Error(`Nieobsługiwane: ${method} ${clean}`);
}

export type { Database };

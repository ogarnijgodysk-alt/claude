import type { Database } from "../types";
import { forgetFile, registerFile } from "./registry";

// IndexedDB persistence for browser-storage mode. If IndexedDB is unavailable (private window,
// blocked storage, sandboxed preview) everything keeps working in memory for the session.

const DB_NAME = "clonestudio";
const EMPTY: Database = {
  brand: {
    name: "",
    industry: "",
    description: "",
    audience: "",
    tone: "Przyjazny, ekspercki, konkretny",
    language: "polski",
    ctas: "",
    bannedWords: "",
    aiDisclosure: true,
    updatedAt: new Date(0).toISOString(),
  },
  clones: [],
  videos: [],
  photos: [],
  assistants: [],
};

let idb: IDBDatabase | null = null;
let state: Database = structuredClone(EMPTY);

function req<T>(r: IDBRequest<T>) {
  return new Promise<T>((resolve, reject) => {
    r.onsuccess = () => resolve(r.result);
    r.onerror = () => reject(r.error);
  });
}

function open() {
  return new Promise<IDBDatabase>((resolve, reject) => {
    const r = indexedDB.open(DB_NAME, 1);
    r.onupgradeneeded = () => {
      r.result.createObjectStore("kv");
      r.result.createObjectStore("files");
    };
    r.onsuccess = () => resolve(r.result);
    r.onerror = () => reject(r.error);
  });
}

let booted: Promise<void> | undefined;

/** Loads the saved database and registers every stored file before the first render. */
export function boot() {
  return (booted ??= (async () => {
    try {
      idb = await open();
      const tx = idb.transaction(["kv", "files"], "readonly");
      const saved = (await req(tx.objectStore("kv").get("db"))) as Database | undefined;
      if (saved) state = { ...structuredClone(EMPTY), ...saved };
      const keys = (await req(tx.objectStore("files").getAllKeys())) as string[];
      const blobs = (await req(tx.objectStore("files").getAll())) as Blob[];
      keys.forEach((k, i) => registerFile(k, blobs[i]));
    } catch {
      idb = null;
    }
  })());
}

export function db() {
  return state;
}

export async function save() {
  if (!idb) return;
  try {
    await req(idb.transaction("kv", "readwrite").objectStore("kv").put(state, "db"));
  } catch {
    // keep working in memory
  }
}

const EXT: Record<string, string> = {
  "video/mp4": "mp4",
  "video/webm": "webm",
  "video/quicktime": "mov",
  "audio/mp4": "m4a",
  "audio/webm": "webm",
  "audio/mpeg": "mp3",
  "audio/ogg": "ogg",
  "audio/wav": "wav",
  "image/jpeg": "jpg",
  "image/png": "png",
  "image/webp": "webp",
  "image/svg+xml": "svg",
};

export async function putFile(blob: Blob, prefix: string) {
  const ext = EXT[blob.type.split(";")[0]] ?? "bin";
  const name = `${prefix}_${crypto.randomUUID().replace(/-/g, "")}.${ext}`;
  registerFile(name, blob);
  if (idb) {
    try {
      await req(idb.transaction("files", "readwrite").objectStore("files").put(blob, name));
    } catch {
      // memory only
    }
  }
  return name;
}

export async function deleteFiles(names: (string | undefined)[]) {
  for (const n of names.filter((x): x is string => Boolean(x))) {
    forgetFile(n);
    if (idb) {
      try {
        await req(idb.transaction("files", "readwrite").objectStore("files").delete(n));
      } catch {
        // ignore
      }
    }
  }
}

/** Wipes everything this browser stored (the "start over" button in settings). */
export async function resetAll() {
  const all = [
    ...state.clones.flatMap((c) => [c.coverFile, c.trainingVideoFile, c.consentVideoFile, c.voice.sampleFile, ...c.photoFiles]),
    ...state.videos.map((v) => v.audioFile),
    ...state.photos.flatMap((p) => p.images),
  ];
  await deleteFiles(all);
  state = structuredClone(EMPTY);
  await save();
}

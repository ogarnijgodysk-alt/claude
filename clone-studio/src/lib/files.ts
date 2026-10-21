import { promises as fs } from "fs";
import path from "path";
import { DATA_DIR } from "./db";
import { falUpload, hasFal } from "./providers/fal";
import { hasKie, kieUpload } from "./providers/kie";

export const UPLOAD_DIR = path.join(DATA_DIR, "uploads");

const EXT_BY_TYPE: Record<string, string> = {
  "video/webm": "webm",
  "video/mp4": "mp4",
  "video/quicktime": "mov",
  "audio/webm": "webm",
  "audio/ogg": "ogg",
  "audio/mpeg": "mp3",
  "audio/mp4": "m4a",
  "audio/wav": "wav",
  "audio/x-wav": "wav",
  "image/jpeg": "jpg",
  "image/png": "png",
  "image/webp": "webp",
  "image/svg+xml": "svg",
};

export const TYPE_BY_EXT: Record<string, string> = {
  webm: "video/webm",
  mp4: "video/mp4",
  mov: "video/quicktime",
  ogg: "audio/ogg",
  mp3: "audio/mpeg",
  m4a: "audio/mp4",
  wav: "audio/wav",
  jpg: "image/jpeg",
  png: "image/png",
  webp: "image/webp",
  svg: "image/svg+xml",
};

function baseType(type: string) {
  return type.split(";")[0].trim().toLowerCase();
}

/** Stored files are referenced by bare name; this rejects anything that could escape UPLOAD_DIR. */
export function isSafeName(name: string) {
  return /^[a-zA-Z0-9_-]+\.[a-z0-9]+$/.test(name);
}

export async function saveBuffer(data: Buffer | Uint8Array, type: string, prefix = "f") {
  const ext = EXT_BY_TYPE[baseType(type)] ?? "bin";
  const name = `${prefix}_${crypto.randomUUID().replace(/-/g, "")}.${ext}`;
  await fs.mkdir(UPLOAD_DIR, { recursive: true });
  await fs.writeFile(path.join(UPLOAD_DIR, name), data);
  return name;
}

export async function saveUpload(file: File, prefix = "f") {
  return saveBuffer(Buffer.from(await file.arrayBuffer()), file.type || "application/octet-stream", prefix);
}

export async function saveText(text: string, type: string, prefix = "f") {
  return saveBuffer(Buffer.from(text, "utf8"), type, prefix);
}

export async function readStored(name: string) {
  if (!isSafeName(name)) throw new Error("Nieprawidłowa nazwa pliku");
  const data = await fs.readFile(path.join(UPLOAD_DIR, name));
  const ext = name.split(".").pop() ?? "";
  return { data, type: TYPE_BY_EXT[ext] ?? "application/octet-stream" };
}

/** URL the browser uses to load a stored file. */
export function fileUrl(name: string) {
  return `/api/files/${name}`;
}

/**
 * URL a third-party API (HeyGen, kie.ai, fal) can download the file from.
 * Prefers this app's own public address; falls back to kie.ai or fal.ai temporary storage.
 */
export async function publicFileUrl(name: string) {
  const base = process.env.PUBLIC_BASE_URL?.replace(/\/$/, "");
  if (base) return `${base}${fileUrl(name)}`;
  if (hasKie() || hasFal()) {
    const { data, type } = await readStored(name);
    if (hasKie()) return kieUpload(data, type, name);
    return falUpload(new Blob([new Uint8Array(data)], { type }));
  }
  throw new Error(
    "Brak publicznego adresu dla plików. Ustaw PUBLIC_BASE_URL (adres wdrożenia lub tunel ngrok) albo KIE_API_KEY / FAL_KEY.",
  );
}

/** Downloads a provider result (signed URLs expire) into our own storage. */
export async function archiveRemote(url: string, fallbackType: string, prefix: string) {
  const res = await fetch(url);
  if (!res.ok) throw new Error(`Nie udało się pobrać wyniku (${res.status})`);
  return saveBuffer(Buffer.from(await res.arrayBuffer()), res.headers.get("content-type") ?? fallbackType, prefix);
}

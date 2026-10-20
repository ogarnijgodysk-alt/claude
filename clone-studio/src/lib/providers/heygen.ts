import { publicFileUrl, readStored } from "../files";

// HeyGen API v3 (v1/v2 are retired on 2026-11-01).
// Docs: https://developers.heygen.com/docs/create-avatar, /reference/create-video, /docs/avatar-consent

const BASE = "https://api.heygen.com";

export function hasHeygen() {
  return Boolean(process.env.HEYGEN_API_KEY);
}

export class HeygenError extends Error {}

async function call<T = Record<string, unknown>>(method: "GET" | "POST", path: string, body?: unknown): Promise<T> {
  const res = await fetch(`${BASE}${path}`, {
    method,
    headers: {
      "x-api-key": process.env.HEYGEN_API_KEY ?? "",
      accept: "application/json",
      ...(body ? { "content-type": "application/json" } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  let json: Record<string, unknown> = {};
  try {
    json = text ? JSON.parse(text) : {};
  } catch {
    // Non-JSON error page; surface the raw text below.
  }
  if (!res.ok || json.error) {
    const err = json.error as { message?: string } | string | undefined;
    const msg = typeof err === "string" ? err : err?.message ?? (json.message as string) ?? text.slice(0, 300);
    throw new HeygenError(`HeyGen ${res.status}: ${msg}`);
  }
  // v3 wraps payloads in { data: ... }
  return (json.data ?? json) as T;
}

/**
 * Training footage is biometric data, so it is sent inline (base64) unless the app
 * itself has a public URL — never through a third-party file host.
 */
async function fileRef(name: string) {
  if (process.env.PUBLIC_BASE_URL) return { type: "url", url: await publicFileUrl(name) };
  const { data, type } = await readStored(name);
  return { type: "base64", media_type: type, data: data.toString("base64") };
}

interface CreatedAvatar {
  avatar_item?: { id?: string };
  avatar_group?: { id?: string };
}

export async function createAvatar(kind: "digital_twin" | "photo", name: string, file: string) {
  const data = await call<CreatedAvatar>("POST", "/v3/avatars", { type: kind, name, file: await fileRef(file) });
  const avatarId = data.avatar_item?.id;
  if (!avatarId) throw new HeygenError("HeyGen nie zwrócił ID awatara");
  return { avatarId, groupId: data.avatar_group?.id };
}

/** Starts HeyGen's webcam consent flow; the returned link is valid for 24h for the person being cloned. */
export async function startConsent(groupId: string, rerouteUrl?: string) {
  const data = await call<Record<string, unknown>>("POST", `/v3/avatars/${groupId}/consent`, {
    ...(rerouteUrl ? { reroute_url: rerouteUrl } : {}),
  });
  const url = (data.url ?? data.consent_url) as string | undefined;
  if (!url) throw new HeygenError("HeyGen nie zwrócił linku do zgody");
  return url;
}

export type HeygenState = "processing" | "ready" | "failed";

function mapStatus(raw: unknown): HeygenState {
  const s = String(raw ?? "").toLowerCase();
  if (["completed", "complete", "ready", "active", "succeeded", "success"].includes(s)) return "ready";
  if (["failed", "error", "rejected", "canceled", "cancelled"].includes(s)) return "failed";
  return "processing";
}

export async function getAvatarLook(lookId: string) {
  const data = await call<Record<string, unknown>>("GET", `/v3/avatars/looks/${lookId}`);
  return {
    state: mapStatus(data.status),
    consentStatus: (data.consent_status as string | null) ?? undefined,
    voiceId: (data.voice_id ?? data.default_voice_id) as string | undefined,
    previewImage: (data.preview_image_url ?? data.image_url) as string | undefined,
    error: (data.error_message ?? data.failure_reason) as string | undefined,
  };
}

export interface VideoRequest {
  avatarId: string;
  title: string;
  aspectRatio: "9:16" | "1:1" | "16:9";
  /** Either a script spoken by voiceId, or an audio track the avatar lip-syncs to. */
  script?: string;
  voiceId?: string;
  audioFile?: string;
}

export async function createVideo(req: VideoRequest) {
  const speech = req.audioFile
    ? { audio_url: await publicFileUrl(req.audioFile) }
    : { script: req.script, voice_id: req.voiceId };
  const data = await call<{ video_id?: string }>("POST", "/v3/videos", {
    type: "avatar",
    avatar_id: req.avatarId,
    title: req.title,
    resolution: "1080p",
    aspect_ratio: req.aspectRatio,
    ...speech,
  });
  if (!data.video_id) throw new HeygenError("HeyGen nie zwrócił ID wideo");
  return data.video_id;
}

export async function getVideo(videoId: string) {
  const data = await call<Record<string, unknown>>("GET", `/v3/videos/${videoId}`);
  const err = data.error as { message?: string } | string | undefined;
  return {
    state: mapStatus(data.status),
    videoUrl: data.video_url as string | undefined,
    thumbnailUrl: data.thumbnail_url as string | undefined,
    error: typeof err === "string" ? err : err?.message,
  };
}

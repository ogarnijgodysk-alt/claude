// kie.ai: one API key, discounted access to many models. We use it for
// - google/nano-banana         AI influencer faces (text to image)
// - google/nano-banana-edit    photo shoots of a clone from its reference photos (no training needed)
// - kling/ai-avatar-standard   talking video from one photo + an audio track (cheaper than HeyGen per second)
// Docs: https://docs.kie.ai (Market API: createTask + recordInfo; File Upload API)

const API = "https://api.kie.ai/api/v1";
const UPLOAD = "https://kieai.redpandaai.co/api/file-base64-upload";

export const KIE_MODELS = {
  textToImage: "google/nano-banana",
  editImage: "google/nano-banana-edit",
  avatar: process.env.KIE_AVATAR_MODEL || "kling/ai-avatar-standard",
} as const;

export function hasKie() {
  return Boolean(process.env.KIE_API_KEY);
}

function headers() {
  return { authorization: `Bearer ${process.env.KIE_API_KEY ?? ""}`, "content-type": "application/json" };
}

interface Envelope<T> {
  code?: number;
  msg?: string;
  message?: string;
  data?: T;
}

async function call<T>(url: string, init: RequestInit): Promise<T> {
  const res = await fetch(url, { ...init, headers: headers() });
  const text = await res.text();
  let json: Envelope<T> = {};
  try {
    json = text ? JSON.parse(text) : {};
  } catch {
    // keep raw text for the error below
  }
  // kie.ai reports most errors as HTTP 200 with a non-200 `code` in the body.
  if (!res.ok || (json.code !== undefined && json.code !== 200)) {
    throw new Error(`kie.ai ${json.code ?? res.status}: ${json.msg ?? json.message ?? text.slice(0, 300)}`);
  }
  return json.data as T;
}

export async function createTask(model: string, input: Record<string, unknown>) {
  const data = await call<{ taskId?: string }>(`${API}/jobs/createTask`, {
    method: "POST",
    body: JSON.stringify({ model, input }),
  });
  if (!data?.taskId) throw new Error("kie.ai nie zwróciło ID zadania");
  return data.taskId;
}

export type KieJob = { status: "processing" } | { status: "ready"; urls: string[] } | { status: "failed"; error: string };

export async function getTask(taskId: string): Promise<KieJob> {
  const data = await call<{ state?: string; resultJson?: string; failMsg?: string; failCode?: string }>(
    `${API}/jobs/recordInfo?taskId=${encodeURIComponent(taskId)}`,
    { method: "GET" },
  );
  const state = data?.state;
  if (state === "success") {
    let urls: string[] = [];
    try {
      const parsed = JSON.parse(data.resultJson ?? "{}") as { resultUrls?: string[] };
      urls = parsed.resultUrls ?? [];
    } catch {
      // fall through to the empty-result error
    }
    return urls.length ? { status: "ready", urls } : { status: "failed", error: "kie.ai nie zwróciło wyniku" };
  }
  if (state === "fail") return { status: "failed", error: data.failMsg || `kie.ai: błąd ${data.failCode ?? ""}`.trim() };
  return { status: "processing" };
}

/** Uploads a file to kie.ai's temporary storage (kept ~1–3 days) and returns its public URL. */
export async function kieUpload(data: Buffer, type: string, fileName: string) {
  const res = await call<{ downloadUrl?: string; fileUrl?: string; url?: string }>(UPLOAD, {
    method: "POST",
    body: JSON.stringify({
      base64Data: `data:${type};base64,${data.toString("base64")}`,
      uploadPath: "clone-studio",
      fileName,
    }),
  });
  const url = res?.downloadUrl ?? res?.fileUrl ?? res?.url;
  if (!url) throw new Error("kie.ai nie zwróciło adresu przesłanego pliku");
  return url;
}

const ASPECT = { "9:16": "9:16", "1:1": "1:1", "16:9": "16:9" } as const;

export function startFace(prompt: string) {
  return createTask(KIE_MODELS.textToImage, { prompt, output_format: "png", aspect_ratio: "1:1" });
}

/** One image per task; the clone's reference photos keep the face consistent. */
export function startSceneEdit(referenceUrls: string[], prompt: string, aspect: keyof typeof ASPECT) {
  return createTask(KIE_MODELS.editImage, {
    prompt,
    image_urls: referenceUrls.slice(0, 5),
    output_format: "png",
    aspect_ratio: ASPECT[aspect],
  });
}

export function startAvatarVideo(imageUrl: string, audioUrl: string, prompt?: string) {
  return createTask(KIE_MODELS.avatar, {
    image_url: imageUrl,
    audio_url: audioUrl,
    prompt: prompt ?? "A person talking naturally to the camera, subtle head movement, friendly expression",
  });
}

/** Waits for several tasks inside one request; used where the UI expects an immediate answer. */
export async function waitForTasks(taskIds: string[], timeoutMs = 110_000) {
  const deadline = Date.now() + timeoutMs;
  const results = new Map<string, KieJob>();
  while (Date.now() < deadline && results.size < taskIds.length) {
    await new Promise((r) => setTimeout(r, 2500));
    for (const id of taskIds.filter((t) => !results.has(t))) {
      const job = await getTask(id);
      if (job.status !== "processing") results.set(id, job);
    }
  }
  return taskIds.map((id) => results.get(id) ?? ({ status: "processing" } as KieJob));
}

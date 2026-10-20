import { fal } from "@fal-ai/client";

// fal.ai hosts the image models: a portrait LoRA trainer for photo clones
// (mysnapface-style "AI photos of you") and FLUX for persona faces and photo shoots.

const TRAINER = "fal-ai/flux-lora-portrait-trainer";
const IMAGE_MODELS = {
  /** Clone's own trained LoRA: best likeness. */
  lora: "fal-ai/flux-lora",
  /** Identity from a single reference photo: used when no LoRA exists. */
  pulid: "fal-ai/flux-pulid",
  text: "fal-ai/flux/dev",
} as const;
export type ImageModel = keyof typeof IMAGE_MODELS;

let configured = false;

export function hasFal() {
  return Boolean(process.env.FAL_KEY);
}

function client() {
  if (!configured) {
    fal.config({ credentials: process.env.FAL_KEY });
    configured = true;
  }
  return fal;
}

export async function falUpload(blob: Blob) {
  return client().storage.upload(blob);
}

export type FalJob = { status: "processing" } | { status: "ready"; result: unknown } | { status: "failed"; error: string };

async function poll(endpoint: string, requestId: string): Promise<FalJob> {
  const c = client();
  const status = await c.queue.status(endpoint, { requestId });
  if (status.status !== "COMPLETED") return { status: "processing" };
  try {
    const { data } = await c.queue.result(endpoint, { requestId });
    return { status: "ready", result: data };
  } catch (err) {
    return { status: "failed", error: err instanceof Error ? err.message : String(err) };
  }
}

/** Starts LoRA training on a zip of face photos. Returns the queue request id. */
export async function startPortraitTraining(zip: Blob, triggerWord: string) {
  const c = client();
  const zipUrl = await c.storage.upload(zip);
  const { request_id } = await c.queue.submit(TRAINER, {
    input: { images_data_url: zipUrl, trigger_phrase: triggerWord, steps: 2500 },
  });
  return request_id;
}

export async function pollPortraitTraining(requestId: string): Promise<FalJob> {
  const job = await poll(TRAINER, requestId);
  if (job.status !== "ready") return job;
  const url = (job.result as { diffusers_lora_file?: { url?: string } }).diffusers_lora_file?.url;
  return url ? { status: "ready", result: url } : { status: "failed", error: "Trening nie zwrócił pliku LoRA" };
}

const SIZE = { "9:16": "portrait_16_9", "1:1": "square_hd", "16:9": "landscape_16_9" } as const;

export async function startLoraImages(loraUrl: string, prompt: string, aspect: keyof typeof SIZE, count = 4) {
  const { request_id } = await client().queue.submit(IMAGE_MODELS.lora, {
    input: {
      prompt,
      loras: [{ path: loraUrl, scale: 1 }],
      image_size: SIZE[aspect],
      num_images: count,
      output_format: "jpeg",
    },
  });
  return request_id;
}

/** PuLID returns one image per request, so callers submit one job per wanted image. */
export async function startPulidImage(referenceUrl: string, prompt: string, aspect: keyof typeof SIZE) {
  const { request_id } = await client().queue.submit(IMAGE_MODELS.pulid, {
    input: { prompt, reference_image_url: referenceUrl, image_size: SIZE[aspect] },
  });
  return request_id;
}

function imageUrls(result: unknown) {
  return ((result as { images?: { url: string }[] }).images ?? []).map((i) => i.url);
}

export async function pollImages(model: ImageModel, requestId: string): Promise<FalJob> {
  const job = await poll(IMAGE_MODELS[model], requestId);
  return job.status === "ready" ? { status: "ready", result: imageUrls(job.result) } : job;
}

/** Synchronous generation, used for the handful of face candidates in the influencer wizard. */
export async function generateTextImages(prompt: string, aspect: keyof typeof SIZE, count = 4) {
  const { data } = await client().subscribe(IMAGE_MODELS.text, {
    input: { prompt, image_size: SIZE[aspect], num_images: count, output_format: "jpeg" },
  });
  return imageUrls(data);
}

import { newId, readDb, updateDb } from "../db";
import { fileUrl, publicFileUrl, saveBuffer, saveText } from "../files";
import { demoJobDone, demoPortraitSvg } from "../providers/demo";
import { hasFal, pollImages, startLoraImages, startPulidImage } from "../providers/fal";
import type { AspectRatio, PhotoSet } from "../types";

export const SCENES: Record<string, string> = {
  biuro: "professional headshot in a modern bright office, business casual outfit, soft window light",
  konferencja: "speaking on stage at a business conference, microphone, stage lights, audience bokeh",
  produkt: "holding and presenting a product to the camera, clean studio background, commercial photo",
  kawiarnia: "candid lifestyle photo in a cozy cafe, laptop on the table, natural light",
  plener: "outdoor lifestyle portrait in a city street at golden hour, smiling",
  studio: "high-end studio portrait, seamless backdrop, editorial magazine lighting",
  podcast: "recording a podcast in a studio, professional microphone, headphones, warm light",
  sport: "fitness lifestyle photo in a modern gym, athletic outfit, energetic",
};

export interface CreatePhotosInput {
  cloneId: string;
  scene: string;
  prompt: string;
  aspect: AspectRatio;
  count: number;
}

export async function createPhotoSet(input: CreatePhotosInput): Promise<PhotoSet> {
  const clone = (await readDb()).clones.find((c) => c.id === input.cloneId);
  if (!clone) throw new Error("Nie znaleziono klona.");
  const count = Math.min(Math.max(input.count, 1), 4);
  const scenePrompt = [SCENES[input.scene], input.prompt.trim()].filter(Boolean).join(", ");
  if (!scenePrompt) throw new Error("Wybierz scenę albo opisz zdjęcie.");

  const set: PhotoSet = {
    id: newId("ph"),
    cloneId: clone.id,
    scene: input.scene,
    prompt: scenePrompt,
    aspect: input.aspect,
    status: "processing",
    falJobs: [],
    images: [],
    demo: !hasFal(),
    createdAt: new Date().toISOString(),
  };

  try {
    if (!hasFal()) {
      for (let i = 0; i < count; i++) {
        const svg = demoPortraitSvg(`${set.id}${i}`, `${clone.name} · ${input.scene || "sesja"}`, input.aspect);
        set.images.push(fileUrl(await saveText(svg, "image/svg+xml", "shot")));
      }
    } else if (clone.fal?.loraUrl) {
      const prompt = `photo of ${clone.fal.triggerWord} person, ${scenePrompt}, photorealistic, 85mm`;
      set.falJobs.push({ model: "lora", requestId: await startLoraImages(clone.fal.loraUrl, prompt, input.aspect, count) });
    } else if (clone.coverFile) {
      const ref = await publicFileUrl(clone.coverFile);
      for (let i = 0; i < count; i++) {
        set.falJobs.push({
          model: "pulid",
          requestId: await startPulidImage(ref, `portrait photo of a person, ${scenePrompt}, photorealistic`, input.aspect),
        });
      }
    } else {
      throw new Error("Klon nie ma zdjęcia referencyjnego.");
    }
  } catch (err) {
    set.status = "failed";
    set.error = err instanceof Error ? err.message : String(err);
  }

  await updateDb((db) => {
    db.photos.unshift(set);
  });
  return set;
}

async function archiveImage(url: string) {
  const res = await fetch(url);
  if (!res.ok) return url;
  return fileUrl(await saveBuffer(Buffer.from(await res.arrayBuffer()), res.headers.get("content-type") ?? "image/jpeg", "shot"));
}

export async function refreshPhotoSet(id: string): Promise<PhotoSet | undefined> {
  const current = (await readDb()).photos.find((p) => p.id === id);
  if (!current || current.status !== "processing") return current;

  if (current.demo) {
    if (!demoJobDone(current.createdAt)) return current;
    return updateDb((db) => {
      const p = db.photos.find((x) => x.id === id);
      if (p) p.status = "ready";
      return p;
    });
  }

  const jobs = current.falJobs.map((j) => ({ ...j }));
  const images = [...current.images];
  let error: string | undefined;
  for (const job of jobs.filter((j) => !j.done)) {
    try {
      const res = await pollImages(job.model, job.requestId);
      if (res.status === "ready") {
        for (const url of res.result as string[]) images.push(await archiveImage(url));
        job.done = true;
      } else if (res.status === "failed") {
        job.done = true;
        error = res.error;
      }
    } catch (err) {
      console.error(`refreshPhotoSet ${id}:`, err);
    }
  }

  const allDone = jobs.every((j) => j.done);
  return updateDb((db) => {
    const p = db.photos.find((x) => x.id === id);
    if (!p) return p;
    p.falJobs = jobs;
    p.images = images;
    if (error) p.error = error;
    if (allDone) p.status = images.length ? "ready" : "failed";
    return p;
  });
}

export async function deletePhotoSet(id: string) {
  await updateDb((db) => {
    db.photos = db.photos.filter((p) => p.id !== id);
  });
}

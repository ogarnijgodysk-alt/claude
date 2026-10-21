import { newId, readDb, updateDb } from "../db";
import { archiveRemote, fileUrl, publicFileUrl, saveText } from "../files";
import { demoJobDone, demoPortraitSvg } from "../providers/demo";
import { hasFal, pollImages, startLoraImages, startPulidImage, type ImageModel } from "../providers/fal";
import { getTask, hasKie, startSceneEdit } from "../providers/kie";
import type { AspectRatio, Clone, PhotoSet } from "../types";

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

/** The clone's own photos (or its cover) are the identity references for image editing models. */
function referenceFiles(clone: Clone) {
  const files = clone.photoFiles.length ? clone.photoFiles : clone.coverFile ? [clone.coverFile] : [];
  return files.filter((f) => !f.endsWith(".svg")).slice(0, 4);
}

async function startJobs(clone: Clone, scenePrompt: string, aspect: AspectRatio, count: number): Promise<PhotoSet["jobs"]> {
  // A trained LoRA (fal) still gives the best likeness, so it wins when it exists.
  if (hasFal() && clone.fal?.loraUrl) {
    const prompt = `photo of ${clone.fal.triggerWord} person, ${scenePrompt}, photorealistic, 85mm`;
    return [{ provider: "fal", model: "lora", requestId: await startLoraImages(clone.fal.loraUrl, prompt, aspect, count) }];
  }
  const refs = referenceFiles(clone);
  if (!refs.length) throw new Error("Klon nie ma zdjęcia referencyjnego.");
  if (hasKie()) {
    const urls = await Promise.all(refs.map(publicFileUrl));
    const prompt = `Photo of the same person as in the reference images, keep the face, hair and identity exactly the same. Scene: ${scenePrompt}. Photorealistic, natural skin, professional photography.`;
    const ids = await Promise.all(Array.from({ length: count }, () => startSceneEdit(urls, prompt, aspect)));
    return ids.map((requestId) => ({ provider: "kie", model: "nano-banana-edit", requestId }));
  }
  const ref = await publicFileUrl(refs[0]);
  const ids = await Promise.all(
    Array.from({ length: count }, () => startPulidImage(ref, `portrait photo of a person, ${scenePrompt}, photorealistic`, aspect)),
  );
  return ids.map((requestId) => ({ provider: "fal", model: "pulid", requestId }));
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
    jobs: [],
    images: [],
    demo: !hasKie() && !hasFal(),
    createdAt: new Date().toISOString(),
  };

  try {
    if (set.demo) {
      for (let i = 0; i < count; i++) {
        const svg = demoPortraitSvg(`${set.id}${i}`, `${clone.name} · ${input.scene || "sesja"}`, input.aspect);
        set.images.push(fileUrl(await saveText(svg, "image/svg+xml", "shot")));
      }
    } else {
      set.jobs = await startJobs(clone, scenePrompt, input.aspect, count);
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

async function pollJob(job: PhotoSet["jobs"][number]) {
  if (job.provider === "kie") {
    const r = await getTask(job.requestId);
    return r.status === "ready" ? { status: "ready" as const, urls: r.urls } : r;
  }
  const r = await pollImages(job.model as ImageModel, job.requestId);
  return r.status === "ready" ? { status: "ready" as const, urls: r.result as string[] } : r;
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

  const jobs = current.jobs.map((j) => ({ ...j }));
  const images = [...current.images];
  let error: string | undefined;
  for (const job of jobs.filter((j) => !j.done)) {
    try {
      const res = await pollJob(job);
      if (res.status === "ready") {
        for (const url of res.urls) images.push(fileUrl(await archiveRemote(url, "image/png", "shot")));
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
    p.jobs = jobs;
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

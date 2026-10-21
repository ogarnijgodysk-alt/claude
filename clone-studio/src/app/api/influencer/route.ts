import { readDb } from "@/lib/db";
import { archiveRemote, fileUrl, saveText } from "@/lib/files";
import { fail, ok } from "@/lib/http";
import { designPersona } from "@/lib/providers/claude";
import { demoPortraitSvg } from "@/lib/providers/demo";
import { generateTextImages, hasFal } from "@/lib/providers/fal";
import { hasKie, startFace, waitForTasks } from "@/lib/providers/kie";

export const dynamic = "force-dynamic";
export const maxDuration = 120;

async function renderFaces(prompt: string, seed: string): Promise<string[]> {
  if (hasKie()) {
    const ids = await Promise.all(Array.from({ length: 4 }, () => startFace(prompt)));
    const jobs = await waitForTasks(ids);
    const urls = jobs.flatMap((j) => (j.status === "ready" ? j.urls.slice(0, 1) : []));
    if (!urls.length) throw new Error("Nie udało się wygenerować twarzy. Spróbuj ponownie.");
    return Promise.all(urls.map((u) => archiveRemote(u, "image/png", "face")));
  }
  if (hasFal()) {
    const urls = await generateTextImages(prompt, "1:1", 4);
    return Promise.all(urls.map((u) => archiveRemote(u, "image/jpeg", "face")));
  }
  const names: string[] = [];
  for (let i = 0; i < 4; i++) names.push(await saveText(demoPortraitSvg(`${seed}${i}${Date.now()}`, seed), "image/svg+xml", "face"));
  return names;
}

/** Designs a fictional persona and renders face candidates to choose from. */
export async function POST(req: Request) {
  try {
    const { description } = (await req.json()) as { description?: string };
    if (!description?.trim()) throw new Error("Opisz, jak ma wyglądać i kim ma być influencer.");
    const persona = await designPersona((await readDb()).brand, description.trim().slice(0, 2000));
    // Faces are stored before they reach the browser, so creating the clone never fetches a client-supplied URL.
    const names = await renderFaces(persona.imagePrompt, persona.name);
    return ok({ persona, faces: names.map((ref) => ({ ref, src: fileUrl(ref) })) });
  } catch (err) {
    return fail(err, 500);
  }
}

import { readDb } from "@/lib/db";
import { fileUrl, saveText } from "@/lib/files";
import { fail, ok } from "@/lib/http";
import { designPersona } from "@/lib/providers/claude";
import { demoPortraitSvg } from "@/lib/providers/demo";
import { generateTextImages, hasFal } from "@/lib/providers/fal";

export const dynamic = "force-dynamic";
export const maxDuration = 120;

/** Designs a fictional persona and renders face candidates to choose from. */
export async function POST(req: Request) {
  try {
    const { description } = (await req.json()) as { description?: string };
    if (!description?.trim()) throw new Error("Opisz, jak ma wyglądać i kim ma być influencer.");
    const persona = await designPersona((await readDb()).brand, description.trim().slice(0, 2000));

    // `ref` is what gets sent back on create: a fal URL or a stored file name.
    let faces: { ref: string; src: string }[];
    if (hasFal()) {
      const urls = await generateTextImages(persona.imagePrompt, "1:1", 4);
      faces = urls.map((u) => ({ ref: u, src: u }));
    } else {
      faces = [];
      for (let i = 0; i < 4; i++) {
        const name = await saveText(demoPortraitSvg(`${persona.name}${i}${Date.now()}`, persona.name), "image/svg+xml", "face");
        faces.push({ ref: name, src: fileUrl(name) });
      }
    }
    return ok({ persona, faces });
  } catch (err) {
    return fail(err, 500);
  }
}

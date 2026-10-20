import { readDb } from "@/lib/db";
import { fail, file, files, ok, str } from "@/lib/http";
import { createClone, refreshClone } from "@/lib/services/clones";
import type { CloneKind } from "@/lib/types";

export const dynamic = "force-dynamic";

export async function GET() {
  const { clones } = await readDb();
  const fresh = await Promise.all(
    clones.map((c) => (c.status === "processing" || c.loraStatus === "processing" ? refreshClone(c.id) : c)),
  );
  return ok(fresh.filter(Boolean));
}

const KINDS: CloneKind[] = ["digital_twin", "photo_clone", "ai_influencer"];

export async function POST(req: Request) {
  try {
    const form = await req.formData();
    const kind = str(form, "kind") as CloneKind;
    if (!KINDS.includes(kind)) throw new Error("Nieznany typ klona.");
    const voiceProvider = str(form, "voiceProvider");
    const clone = await createClone({
      kind,
      name: str(form, "name"),
      description: str(form, "description"),
      subjectName: str(form, "subjectName"),
      consentConfirmed: str(form, "consent") === "true",
      trainingVideo: file(form, "trainingVideo"),
      consentVideo: file(form, "consentVideo"),
      cover: file(form, "cover"),
      coverRef: str(form, "coverRef") || undefined,
      photos: files(form, "photos"),
      voiceSample: file(form, "voiceSample"),
      voiceProvider: voiceProvider === "heygen" || voiceProvider === "elevenlabs" ? voiceProvider : undefined,
      voiceId: str(form, "voiceId"),
    });
    return ok(clone, { status: 201 });
  } catch (err) {
    return fail(err);
  }
}

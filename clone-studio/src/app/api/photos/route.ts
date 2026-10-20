import { readDb } from "@/lib/db";
import { fail, ok } from "@/lib/http";
import { createPhotoSet, refreshPhotoSet } from "@/lib/services/photos";
import type { AspectRatio } from "@/lib/types";

export const dynamic = "force-dynamic";

export async function GET() {
  const { photos } = await readDb();
  const fresh = await Promise.all(photos.map((p) => (p.status === "processing" ? refreshPhotoSet(p.id) : p)));
  return ok(fresh.filter(Boolean));
}

const ASPECTS: AspectRatio[] = ["9:16", "1:1", "16:9"];

export async function POST(req: Request) {
  try {
    const body = (await req.json()) as { cloneId?: string; scene?: string; prompt?: string; aspect?: AspectRatio; count?: number };
    const set = await createPhotoSet({
      cloneId: body.cloneId ?? "",
      scene: body.scene ?? "",
      prompt: (body.prompt ?? "").slice(0, 1000),
      aspect: body.aspect && ASPECTS.includes(body.aspect) ? body.aspect : "1:1",
      count: Number(body.count) || 4,
    });
    return ok(set, { status: 201 });
  } catch (err) {
    return fail(err);
  }
}

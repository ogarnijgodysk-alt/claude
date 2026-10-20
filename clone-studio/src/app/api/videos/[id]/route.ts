import { fail, ok } from "@/lib/http";
import { deleteVideo, refreshVideo } from "@/lib/services/videos";

export const dynamic = "force-dynamic";

type Ctx = { params: Promise<{ id: string }> };

export async function GET(_req: Request, { params }: Ctx) {
  const video = await refreshVideo((await params).id);
  return video ? ok(video) : fail("Nie znaleziono wideo.", 404);
}

export async function DELETE(_req: Request, { params }: Ctx) {
  await deleteVideo((await params).id);
  return ok({ ok: true });
}

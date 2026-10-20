import { fail, ok } from "@/lib/http";
import { deletePhotoSet, refreshPhotoSet } from "@/lib/services/photos";

export const dynamic = "force-dynamic";

type Ctx = { params: Promise<{ id: string }> };

export async function GET(_req: Request, { params }: Ctx) {
  const set = await refreshPhotoSet((await params).id);
  return set ? ok(set) : fail("Nie znaleziono sesji.", 404);
}

export async function DELETE(_req: Request, { params }: Ctx) {
  await deletePhotoSet((await params).id);
  return ok({ ok: true });
}

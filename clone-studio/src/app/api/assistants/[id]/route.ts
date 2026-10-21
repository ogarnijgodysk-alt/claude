import { fail, ok } from "@/lib/http";
import { deleteAssistant, refreshAssistant, updateAssistant } from "@/lib/services/assistants";

export const dynamic = "force-dynamic";

type Ctx = { params: Promise<{ id: string }> };

export async function GET(_req: Request, { params }: Ctx) {
  const a = await refreshAssistant((await params).id);
  return a ? ok(a) : fail("Nie znaleziono asystenta.", 404);
}

export async function PATCH(req: Request, { params }: Ctx) {
  try {
    return ok(await updateAssistant((await params).id, await req.json()));
  } catch (err) {
    return fail(err);
  }
}

export async function DELETE(_req: Request, { params }: Ctx) {
  await deleteAssistant((await params).id);
  return ok({ ok: true });
}

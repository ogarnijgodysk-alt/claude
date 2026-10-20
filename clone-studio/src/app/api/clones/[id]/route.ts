import { fail, ok } from "@/lib/http";
import { deleteClone, refreshClone, updateCloneVoice } from "@/lib/services/clones";

export const dynamic = "force-dynamic";

type Ctx = { params: Promise<{ id: string }> };

export async function GET(_req: Request, { params }: Ctx) {
  const clone = await refreshClone((await params).id);
  return clone ? ok(clone) : fail("Nie znaleziono klona.", 404);
}

export async function PATCH(req: Request, { params }: Ctx) {
  try {
    const body = (await req.json()) as { voiceProvider?: string; voiceId?: string };
    if (body.voiceProvider !== "heygen" && body.voiceProvider !== "elevenlabs") throw new Error("Wybierz dostawcę głosu.");
    if (!body.voiceId?.trim()) throw new Error("Podaj ID głosu.");
    return ok(await updateCloneVoice((await params).id, { provider: body.voiceProvider, voiceId: body.voiceId.trim() }));
  } catch (err) {
    return fail(err);
  }
}

export async function DELETE(_req: Request, { params }: Ctx) {
  await deleteClone((await params).id);
  return ok({ ok: true });
}

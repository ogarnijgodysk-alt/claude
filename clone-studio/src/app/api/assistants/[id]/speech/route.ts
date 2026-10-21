import { fail, rateLimited } from "@/lib/http";
import { speechFor } from "@/lib/services/assistants";

export const dynamic = "force-dynamic";

/** 204 tells the widget to speak with the browser's own voice instead. */
export async function POST(req: Request, { params }: { params: Promise<{ id: string }> }) {
  if (rateLimited(req, "speech", 40, 10 * 60_000)) return fail("Za dużo zapytań.", 429);
  try {
    const { text } = (await req.json()) as { text?: string };
    const mp3 = text?.trim() ? await speechFor((await params).id, text) : undefined;
    if (!mp3) return new Response(null, { status: 204 });
    return new Response(new Uint8Array(mp3), { headers: { "content-type": "audio/mpeg" } });
  } catch (err) {
    return fail(err, 500);
  }
}

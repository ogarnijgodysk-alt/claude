import { fail, ok, rateLimited } from "@/lib/http";
import { startConversation } from "@/lib/services/assistants";

export const dynamic = "force-dynamic";

export async function POST(req: Request, { params }: { params: Promise<{ id: string }> }) {
  if (rateLimited(req, "conversation", 5, 10 * 60_000)) return fail("Za dużo rozmów naraz. Spróbuj za kilka minut.", 429);
  try {
    return ok(await startConversation((await params).id));
  } catch (err) {
    return fail(err, 500);
  }
}

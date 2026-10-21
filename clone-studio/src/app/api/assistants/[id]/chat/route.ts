import { fail, ok, rateLimited } from "@/lib/http";
import { replyTo } from "@/lib/services/assistants";
import type { ChatMessage } from "@/lib/types";

export const dynamic = "force-dynamic";
export const maxDuration = 60;

export async function POST(req: Request, { params }: { params: Promise<{ id: string }> }) {
  if (rateLimited(req, "chat", 40, 10 * 60_000)) return fail("Za dużo pytań. Spróbuj za kilka minut.", 429);
  try {
    const { messages } = (await req.json()) as { messages?: ChatMessage[] };
    return ok({ reply: await replyTo((await params).id, Array.isArray(messages) ? messages : []) });
  } catch (err) {
    return fail(err, 500);
  }
}

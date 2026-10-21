import { assistantKnowledge, assistantSystemPrompt, demoAnswer } from "@/lib/assistant-prompt";
import { brandFromBody } from "@/lib/brand-input";
import { readDb } from "@/lib/db";
import { fail, ok, rateLimited } from "@/lib/http";
import { chatReply, hasClaude } from "@/lib/providers/claude";
import type { Assistant, ChatMessage } from "@/lib/types";

export const dynamic = "force-dynamic";
export const maxDuration = 60;

/**
 * Stateless website-assistant reply for the browser-storage demo, where the assistant's
 * settings live in the visitor's browser. The prompt is still assembled here from known fields.
 */
export async function POST(req: Request) {
  if (rateLimited(req, "chat", 40, 10 * 60_000)) return fail("Za dużo pytań. Spróbuj za kilka minut.", 429);
  try {
    const body = (await req.json()) as {
      brand?: unknown;
      assistant?: Partial<Assistant>;
      cloneName?: string;
      cloneDescription?: string;
      messages?: ChatMessage[];
    };
    const brand = brandFromBody(body.brand, (await readDb()).brand);
    const a = body.assistant ?? {};
    const assistant = {
      name: String(a.name ?? "").slice(0, 120),
      greeting: "",
      knowledge: String(a.knowledge ?? "").slice(0, 20000),
      handoff: String(a.handoff ?? "").slice(0, 500),
      language: String(a.language ?? "polski").slice(0, 40),
    } as Assistant;
    const messages = (Array.isArray(body.messages) ? body.messages : [])
      .filter((m) => (m.role === "user" || m.role === "assistant") && typeof m.content === "string" && m.content.trim())
      .map((m) => ({ role: m.role, content: m.content.slice(0, 2000) }))
      .slice(-20);
    while (messages[0]?.role === "assistant") messages.shift();
    if (!messages.length) throw new Error("Brak pytania.");
    if (!hasClaude()) return ok({ reply: demoAnswer(assistant, brand, messages) });
    const system = assistantSystemPrompt(brand, assistant, String(body.cloneName ?? assistant.name).slice(0, 120), String(body.cloneDescription ?? "").slice(0, 2000));
    return ok({ reply: await chatReply(system, assistantKnowledge(brand, assistant), messages) });
  } catch (err) {
    return fail(err, 500);
  }
}

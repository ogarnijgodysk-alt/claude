import { brandFromBody } from "@/lib/brand-input";
import { readDb } from "@/lib/db";
import { fail, ok, rateLimited } from "@/lib/http";
import { writeScript } from "@/lib/providers/claude";
import { PLATFORMS, type Platform } from "@/lib/types";

export const dynamic = "force-dynamic";
export const maxDuration = 120;

export async function POST(req: Request) {
  if (rateLimited(req, "ai", 30, 10 * 60_000)) return fail("Za dużo zapytań do AI. Spróbuj za kilka minut.", 429);
  try {
    const body = (await req.json()) as {
      topic?: string;
      platform?: Platform;
      goal?: string;
      seconds?: number;
      cloneId?: string;
      persona?: string;
      notes?: string;
      brand?: unknown;
    };
    if (!body.topic?.trim()) throw new Error("Podaj temat filmu.");
    const platform = body.platform && body.platform in PLATFORMS ? body.platform : "tiktok";
    const db = await readDb();
    const clone = db.clones.find((c) => c.id === body.cloneId);
    const script = await writeScript(brandFromBody(body.brand, db.brand), {
      topic: body.topic.trim().slice(0, 500),
      platform,
      goal: body.goal || "zasięg",
      seconds: Math.min(Math.max(Number(body.seconds) || 30, 10), 180),
      persona: clone ? `${clone.name}. ${clone.description}`.trim() : body.persona?.slice(0, 1000),
      notes: body.notes?.slice(0, 1000),
    });
    return ok(script);
  } catch (err) {
    return fail(err, 500);
  }
}

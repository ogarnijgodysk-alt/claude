import { brandFromBody } from "@/lib/brand-input";
import { readDb } from "@/lib/db";
import { fail, ok, rateLimited } from "@/lib/http";
import { planIdeas } from "@/lib/providers/claude";
import { PLATFORMS, type Platform } from "@/lib/types";

export const dynamic = "force-dynamic";
export const maxDuration = 120;

export async function POST(req: Request) {
  if (rateLimited(req, "ai", 30, 10 * 60_000)) return fail("Za dużo zapytań do AI. Spróbuj za kilka minut.", 429);
  try {
    const body = (await req.json()) as { count?: number; platforms?: Platform[]; focus?: string; brand?: unknown };
    const platforms = (body.platforms ?? []).filter((p) => p in PLATFORMS);
    const count = Math.min(Math.max(Number(body.count) || 7, 1), 20);
    const brand = brandFromBody(body.brand, (await readDb()).brand);
    return ok(await planIdeas(brand, count, platforms.length ? platforms : ["tiktok", "reels"], body.focus?.slice(0, 1000) ?? ""));
  } catch (err) {
    return fail(err, 500);
  }
}

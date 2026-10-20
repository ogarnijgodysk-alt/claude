import { readDb } from "@/lib/db";
import { fail, ok } from "@/lib/http";
import { planIdeas } from "@/lib/providers/claude";
import { PLATFORMS, type Platform } from "@/lib/types";

export const dynamic = "force-dynamic";
export const maxDuration = 120;

export async function POST(req: Request) {
  try {
    const body = (await req.json()) as { count?: number; platforms?: Platform[]; focus?: string };
    const platforms = (body.platforms ?? []).filter((p) => p in PLATFORMS);
    const count = Math.min(Math.max(Number(body.count) || 7, 1), 20);
    return ok(await planIdeas((await readDb()).brand, count, platforms.length ? platforms : ["tiktok", "reels"], body.focus?.slice(0, 1000) ?? ""));
  } catch (err) {
    return fail(err, 500);
  }
}

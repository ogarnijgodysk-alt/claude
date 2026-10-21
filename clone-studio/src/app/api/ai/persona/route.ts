import { brandFromBody } from "@/lib/brand-input";
import { readDb } from "@/lib/db";
import { fail, ok, rateLimited } from "@/lib/http";
import { designPersona } from "@/lib/providers/claude";

export const dynamic = "force-dynamic";
export const maxDuration = 120;

/** Stateless persona design, used by the browser-storage demo (faces are drawn in the browser there). */
export async function POST(req: Request) {
  if (rateLimited(req, "ai", 30, 10 * 60_000)) return fail("Za dużo zapytań do AI. Spróbuj za kilka minut.", 429);
  try {
    const { description, brand } = (await req.json()) as { description?: string; brand?: unknown };
    if (!description?.trim()) throw new Error("Opisz, jak ma wyglądać i kim ma być influencer.");
    return ok(await designPersona(brandFromBody(brand, (await readDb()).brand), description.trim().slice(0, 2000)));
  } catch (err) {
    return fail(err, 500);
  }
}

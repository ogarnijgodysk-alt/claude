import { readDb } from "@/lib/db";
import { fail, ok } from "@/lib/http";
import { writeScript } from "@/lib/providers/claude";
import { PLATFORMS, type Platform } from "@/lib/types";

export const dynamic = "force-dynamic";
export const maxDuration = 120;

export async function POST(req: Request) {
  try {
    const body = (await req.json()) as {
      topic?: string;
      platform?: Platform;
      goal?: string;
      seconds?: number;
      cloneId?: string;
      notes?: string;
    };
    if (!body.topic?.trim()) throw new Error("Podaj temat filmu.");
    const platform = body.platform && body.platform in PLATFORMS ? body.platform : "tiktok";
    const db = await readDb();
    const clone = db.clones.find((c) => c.id === body.cloneId);
    const script = await writeScript(db.brand, {
      topic: body.topic.trim().slice(0, 500),
      platform,
      goal: body.goal || "zasięg",
      seconds: Math.min(Math.max(Number(body.seconds) || 30, 10), 180),
      persona: clone ? `${clone.name}. ${clone.description}`.trim() : undefined,
      notes: body.notes?.slice(0, 1000),
    });
    return ok(script);
  } catch (err) {
    return fail(err, 500);
  }
}

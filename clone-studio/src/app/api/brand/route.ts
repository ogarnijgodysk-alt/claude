import { readDb, updateDb } from "@/lib/db";
import { fail, ok } from "@/lib/http";
import type { Brand } from "@/lib/types";

export const dynamic = "force-dynamic";

export async function GET() {
  return ok((await readDb()).brand);
}

const FIELDS = ["name", "industry", "description", "audience", "tone", "language", "ctas", "bannedWords"] as const;

export async function PUT(req: Request) {
  try {
    const body = (await req.json()) as Partial<Brand>;
    const brand = await updateDb((db) => {
      for (const f of FIELDS) if (typeof body[f] === "string") db.brand[f] = body[f].slice(0, 4000);
      if (typeof body.aiDisclosure === "boolean") db.brand.aiDisclosure = body.aiDisclosure;
      db.brand.updatedAt = new Date().toISOString();
      return db.brand;
    });
    return ok(brand);
  } catch (err) {
    return fail(err);
  }
}

import { fail, ok } from "@/lib/http";
import { renewConsentLink } from "@/lib/services/clones";

export const dynamic = "force-dynamic";

export async function POST(_req: Request, { params }: { params: Promise<{ id: string }> }) {
  try {
    return ok(await renewConsentLink((await params).id));
  } catch (err) {
    return fail(err);
  }
}

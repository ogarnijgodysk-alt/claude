import { ok } from "@/lib/http";
import { providerStatus } from "@/lib/providers/status";

export const dynamic = "force-dynamic";

export function GET() {
  return ok(providerStatus());
}

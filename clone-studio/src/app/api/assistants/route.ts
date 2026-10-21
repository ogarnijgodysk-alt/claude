import { readDb } from "@/lib/db";
import { fail, file, ok, str } from "@/lib/http";
import { createAssistant, refreshAssistant } from "@/lib/services/assistants";

export const dynamic = "force-dynamic";
export const maxDuration = 120;

export async function GET() {
  const { assistants } = await readDb();
  const fresh = await Promise.all(assistants.map((a) => (a.tavus?.replicaStatus === "processing" ? refreshAssistant(a.id) : a)));
  return ok(fresh.filter(Boolean));
}

export async function POST(req: Request) {
  try {
    const form = await req.formData();
    const assistant = await createAssistant({
      cloneId: str(form, "cloneId"),
      name: str(form, "name"),
      greeting: str(form, "greeting"),
      knowledge: str(form, "knowledge"),
      handoff: str(form, "handoff"),
      language: str(form, "language"),
      tavusVideo: file(form, "tavusVideo"),
    });
    return ok(assistant, { status: 201 });
  } catch (err) {
    return fail(err);
  }
}

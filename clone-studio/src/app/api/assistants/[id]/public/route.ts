import { NextResponse } from "next/server";
import { publicAssistant } from "@/lib/services/assistants";

export const dynamic = "force-dynamic";

// Read by embed.js on the customer's website, hence the open CORS header.
const CORS = { "access-control-allow-origin": "*" };

export async function GET(_req: Request, { params }: { params: Promise<{ id: string }> }) {
  const a = await publicAssistant((await params).id);
  if (!a) return NextResponse.json({ error: "Nie znaleziono asystenta." }, { status: 404, headers: CORS });
  return NextResponse.json(a, { headers: CORS });
}

import { NextResponse } from "next/server";

export function ok<T>(data: T, init?: ResponseInit) {
  return NextResponse.json(data, init);
}

export function fail(err: unknown, status = 400) {
  const message = err instanceof Error ? err.message : String(err);
  if (status >= 500) console.error(err);
  return NextResponse.json({ error: message }, { status });
}

export function str(form: FormData, key: string) {
  const v = form.get(key);
  return typeof v === "string" ? v : "";
}

export function file(form: FormData, key: string) {
  const v = form.get(key);
  return v instanceof File && v.size > 0 ? v : undefined;
}

export function files(form: FormData, key: string) {
  return form.getAll(key).filter((v): v is File => v instanceof File && v.size > 0);
}

const hits = new Map<string, number[]>();

/**
 * Small in-memory limiter for the public website-assistant endpoints, which anyone can call
 * and which spend API credits. Per instance only; put a real limiter in front for production.
 */
export function rateLimited(req: Request, bucket: string, limit: number, windowMs: number) {
  const ip = req.headers.get("x-forwarded-for")?.split(",")[0].trim() || "local";
  const key = `${bucket}:${ip}`;
  const now = Date.now();
  const recent = (hits.get(key) ?? []).filter((t) => now - t < windowMs);
  recent.push(now);
  hits.set(key, recent);
  return recent.length > limit;
}

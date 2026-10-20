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

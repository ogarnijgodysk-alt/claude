"use client";

import { useCallback, useEffect, useRef, useState } from "react";

/**
 * "browser" keeps all data in the visitor's browser (IndexedDB) and calls the server only for
 * stateless AI requests. Used for the public test deployment and the claude.ai preview.
 */
export const BROWSER_MODE = process.env.NEXT_PUBLIC_STORAGE === "browser";

type Init = RequestInit & { json?: unknown };

let backend: Promise<typeof import("@/lib/browser/backend")> | undefined;
function browserBackend() {
  return (backend ??= import("@/lib/browser/backend"));
}

export async function api<T>(url: string, init?: Init): Promise<T> {
  if (BROWSER_MODE) return (await browserBackend()).handle<T>(url, init);
  const { json, ...rest } = init ?? {};
  const res = await fetch(url, {
    ...rest,
    ...(json !== undefined ? { body: JSON.stringify(json), headers: { "content-type": "application/json" } } : {}),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error((data as { error?: string }).error ?? `Błąd ${res.status}`);
  return data as T;
}

/** POSTs JSON and returns the audio reply, or null when the server says to use the device voice. */
export async function apiAudio(url: string, json: unknown): Promise<Blob | null> {
  if (BROWSER_MODE) return null;
  const res = await fetch(url, { method: "POST", body: JSON.stringify(json), headers: { "content-type": "application/json" } });
  if (res.status === 204 || !res.ok) return null;
  return res.blob();
}

/**
 * Loads `url` and re-fetches every `intervalMs` while `shouldPoll(data)` is true.
 * The server refreshes provider job state on every GET, so polling drives progress.
 */
export function usePolling<T>(url: string | null, shouldPoll: (data: T) => boolean, intervalMs = 4000) {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  const pollRef = useRef(shouldPoll);
  pollRef.current = shouldPoll;
  const [tick, setTick] = useState(0);

  const reload = useCallback(() => setTick((t) => t + 1), []);

  useEffect(() => {
    if (!url) return;
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout>;
    const load = async () => {
      try {
        const d = await api<T>(url);
        if (cancelled) return;
        setData(d);
        setError(null);
        if (pollRef.current(d)) timer = setTimeout(load, intervalMs);
      } catch (e) {
        if (!cancelled) setError(e instanceof Error ? e.message : String(e));
      }
    };
    load();
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [url, intervalMs, tick]);

  return { data, setData, error, reload };
}

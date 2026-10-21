import type { Brand } from "./types";

/**
 * In browser-storage mode the brand profile lives in the visitor's browser and arrives with the request.
 * Only known string fields are taken, each capped, so the payload can't grow the prompt without bound.
 */
export function brandFromBody(raw: unknown, fallback: Brand): Brand {
  if (!raw || typeof raw !== "object") return fallback;
  const r = raw as Record<string, unknown>;
  const s = (k: keyof Brand) => (typeof r[k] === "string" ? (r[k] as string).slice(0, 4000) : (fallback[k] as string));
  return {
    ...fallback,
    name: s("name"),
    industry: s("industry"),
    description: s("description"),
    audience: s("audience"),
    tone: s("tone"),
    language: s("language"),
    ctas: s("ctas"),
    bannedWords: s("bannedWords"),
  };
}

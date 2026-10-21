import { assistantKnowledge, assistantSystemPrompt, demoAnswer } from "../assistant-prompt";
import {
  JSON_SHAPES,
  demoIdeas,
  demoPersona,
  demoScript,
  ideasTask,
  personaTask,
  scriptTask,
  writerSystem,
  type Ideas,
  type Persona,
  type Script,
  type ScriptInput,
} from "../content";
import type { Assistant, Brand, ChatMessage, Platform } from "../types";

// AI for browser-storage mode, in order of preference:
// 1. Claude through the claude.ai viewer (`sample` capability) when the page runs as a claude.ai artifact,
// 2. the app server's stateless /api/ai/* endpoints (a deployment with ANTHROPIC_API_KEY),
// 3. built-in demo content.

const SERVER_AI = process.env.NEXT_PUBLIC_SERVER_AI !== "0";

interface SampleFn {
  (input: string | ChatMessage[], opts?: Record<string, unknown>): Promise<{ text: string }>;
  json(input: string | ChatMessage[], opts?: Record<string, unknown>): Promise<unknown>;
}

let samplePromise: Promise<SampleFn | null> | undefined;
export function claudeSample() {
  return (samplePromise ??= (async () => {
    const claude = (window as unknown as { claude?: { use?: (n: string) => Promise<unknown> } }).claude;
    if (!claude?.use) return null;
    try {
      return ((await claude.use("sample")) as SampleFn | null) ?? null;
    } catch {
      return null;
    }
  })());
}

/** Whether real AI answers are available (shown on the integrations page). */
export async function aiAvailable() {
  if (await claudeSample()) return true;
  if (!SERVER_AI) return false;
  try {
    const s = (await (await fetch("/api/status")).json()) as { claude?: boolean };
    return Boolean(s.claude);
  } catch {
    return false;
  }
}

async function server<T>(path: string, body: unknown): Promise<T | null> {
  if (!SERVER_AI) return null;
  try {
    const res = await fetch(path, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(body) });
    const data = await res.json();
    if (!res.ok) throw new Error((data as { error?: string }).error ?? `Błąd ${res.status}`);
    return data as T;
  } catch (err) {
    // A 429 or a refusal should reach the user; a missing server means "no server AI here".
    if (err instanceof Error && !/Failed to fetch|NetworkError|Unexpected token|JSON/i.test(err.message)) throw err;
    return null;
  }
}

async function sampleJson<T>(system: string, task: string, shape: string): Promise<T | null> {
  const sample = await claudeSample();
  if (!sample) return null;
  try {
    return (await sample.json(`${system}\n\n${task}\n\nOdpowiedz wyłącznie poprawnym JSON w kształcie:\n${shape}`)) as T;
  } catch (e) {
    const code = (e as { code?: string }).code;
    if (code === "not_granted" || code === "unavailable") return null;
    throw new Error((e as { message?: string }).message ?? "Claude nie odpowiedział.");
  }
}

export async function aiScript(brand: Brand, input: ScriptInput): Promise<Script> {
  const s = await sampleJson<Script>(writerSystem(brand), scriptTask(brand, input), JSON_SHAPES.script);
  if (s?.script) return { ...s, hashtags: s.hashtags ?? [], onScreenText: s.onScreenText ?? [] };
  return (await server<Script>("/api/ai/script", { ...input, brand })) ?? demoScript(brand, input);
}

export async function aiIdeas(brand: Brand, count: number, platforms: Platform[], focus: string): Promise<Ideas> {
  const s = await sampleJson<Ideas>(writerSystem(brand), ideasTask(brand, count, platforms, focus), JSON_SHAPES.ideas);
  if (s?.ideas?.length) return s;
  return (await server<Ideas>("/api/ai/ideas", { count, platforms, focus, brand })) ?? demoIdeas(count, platforms);
}

export async function aiPersona(brand: Brand, description: string): Promise<Persona> {
  const s = await sampleJson<Persona>(writerSystem(brand), personaTask(description), JSON_SHAPES.persona);
  if (s?.name) return s;
  return (await server<Persona>("/api/ai/persona", { description, brand })) ?? demoPersona(description);
}

export async function aiChat(brand: Brand, assistant: Assistant, cloneName: string, cloneDescription: string, messages: ChatMessage[]) {
  const sample = await claudeSample();
  if (sample) {
    const rules = `${assistantSystemPrompt(brand, assistant, cloneName, cloneDescription)}\n\nWiedza o firmie:\n${assistantKnowledge(brand, assistant)}\n\nOdpowiedz tylko jako asystent, jedną krótką wypowiedzią.`;
    try {
      const { text } = await sample([{ role: "user", content: rules }, ...messages.slice(-20)], { modelTier: "quick", cache: false });
      return text.trim();
    } catch (e) {
      const code = (e as { code?: string }).code;
      if (code !== "not_granted" && code !== "unavailable") throw new Error((e as { message?: string }).message ?? "Claude nie odpowiedział.");
    }
  }
  const res = await server<{ reply: string }>("/api/ai/chat", { brand, assistant, cloneName, cloneDescription, messages });
  return res?.reply ?? demoAnswer(assistant, brand, messages);
}

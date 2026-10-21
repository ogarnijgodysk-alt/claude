import { createHash } from "crypto";
import { assistantKnowledge, assistantSystemPrompt, demoAnswer } from "../assistant-prompt";
import { newId, readDb, updateDb } from "../db";
import { publicFileUrl, saveUpload } from "../files";
import { chatReply, hasClaude } from "../providers/claude";
import { hasElevenLabs, speak } from "../providers/elevenlabs";
import { createConversation, createPersona, createReplica, getReplicaStatus, hasTavus } from "../providers/tavus";
import type { Assistant, ChatMessage, Database } from "../types";

export interface AssistantInput {
  cloneId: string;
  name: string;
  greeting: string;
  knowledge: string;
  handoff: string;
  language: string;
  /** Footage for a personal Tavus replica; it must open with the spoken Tavus consent statement. */
  tavusVideo?: File;
}

function clip(s: string, n: number) {
  return s.trim().slice(0, n);
}

export async function createAssistant(input: AssistantInput): Promise<Assistant> {
  const clone = (await readDb()).clones.find((c) => c.id === input.cloneId);
  if (!clone) throw new Error("Wybierz klona, który będzie rozmawiał na stronie.");
  const assistant: Assistant = {
    id: newId("as"),
    cloneId: clone.id,
    name: clip(input.name, 120) || clone.name,
    greeting: clip(input.greeting, 500) || `Cześć! Jestem ${clone.name}. W czym mogę pomóc?`,
    knowledge: clip(input.knowledge, 20000),
    handoff: clip(input.handoff, 500),
    language: clip(input.language, 40) || "polski",
    createdAt: new Date().toISOString(),
  };

  if (hasTavus()) {
    try {
      if (input.tavusVideo) {
        const file = await saveUpload(input.tavusVideo, "tavus");
        assistant.tavus = { replicaId: await createReplica(assistant.name, await publicFileUrl(file)), replicaStatus: "processing" };
      } else if (process.env.TAVUS_DEFAULT_REPLICA_ID) {
        assistant.tavus = { replicaId: process.env.TAVUS_DEFAULT_REPLICA_ID, replicaStatus: "ready" };
      }
    } catch (err) {
      assistant.error = err instanceof Error ? err.message : String(err);
    }
  }

  await updateDb((db) => {
    db.assistants.unshift(assistant);
  });
  return assistant;
}

export async function updateAssistant(id: string, patch: Partial<Pick<Assistant, "name" | "greeting" | "knowledge" | "handoff" | "language">>) {
  return updateDb((db) => {
    const a = db.assistants.find((x) => x.id === id);
    if (!a) throw new Error("Nie znaleziono asystenta.");
    if (patch.name !== undefined) a.name = clip(patch.name, 120) || a.name;
    if (patch.greeting !== undefined) a.greeting = clip(patch.greeting, 500);
    if (patch.knowledge !== undefined) a.knowledge = clip(patch.knowledge, 20000);
    if (patch.handoff !== undefined) a.handoff = clip(patch.handoff, 500);
    if (patch.language !== undefined) a.language = clip(patch.language, 40);
    return a;
  });
}

export async function deleteAssistant(id: string) {
  await updateDb((db) => {
    db.assistants = db.assistants.filter((a) => a.id !== id);
  });
}

/** Personal replicas train for hours; each GET checks whether training finished. */
export async function refreshAssistant(id: string) {
  const a = (await readDb()).assistants.find((x) => x.id === id);
  if (!a?.tavus?.replicaId || a.tavus.replicaStatus !== "processing" || !hasTavus()) return a;
  try {
    const status = await getReplicaStatus(a.tavus.replicaId);
    if (status === "processing") return a;
    return updateDb((db) => {
      const x = db.assistants.find((y) => y.id === id)!;
      x.tavus = { ...x.tavus, replicaStatus: status };
      if (status === "failed") x.error = "Trening repliki w Tavus nie powiódł się. Sprawdź jakość nagrania i oświadczenie zgody.";
      return x;
    });
  } catch (err) {
    console.error(`refreshAssistant ${id}:`, err);
    return a;
  }
}

function context(db: Database, a: Assistant) {
  const clone = db.clones.find((c) => c.id === a.cloneId);
  return {
    clone,
    system: assistantSystemPrompt(db.brand, a, clone?.name ?? a.name, clone?.description ?? ""),
    knowledge: assistantKnowledge(db.brand, a),
  };
}

/** What the website widget shows before a conversation starts. Safe to expose publicly. */
export async function publicAssistant(id: string) {
  const db = await readDb();
  const a = db.assistants.find((x) => x.id === id);
  if (!a) return undefined;
  const clone = db.clones.find((c) => c.id === a.cloneId);
  return {
    id: a.id,
    name: a.name,
    greeting: a.greeting,
    company: db.brand.name,
    cover: clone?.coverFile ? `/api/files/${clone.coverFile}` : undefined,
    video: Boolean(hasTavus() && a.tavus?.replicaStatus === "ready"),
    voice: Boolean(clone?.voice.provider === "elevenlabs" && clone.voice.voiceId && hasElevenLabs()),
  };
}

/**
 * Starts a live video call in Tavus when a replica is ready; otherwise the widget
 * falls back to the built-in voice chat (speech recognition + Claude + speech).
 */
export async function startConversation(id: string): Promise<{ mode: "tavus"; url: string; id: string } | { mode: "voice" }> {
  const db = await readDb();
  const a = db.assistants.find((x) => x.id === id);
  if (!a) throw new Error("Nie znaleziono asystenta.");
  if (!hasTavus() || a.tavus?.replicaStatus !== "ready" || !a.tavus.replicaId) return { mode: "voice" };

  const { system, knowledge } = context(db, a);
  const hash = createHash("sha256").update(`${system}\n${knowledge}\n${a.tavus.replicaId}`).digest("hex").slice(0, 16);
  let personaId = a.tavus.personaId;
  if (!personaId || a.tavus.personaHash !== hash) {
    personaId = await createPersona({ name: a.name, systemPrompt: system, context: knowledge, replicaId: a.tavus.replicaId });
    await updateDb((d) => {
      const x = d.assistants.find((y) => y.id === id);
      if (x) x.tavus = { ...x.tavus, personaId, personaHash: hash };
    });
  }
  const conv = await createConversation({
    personaId,
    replicaId: a.tavus.replicaId,
    name: `${a.name} — strona www`,
    greeting: a.greeting,
  });
  return { mode: "tavus", ...conv };
}

export async function replyTo(id: string, history: ChatMessage[]) {
  const db = await readDb();
  const a = db.assistants.find((x) => x.id === id);
  if (!a) throw new Error("Nie znaleziono asystenta.");
  const cleaned = history
    .filter((m) => (m.role === "user" || m.role === "assistant") && typeof m.content === "string" && m.content.trim())
    .map((m) => ({ role: m.role, content: m.content.slice(0, 2000) }))
    .slice(-20);
  while (cleaned[0]?.role === "assistant") cleaned.shift();
  if (!cleaned.length) throw new Error("Brak pytania.");
  if (!hasClaude()) return demoAnswer(a, db.brand, cleaned);
  const { system, knowledge } = context(db, a);
  return chatReply(system, knowledge, cleaned);
}

/** MP3 of the reply in the clone's own (ElevenLabs) voice, or undefined to let the browser speak. */
export async function speechFor(id: string, text: string) {
  const db = await readDb();
  const a = db.assistants.find((x) => x.id === id);
  const clone = a && db.clones.find((c) => c.id === a.cloneId);
  if (!clone || clone.voice.provider !== "elevenlabs" || !clone.voice.voiceId || !hasElevenLabs()) return undefined;
  return speak(clone.voice.voiceId, text.slice(0, 1500));
}

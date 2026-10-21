// Tavus Conversational Video Interface: a real-time video call with the clone, embeddable on a website.
// Tavus renamed replica -> Face and persona -> PAL; the legacy endpoints and field names below stay supported.
// Docs: https://docs.tavus.io/api-reference/overview

const BASE = "https://tavusapi.com/v2";

export function hasTavus() {
  return Boolean(process.env.TAVUS_API_KEY);
}

async function call<T = Record<string, unknown>>(method: "GET" | "POST" | "PATCH" | "DELETE", path: string, body?: unknown): Promise<T> {
  const res = await fetch(`${BASE}${path}`, {
    method,
    headers: { "x-api-key": process.env.TAVUS_API_KEY ?? "", "content-type": "application/json" },
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  let json: Record<string, unknown> = {};
  try {
    json = text ? JSON.parse(text) : {};
  } catch {
    // raw text used below
  }
  if (!res.ok) throw new Error(`Tavus ${res.status}: ${(json.message ?? json.error ?? text.slice(0, 300)) as string}`);
  return json as T;
}

/** Trains a personal replica from footage that starts with the spoken consent statement. Takes hours. */
export async function createReplica(name: string, trainVideoUrl: string) {
  const data = await call<{ replica_id?: string }>("POST", "/replicas", { replica_name: name, train_video_url: trainVideoUrl });
  if (!data.replica_id) throw new Error("Tavus nie zwrócił ID repliki");
  return data.replica_id;
}

export async function getReplicaStatus(replicaId: string): Promise<"processing" | "ready" | "failed"> {
  const data = await call<{ status?: string }>("GET", `/replicas/${replicaId}`);
  const s = String(data.status ?? "").toLowerCase();
  if (s === "completed" || s === "ready") return "ready";
  if (s === "error" || s === "failed") return "failed";
  return "processing";
}

export async function createPersona(p: { name: string; systemPrompt: string; context: string; replicaId: string }) {
  const data = await call<{ persona_id?: string }>("POST", "/personas", {
    persona_name: p.name,
    system_prompt: p.systemPrompt,
    context: p.context,
    default_replica_id: p.replicaId,
  });
  if (!data.persona_id) throw new Error("Tavus nie zwrócił ID persony");
  return data.persona_id;
}

export async function createConversation(p: { personaId: string; replicaId: string; name: string; greeting: string; context?: string }) {
  const data = await call<{ conversation_id?: string; conversation_url?: string }>("POST", "/conversations", {
    persona_id: p.personaId,
    replica_id: p.replicaId,
    conversation_name: p.name,
    custom_greeting: p.greeting,
    conversational_context: p.context,
    properties: { max_call_duration: 600, participant_left_timeout: 30, participant_absent_timeout: 120 },
  });
  if (!data.conversation_url || !data.conversation_id) throw new Error("Tavus nie zwrócił linku do rozmowy");
  return { id: data.conversation_id, url: data.conversation_url };
}

export async function endConversation(conversationId: string) {
  await call("POST", `/conversations/${conversationId}/end`);
}

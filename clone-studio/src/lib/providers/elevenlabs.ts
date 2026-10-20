import { readStored } from "../files";

// ElevenLabs: instant voice clone from a short recording, then text-to-speech in that voice.
const BASE = "https://api.elevenlabs.io/v1";

export function hasElevenLabs() {
  return Boolean(process.env.ELEVENLABS_API_KEY);
}

function headers(extra: Record<string, string> = {}) {
  return { "xi-api-key": process.env.ELEVENLABS_API_KEY ?? "", ...extra };
}

async function fail(res: Response): Promise<never> {
  const text = await res.text();
  let msg = text.slice(0, 300);
  try {
    const detail = JSON.parse(text).detail;
    msg = typeof detail === "string" ? detail : detail?.message ?? msg;
  } catch {
    // keep raw text
  }
  throw new Error(`ElevenLabs ${res.status}: ${msg}`);
}

export async function cloneVoice(name: string, sampleFile: string, description?: string) {
  const { data, type } = await readStored(sampleFile);
  const form = new FormData();
  form.append("name", name);
  if (description) form.append("description", description);
  form.append("files", new Blob([new Uint8Array(data)], { type }), sampleFile);
  const res = await fetch(`${BASE}/voices/add`, { method: "POST", headers: headers(), body: form });
  if (!res.ok) await fail(res);
  const json = (await res.json()) as { voice_id: string };
  return json.voice_id;
}

/** Returns MP3 audio of `text` spoken in the given voice. */
export async function speak(voiceId: string, text: string) {
  const res = await fetch(`${BASE}/text-to-speech/${voiceId}?output_format=mp3_44100_128`, {
    method: "POST",
    headers: headers({ "content-type": "application/json", accept: "audio/mpeg" }),
    body: JSON.stringify({ text, model_id: "eleven_multilingual_v2" }),
  });
  if (!res.ok) await fail(res);
  return Buffer.from(await res.arrayBuffer());
}

import { newId, readDb, updateDb } from "../db";
import { fileUrl, saveBuffer, saveUpload } from "../files";
import { demoJobDone } from "../providers/demo";
import { hasElevenLabs, speak } from "../providers/elevenlabs";
import { createVideo, getVideo, hasHeygen } from "../providers/heygen";
import type { Brand, Clone, Platform, Video } from "../types";
import { PLATFORMS } from "../types";

export interface CreateVideoInput {
  cloneId: string;
  title: string;
  platform: Platform;
  mode: "script" | "voice";
  script: string;
  /** Recorded in the browser: the clone lip-syncs to the user's own voice. */
  audio?: File;
  caption: string;
  hashtags: string[];
}

export const AI_DISCLOSURE = "Treść wygenerowana z użyciem AI.";

export function finalCaption(brand: Brand, caption: string) {
  if (!brand.aiDisclosure || caption.includes(AI_DISCLOSURE)) return caption;
  return `${caption.trim()}\n\n${AI_DISCLOSURE}`.trim();
}

async function heygenSpeech(clone: Clone, input: CreateVideoInput, audioFile?: string) {
  if (audioFile) return { audioFile };
  if (clone.voice.provider === "elevenlabs" && clone.voice.voiceId && hasElevenLabs()) {
    const mp3 = await speak(clone.voice.voiceId, input.script);
    return { audioFile: await saveBuffer(mp3, "audio/mpeg", "tts") };
  }
  const voiceId =
    (clone.voice.provider === "heygen" ? clone.voice.voiceId : undefined) || process.env.HEYGEN_DEFAULT_VOICE_ID;
  if (!voiceId) {
    throw new Error(
      "Klon nie ma jeszcze głosu. Dodaj próbkę głosu (ElevenLabs), ID głosu HeyGen w ustawieniach klona albo HEYGEN_DEFAULT_VOICE_ID.",
    );
  }
  return { script: input.script, voiceId };
}

export async function createCloneVideo(input: CreateVideoInput): Promise<Video> {
  const db = await readDb();
  const clone = db.clones.find((c) => c.id === input.cloneId);
  if (!clone) throw new Error("Nie znaleziono klona.");
  if (clone.status !== "ready") throw new Error("Klon jeszcze się trenuje — poczekaj, aż będzie gotowy.");
  if (input.mode === "script" && !input.script.trim()) throw new Error("Wpisz lub wygeneruj skrypt.");
  if (input.mode === "voice" && !input.audio) throw new Error("Nagraj swój głos.");

  const video: Video = {
    id: newId("vid"),
    cloneId: clone.id,
    title: input.title.trim() || "Nowe wideo",
    platform: input.platform,
    aspectRatio: PLATFORMS[input.platform].aspect,
    mode: input.mode,
    script: input.script.trim(),
    caption: finalCaption(db.brand, input.caption),
    hashtags: input.hashtags.map((h) => h.replace(/^#/, "").trim()).filter(Boolean),
    status: "processing",
    demo: clone.demo || !hasHeygen() || !clone.heygen?.avatarId,
    createdAt: new Date().toISOString(),
  };
  if (input.audio) video.audioFile = await saveUpload(input.audio, "rec");

  if (!video.demo) {
    try {
      const speech = await heygenSpeech(clone, input, video.audioFile);
      if (speech.audioFile) video.audioFile = speech.audioFile;
      video.heygenVideoId = await createVideo({
        avatarId: clone.heygen!.avatarId!,
        title: video.title,
        aspectRatio: video.aspectRatio,
        ...speech,
      });
    } catch (err) {
      video.status = "failed";
      video.error = err instanceof Error ? err.message : String(err);
    }
  }

  await updateDb((d) => {
    d.videos.unshift(video);
  });
  return video;
}

/** Signed provider URLs expire, so finished renders are copied into our own storage. */
async function archive(url: string) {
  const res = await fetch(url);
  if (!res.ok) return url;
  const name = await saveBuffer(Buffer.from(await res.arrayBuffer()), res.headers.get("content-type") ?? "video/mp4", "video");
  return fileUrl(name);
}

export async function refreshVideo(id: string): Promise<Video | undefined> {
  const current = (await readDb()).videos.find((v) => v.id === id);
  if (!current || current.status !== "processing") return current;
  const patch: Partial<Video> = {};

  if (current.demo || !current.heygenVideoId) {
    if (demoJobDone(current.createdAt)) patch.status = "ready";
  } else {
    try {
      const v = await getVideo(current.heygenVideoId);
      if (v.state === "ready" && v.videoUrl) {
        patch.status = "ready";
        patch.videoUrl = await archive(v.videoUrl);
        patch.thumbnailUrl = v.thumbnailUrl;
      } else if (v.state === "failed") {
        patch.status = "failed";
        patch.error = v.error ?? "Renderowanie w HeyGen nie powiodło się.";
      }
    } catch (err) {
      console.error(`refreshVideo ${id}:`, err);
    }
  }

  if (Object.keys(patch).length === 0) return current;
  return updateDb((db) => {
    const v = db.videos.find((x) => x.id === id);
    if (v) Object.assign(v, patch);
    return v;
  });
}

export async function deleteVideo(id: string) {
  await updateDb((db) => {
    db.videos = db.videos.filter((v) => v.id !== id);
  });
}

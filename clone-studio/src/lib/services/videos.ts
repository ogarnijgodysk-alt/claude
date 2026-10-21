import { newId, readDb, updateDb } from "../db";
import { archiveRemote, fileUrl, publicFileUrl, saveBuffer, saveUpload } from "../files";
import { demoJobDone } from "../providers/demo";
import { hasElevenLabs, speak } from "../providers/elevenlabs";
import { createVideo, getVideo, hasHeygen } from "../providers/heygen";
import { getTask, hasKie, startAvatarVideo } from "../providers/kie";
import { finalCaption } from "../captions";
import type { Clone, Platform, Video } from "../types";
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


function canSpeakWithElevenLabs(clone: Clone) {
  return clone.voice.provider === "elevenlabs" && Boolean(clone.voice.voiceId) && hasElevenLabs();
}

async function elevenLabsAudio(clone: Clone, script: string) {
  return saveBuffer(await speak(clone.voice.voiceId!, script), "audio/mpeg", "tts");
}

/**
 * Twins always render in HeyGen. Photo-based clones prefer kie.ai's Kling Avatar (cheaper per second),
 * which needs an audio track: the user's recording or ElevenLabs speech in the clone's voice.
 */
function pickProvider(clone: Clone, input: CreateVideoInput): Video["provider"] | undefined {
  const heygenReady = hasHeygen() && Boolean(clone.heygen?.avatarId);
  if (clone.kind === "digital_twin") return heygenReady ? "heygen" : undefined;
  const kieCanSpeak = input.mode === "voice" || canSpeakWithElevenLabs(clone);
  if (hasKie() && (kieCanSpeak || !heygenReady)) return "kie";
  return heygenReady ? "heygen" : undefined;
}

async function startKie(clone: Clone, input: CreateVideoInput, audioFile?: string) {
  if (!audioFile) {
    if (!canSpeakWithElevenLabs(clone)) {
      throw new Error("Do renderu w kie.ai klon potrzebuje głosu ElevenLabs (próbka głosu) — albo użyj trybu „Mój głos”.");
    }
    audioFile = await elevenLabsAudio(clone, input.script);
  }
  const [imageUrl, audioUrl] = await Promise.all([publicFileUrl(clone.coverFile!), publicFileUrl(audioFile)]);
  return { audioFile, jobId: await startAvatarVideo(imageUrl, audioUrl) };
}

async function heygenSpeech(clone: Clone, input: CreateVideoInput, audioFile?: string) {
  if (audioFile) return { audioFile };
  if (canSpeakWithElevenLabs(clone)) return { audioFile: await elevenLabsAudio(clone, input.script) };
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
    provider: clone.demo ? undefined : pickProvider(clone, input),
    demo: true,
    createdAt: new Date().toISOString(),
  };
  video.demo = !video.provider;
  if (input.audio) video.audioFile = await saveUpload(input.audio, "rec");

  if (!video.demo) {
    try {
      if (video.provider === "kie") {
        Object.assign(video, await startKie(clone, input, video.audioFile));
      } else {
        const speech = await heygenSpeech(clone, input, video.audioFile);
        if (speech.audioFile) video.audioFile = speech.audioFile;
        video.jobId = await createVideo({
          avatarId: clone.heygen!.avatarId!,
          title: video.title,
          aspectRatio: video.aspectRatio,
          ...speech,
        });
      }
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

export async function refreshVideo(id: string): Promise<Video | undefined> {
  const current = (await readDb()).videos.find((v) => v.id === id);
  if (!current || current.status !== "processing") return current;
  const patch: Partial<Video> = {};

  if (current.demo || !current.jobId) {
    if (demoJobDone(current.createdAt)) patch.status = "ready";
  } else {
    try {
      // Signed provider URLs expire, so finished renders are copied into our own storage.
      if (current.provider === "kie") {
        const job = await getTask(current.jobId);
        if (job.status === "ready") {
          patch.status = "ready";
          patch.videoUrl = fileUrl(await archiveRemote(job.urls[0], "video/mp4", "video"));
        } else if (job.status === "failed") {
          patch.status = "failed";
          patch.error = job.error;
        }
      } else {
        const v = await getVideo(current.jobId);
        if (v.state === "ready" && v.videoUrl) {
          patch.status = "ready";
          patch.videoUrl = fileUrl(await archiveRemote(v.videoUrl, "video/mp4", "video"));
          patch.thumbnailUrl = v.thumbnailUrl;
        } else if (v.state === "failed") {
          patch.status = "failed";
          patch.error = v.error ?? "Renderowanie w HeyGen nie powiodło się.";
        }
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

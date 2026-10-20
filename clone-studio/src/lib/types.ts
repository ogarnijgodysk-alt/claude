export type Platform = "tiktok" | "reels" | "shorts" | "linkedin" | "youtube" | "facebook";
export type AspectRatio = "9:16" | "1:1" | "16:9";

export const PLATFORMS: Record<Platform, { label: string; aspect: AspectRatio }> = {
  tiktok: { label: "TikTok", aspect: "9:16" },
  reels: { label: "Instagram Reels", aspect: "9:16" },
  shorts: { label: "YouTube Shorts", aspect: "9:16" },
  facebook: { label: "Facebook", aspect: "1:1" },
  linkedin: { label: "LinkedIn", aspect: "1:1" },
  youtube: { label: "YouTube", aspect: "16:9" },
};

export interface Brand {
  name: string;
  industry: string;
  description: string;
  audience: string;
  tone: string;
  language: string;
  ctas: string;
  bannedWords: string;
  /** Append an "AI-generated" disclosure to captions (EU AI Act, art. 50). */
  aiDisclosure: boolean;
  updatedAt: string;
}

/**
 * - digital_twin: HeyGen-style twin trained on a recorded video of a real person
 * - photo_clone: mysnapface-style clone trained on selfies (LoRA) + talking photo avatar
 * - ai_influencer: a fictional persona designed from a text prompt
 */
export type CloneKind = "digital_twin" | "photo_clone" | "ai_influencer";
export type JobStatus = "draft" | "processing" | "ready" | "failed";

export interface CloneVoice {
  provider: "heygen" | "elevenlabs" | "none";
  voiceId?: string;
  sampleFile?: string;
}

export interface Clone {
  id: string;
  kind: CloneKind;
  name: string;
  description: string;
  status: JobStatus;
  /** Separate track for the photo-shoot model so a twin can be "ready" while LoRA still trains. */
  loraStatus?: JobStatus;
  coverFile?: string;
  trainingVideoFile?: string;
  consentVideoFile?: string;
  photoFiles: string[];
  voice: CloneVoice;
  consent: { confirmed: boolean; subjectName: string; at: string };
  heygen?: {
    avatarId?: string;
    groupId?: string;
    consentUrl?: string;
    consentStatus?: string;
  };
  fal?: {
    trainingRequestId?: string;
    loraUrl?: string;
    triggerWord?: string;
  };
  demo: boolean;
  error?: string;
  createdAt: string;
}

export interface Video {
  id: string;
  cloneId: string;
  title: string;
  platform: Platform;
  aspectRatio: AspectRatio;
  mode: "script" | "voice";
  script: string;
  audioFile?: string;
  caption: string;
  hashtags: string[];
  status: JobStatus;
  heygenVideoId?: string;
  videoUrl?: string;
  thumbnailUrl?: string;
  demo: boolean;
  error?: string;
  createdAt: string;
}

export interface PhotoSet {
  id: string;
  cloneId: string;
  prompt: string;
  scene: string;
  aspect: AspectRatio;
  status: JobStatus;
  falJobs: { model: "lora" | "pulid"; requestId: string; done?: boolean }[];
  images: string[];
  demo: boolean;
  error?: string;
  createdAt: string;
}

export interface Database {
  brand: Brand;
  clones: Clone[];
  videos: Video[];
  photos: PhotoSet[];
}

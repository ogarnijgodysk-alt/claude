"use client";

// Browser speech APIs: recognition (Chrome, Edge, Safari incl. iOS 14.5+) and synthesis (all modern browsers).

export interface Recognition {
  lang: string;
  continuous: boolean;
  interimResults: boolean;
  onresult: ((e: { resultIndex: number; results: ArrayLike<{ isFinal: boolean; 0: { transcript: string } }> }) => void) | null;
  onerror: ((e: { error: string }) => void) | null;
  onend: (() => void) | null;
  start(): void;
  stop(): void;
  abort(): void;
}

export function recognitionCtor(): (new () => Recognition) | null {
  if (typeof window === "undefined") return null;
  const w = window as unknown as { SpeechRecognition?: new () => Recognition; webkitSpeechRecognition?: new () => Recognition };
  return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null;
}

const LANGS: Record<string, string> = {
  polski: "pl-PL",
  angielski: "en-US",
  english: "en-US",
  niemiecki: "de-DE",
  hiszpański: "es-ES",
  francuski: "fr-FR",
  ukraiński: "uk-UA",
  czeski: "cs-CZ",
};

export function langCode(language?: string) {
  const l = (language ?? "").trim().toLowerCase();
  if (/^[a-z]{2}-[A-Z]{2}$/i.test(language ?? "")) return language!;
  return LANGS[l] ?? "pl-PL";
}

function pickVoice(lang: string) {
  const voices = window.speechSynthesis?.getVoices() ?? [];
  const prefix = lang.slice(0, 2);
  return voices.find((v) => v.lang === lang && /natural|neural|premium|enhanced/i.test(v.name)) ?? voices.find((v) => v.lang === lang) ?? voices.find((v) => v.lang.startsWith(prefix));
}

let currentAudio: HTMLAudioElement | null = null;

/** Speaks `text`: an MP3 in the clone's voice when given, otherwise the device's own voice. Resolves when done. */
export function speak(text: string, lang: string, mp3?: Blob | null): Promise<void> {
  stopSpeaking();
  if (mp3) {
    return new Promise((resolve) => {
      const audio = new Audio(URL.createObjectURL(mp3));
      currentAudio = audio;
      audio.onended = audio.onerror = () => resolve();
      audio.play().catch(() => resolve());
    });
  }
  const synth = typeof window !== "undefined" ? window.speechSynthesis : undefined;
  if (!synth) return Promise.resolve();
  return new Promise((resolve) => {
    const u = new SpeechSynthesisUtterance(text);
    u.lang = lang;
    const v = pickVoice(lang);
    if (v) u.voice = v;
    u.rate = 1.03;
    u.onend = u.onerror = () => resolve();
    synth.speak(u);
    // Some mobile browsers never fire onend; don't leave the conversation hanging.
    setTimeout(resolve, 2000 + text.length * 90);
  });
}

export function stopSpeaking() {
  currentAudio?.pause();
  currentAudio = null;
  if (typeof window !== "undefined") window.speechSynthesis?.cancel();
}

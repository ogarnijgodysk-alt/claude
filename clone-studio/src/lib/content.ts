import type { Brand, Platform } from "./types";
import { PLATFORMS } from "./types";

// Prompts and demo content for scripts, content plans and personas.
// Pure (no SDK, no Node APIs) so the server and the in-browser demo share them.

export interface Script {
  title: string;
  hook: string;
  script: string;
  caption: string;
  hashtags: string[];
  onScreenText: string[];
}

export interface Idea {
  title: string;
  hook: string;
  format: string;
  platform: Platform;
  goal: string;
  outline: string;
}

export interface Ideas {
  ideas: Idea[];
}

export interface Persona {
  name: string;
  bio: string;
  imagePrompt: string;
  voiceDescription: string;
}

export interface ScriptInput {
  topic: string;
  platform: Platform;
  goal: string;
  seconds: number;
  persona?: string;
  notes?: string;
}

export function brandContext(brand: Brand) {
  const line = (label: string, value: string) => (value?.trim() ? `${label}: ${value.trim()}` : "");
  return [
    line("Firma", brand.name),
    line("Branża", brand.industry),
    line("Opis i oferta", brand.description),
    line("Grupa docelowa", brand.audience),
    line("Ton komunikacji", brand.tone),
    line("Typowe wezwania do działania", brand.ctas),
    line("Słowa i tematy zakazane", brand.bannedWords),
  ]
    .filter(Boolean)
    .join("\n");
}

export const WRITER_SYSTEM = `Jesteś starszym strategiem social media i scenarzystą krótkich form wideo dla firm.
Piszesz teksty, które wypowiada awatar AI (klon osoby lub wirtualny influencer) prosto do kamery.
Zasady:
- Tekst mówiony ma brzmieć naturalnie na głos: krótkie zdania, bez list wypunktowanych, bez emoji, bez didaskaliów i nawiasów.
- Pierwsze zdanie to mocny hook, który zatrzymuje scrollowanie.
- Trzymaj się faktów z profilu marki; nie wymyślaj cen, wyników ani obietnic, których tam nie ma.
- Nigdy nie używaj słów i tematów oznaczonych jako zakazane.
- Pisz w języku wskazanym w zadaniu.`;

export function writerSystem(brand: Brand) {
  return `${WRITER_SYSTEM}\n\nProfil marki:\n${brandContext(brand) || "(nie uzupełniono — pisz uniwersalnie)"}`;
}

export function scriptTask(brand: Brand, input: ScriptInput) {
  const words = Math.round(input.seconds * 2.4);
  return `Napisz skrypt wideo.
Platforma: ${PLATFORMS[input.platform].label}
Cel: ${input.goal}
Temat: ${input.topic}
Długość: ok. ${input.seconds} s (ok. ${words} słów tekstu mówionego)
Język: ${brand.language || "polski"}
${input.persona ? `Kto mówi (persona awatara): ${input.persona}` : ""}
${input.notes ? `Dodatkowe wskazówki: ${input.notes}` : ""}`;
}

export function ideasTask(brand: Brand, count: number, platforms: Platform[], focus: string) {
  return `Przygotuj plan ${count} pomysłów na krótkie filmy z awatarem AI.
Platformy do wykorzystania: ${platforms.map((p) => PLATFORMS[p].label).join(", ")}
${focus ? `Na czym się skupić: ${focus}` : ""}
Zróżnicuj formaty i cele (zasięg, zaufanie, sprzedaż). Język: ${brand.language || "polski"}.`;
}

export function personaTask(description: string) {
  return `Zaprojektuj wirtualnego influencera (fikcyjną osobę) dla tej marki na podstawie opisu klienta:
"""${description}"""
Postać nie może przypominać żadnej istniejącej, rozpoznawalnej osoby.`;
}

/** JSON shapes spelled out for model calls that have no schema enforcement (the in-browser demo). */
export const JSON_SHAPES = {
  script: `{"title": string, "hook": string, "script": string (pełny tekst mówiony), "caption": string, "hashtags": string[] (bez #), "onScreenText": string[] (maks. 6 słów każdy)}`,
  ideas: `{"ideas": [{"title": string, "hook": string, "format": string, "platform": "tiktok"|"reels"|"shorts"|"linkedin"|"youtube"|"facebook", "goal": string, "outline": string (2–3 zdania)}]}`,
  persona: `{"name": string, "bio": string (2–3 zdania), "imagePrompt": string (po angielsku, fotorealistyczny portret), "voiceDescription": string}`,
};

// ---------- Demo content (no AI configured) ----------

export function demoScriptText(topic: string, brandName: string) {
  const intro = brandName ? `Cześć, tu ${brandName}.` : "Cześć!";
  return `Temat na dziś: ${topic}. Większość ludzi popełnia tu ten sam błąd. ${intro} W 30 sekund pokażę Ci, jak zrobić to lepiej. Po pierwsze: zacznij od konkretu, nie od teorii. Po drugie: mierz efekty co tydzień. Po trzecie: nie rób wszystkiego sam — od tego jesteśmy my. Zapisz ten film i napisz w komentarzu, który punkt był dla Ciebie nowy.`;
}

export function demoScript(brand: Brand, input: ScriptInput): Script {
  return {
    title: input.topic,
    hook: `Temat na dziś: ${input.topic}.`,
    script: demoScriptText(input.topic, brand.name),
    caption: `${input.topic} — 3 rzeczy, które warto wiedzieć. (Tryb demo: po podłączeniu Claude skrypty pisze AI.)`,
    hashtags: ["marketing", "wskazowki", "biznes"],
    onScreenText: ["Najczęstszy błąd", "3 proste kroki", "Zapisz na później"],
  };
}

export function demoIdeas(count: number, platforms: Platform[]): Ideas {
  const base = ["Najczęstszy błąd klientów", "3 pytania, które słyszymy codziennie", "Za kulisami naszej pracy", "Mit vs fakt", "Jak wybrać dobrą ofertę", "Historia klienta"];
  return {
    ideas: Array.from({ length: count }, (_, i) => ({
      title: base[i % base.length],
      hook: "Zanim wydasz pieniądze, obejrzyj to.",
      format: "porada",
      platform: platforms[i % platforms.length] ?? "tiktok",
      goal: "zasięg",
      outline: "Przykładowy pomysł w trybie demo. Po podłączeniu Claude plan powstaje na podstawie profilu Twojej marki.",
    })),
  };
}

export function demoPersona(description: string): Persona {
  return {
    name: "Ola AI",
    bio: `Wirtualna ambasadorka marki. ${description}`.trim(),
    imagePrompt: `photorealistic portrait of a friendly brand ambassador, ${description}, looking at camera, soft studio light, neutral background, 85mm`,
    voiceDescription: "Ciepły, energiczny głos kobiecy, ok. 28 lat, wyraźna dykcja",
  };
}

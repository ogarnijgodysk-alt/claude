import Anthropic from "@anthropic-ai/sdk";
import { betaZodOutputFormat } from "@anthropic-ai/sdk/helpers/beta/zod";
import { z } from "zod";
import type { Brand, Platform } from "../types";
import { PLATFORMS } from "../types";
import { demoScript } from "./demo";

const MODEL = "claude-opus-5-5";

export function hasClaude() {
  return Boolean(process.env.ANTHROPIC_API_KEY || process.env.ANTHROPIC_AUTH_TOKEN);
}

let client: Anthropic | undefined;
function anthropic() {
  return (client ??= new Anthropic());
}

function brandContext(brand: Brand) {
  const line = (label: string, value: string) => (value.trim() ? `${label}: ${value.trim()}` : "");
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

const SYSTEM = `Jesteś starszym strategiem social media i scenarzystą krótkich form wideo dla firm.
Piszesz teksty, które wypowiada awatar AI (klon osoby lub wirtualny influencer) prosto do kamery.
Zasady:
- Tekst mówiony ma brzmieć naturalnie na głos: krótkie zdania, bez list wypunktowanych, bez emoji, bez didaskaliów i nawiasów.
- Pierwsze zdanie to mocny hook, który zatrzymuje scrollowanie.
- Trzymaj się faktów z profilu marki; nie wymyślaj cen, wyników ani obietnic, których tam nie ma.
- Nigdy nie używaj słów i tematów oznaczonych jako zakazane.
- Pisz w języku wskazanym w zadaniu.`;

async function structured<T extends z.ZodType>(schema: T, brand: Brand, task: string): Promise<z.infer<T>> {
  const response = await anthropic().beta.messages.parse({
    model: MODEL,
    max_tokens: 16000,
    betas: ["server-side-fallback-2026-07-01"],
    fallbacks: "default",
    output_config: { effort: "medium", format: betaZodOutputFormat(schema) },
    system: `${SYSTEM}\n\nProfil marki:\n${brandContext(brand) || "(nie uzupełniono — pisz uniwersalnie)"}`,
    messages: [{ role: "user", content: task }],
  });
  if (response.stop_reason === "refusal") {
    throw new Error("Model odmówił wygenerowania tej treści. Zmień temat lub sformułowanie.");
  }
  if (!response.parsed_output) throw new Error("Nie udało się odczytać odpowiedzi modelu.");
  return response.parsed_output;
}

// ---------- Scripts ----------

export const ScriptSchema = z.object({
  title: z.string().describe("Roboczy tytuł filmu"),
  hook: z.string().describe("Pierwsze zdanie filmu"),
  script: z.string().describe("Pełny tekst do wypowiedzenia przez awatar, łącznie z hookiem i CTA"),
  caption: z.string().describe("Opis posta pod film, dopasowany do platformy"),
  hashtags: z.array(z.string()).describe("Hashtagi bez znaku #"),
  onScreenText: z.array(z.string()).describe("Krótkie napisy na ekran, maks. 6 słów każdy"),
});
export type Script = z.infer<typeof ScriptSchema>;

export interface ScriptInput {
  topic: string;
  platform: Platform;
  goal: string;
  seconds: number;
  persona?: string;
  notes?: string;
}

export async function writeScript(brand: Brand, input: ScriptInput): Promise<Script> {
  if (!hasClaude()) {
    return {
      title: input.topic,
      hook: `Temat na dziś: ${input.topic}.`,
      script: demoScript(input.topic, brand.name),
      caption: `${input.topic} — 3 rzeczy, które warto wiedzieć. (Tryb demo: dodaj ANTHROPIC_API_KEY, aby generować prawdziwe skrypty.)`,
      hashtags: ["marketing", "wskazowki", "biznes"],
      onScreenText: ["Najczęstszy błąd", "3 proste kroki", "Zapisz na później"],
    };
  }
  const words = Math.round(input.seconds * 2.4);
  return structured(
    ScriptSchema,
    brand,
    `Napisz skrypt wideo.
Platforma: ${PLATFORMS[input.platform].label}
Cel: ${input.goal}
Temat: ${input.topic}
Długość: ok. ${input.seconds} s (ok. ${words} słów tekstu mówionego)
Język: ${brand.language || "polski"}
${input.persona ? `Kto mówi (persona awatara): ${input.persona}` : ""}
${input.notes ? `Dodatkowe wskazówki: ${input.notes}` : ""}`,
  );
}

// ---------- Content plan ----------

export const IdeasSchema = z.object({
  ideas: z.array(
    z.object({
      title: z.string(),
      hook: z.string(),
      format: z.string().describe("np. porada, mit vs fakt, za kulisami, odpowiedź na komentarz, case study"),
      platform: z.enum(["tiktok", "reels", "shorts", "linkedin", "youtube", "facebook"]),
      goal: z.string(),
      outline: z.string().describe("2–3 zdania o tym, co pada w filmie"),
    }),
  ),
});
export type Ideas = z.infer<typeof IdeasSchema>;

export async function planIdeas(brand: Brand, count: number, platforms: Platform[], focus: string): Promise<Ideas> {
  if (!hasClaude()) {
    const base = ["Najczęstszy błąd klientów", "3 pytania, które słyszymy codziennie", "Za kulisami naszej pracy", "Mit vs fakt", "Jak wybrać dobrą ofertę", "Historia klienta"];
    return {
      ideas: Array.from({ length: count }, (_, i) => ({
        title: base[i % base.length],
        hook: "Zanim wydasz pieniądze, obejrzyj to.",
        format: "porada",
        platform: platforms[i % platforms.length] ?? "tiktok",
        goal: "zasięg",
        outline: "Przykładowy pomysł w trybie demo. Dodaj ANTHROPIC_API_KEY, aby dostać plan dopasowany do Twojej marki.",
      })),
    };
  }
  return structured(
    IdeasSchema,
    brand,
    `Przygotuj plan ${count} pomysłów na krótkie filmy z awatarem AI.
Platformy do wykorzystania: ${platforms.map((p) => PLATFORMS[p].label).join(", ")}
${focus ? `Na czym się skupić: ${focus}` : ""}
Zróżnicuj formaty i cele (zasięg, zaufanie, sprzedaż). Język: ${brand.language || "polski"}.`,
  );
}

// ---------- AI influencer persona ----------

export const PersonaSchema = z.object({
  name: z.string().describe("Imię i ewentualnie pseudonim wirtualnego influencera"),
  bio: z.string().describe("2–3 zdania: kim jest, o czym mówi, jak brzmi"),
  imagePrompt: z
    .string()
    .describe(
      "Szczegółowy prompt PO ANGIELSKU dla modelu FLUX: fotorealistyczny portret od klatki w górę, patrzy w obiektyw, neutralne tło studyjne, wygląd, wiek, styl, oświetlenie",
    ),
  voiceDescription: z.string().describe("Opis głosu: płeć, wiek, barwa, tempo, akcent"),
});
export type Persona = z.infer<typeof PersonaSchema>;

export async function designPersona(brand: Brand, description: string): Promise<Persona> {
  if (!hasClaude()) {
    return {
      name: "Ola AI",
      bio: `Wirtualna ambasadorka marki. ${description}`.trim(),
      imagePrompt: `photorealistic portrait of a friendly brand ambassador, ${description}, looking at camera, soft studio light, neutral background, 85mm`,
      voiceDescription: "Ciepły, energiczny głos kobiecy, ok. 28 lat, wyraźna dykcja",
    };
  }
  return structured(
    PersonaSchema,
    brand,
    `Zaprojektuj wirtualnego influencera (fikcyjną osobę) dla tej marki na podstawie opisu klienta:
"""${description}"""
Postać nie może przypominać żadnej istniejącej, rozpoznawalnej osoby.`,
  );
}

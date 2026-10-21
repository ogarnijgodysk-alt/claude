import Anthropic from "@anthropic-ai/sdk";
import { betaZodOutputFormat } from "@anthropic-ai/sdk/helpers/beta/zod";
import { z } from "zod";
import {
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
import type { Brand, ChatMessage, Platform } from "../types";

export type { Ideas, Persona, Script, ScriptInput } from "../content";

const MODEL = "claude-opus-5-5";

export function hasClaude() {
  return Boolean(process.env.ANTHROPIC_API_KEY || process.env.ANTHROPIC_AUTH_TOKEN);
}

let client: Anthropic | undefined;
function anthropic() {
  return (client ??= new Anthropic());
}

async function structured<T extends z.ZodType>(schema: T, brand: Brand, task: string): Promise<z.infer<T>> {
  const response = await anthropic().beta.messages.parse({
    model: MODEL,
    max_tokens: 16000,
    betas: ["server-side-fallback-2026-07-01"],
    fallbacks: "default",
    output_config: { effort: "medium", format: betaZodOutputFormat(schema) },
    system: writerSystem(brand),
    messages: [{ role: "user", content: task }],
  });
  if (response.stop_reason === "refusal") {
    throw new Error("Model odmówił wygenerowania tej treści. Zmień temat lub sformułowanie.");
  }
  if (!response.parsed_output) throw new Error("Nie udało się odczytać odpowiedzi modelu.");
  return response.parsed_output;
}

// ---------- Scripts ----------

const ScriptSchema = z.object({
  title: z.string().describe("Roboczy tytuł filmu"),
  hook: z.string().describe("Pierwsze zdanie filmu"),
  script: z.string().describe("Pełny tekst do wypowiedzenia przez awatar, łącznie z hookiem i CTA"),
  caption: z.string().describe("Opis posta pod film, dopasowany do platformy"),
  hashtags: z.array(z.string()).describe("Hashtagi bez znaku #"),
  onScreenText: z.array(z.string()).describe("Krótkie napisy na ekran, maks. 6 słów każdy"),
});

export async function writeScript(brand: Brand, input: ScriptInput): Promise<Script> {
  if (!hasClaude()) return demoScript(brand, input);
  return structured(ScriptSchema, brand, scriptTask(brand, input));
}

// ---------- Content plan ----------

const IdeasSchema = z.object({
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

export async function planIdeas(brand: Brand, count: number, platforms: Platform[], focus: string): Promise<Ideas> {
  if (!hasClaude()) return demoIdeas(count, platforms);
  return structured(IdeasSchema, brand, ideasTask(brand, count, platforms, focus));
}

// ---------- AI influencer persona ----------

const PersonaSchema = z.object({
  name: z.string().describe("Imię i ewentualnie pseudonim wirtualnego influencera"),
  bio: z.string().describe("2–3 zdania: kim jest, o czym mówi, jak brzmi"),
  imagePrompt: z
    .string()
    .describe(
      "Szczegółowy prompt PO ANGIELSKU dla modelu obrazów: fotorealistyczny portret od klatki w górę, patrzy w obiektyw, neutralne tło studyjne, wygląd, wiek, styl, oświetlenie",
    ),
  voiceDescription: z.string().describe("Opis głosu: płeć, wiek, barwa, tempo, akcent"),
});

export async function designPersona(brand: Brand, description: string): Promise<Persona> {
  if (!hasClaude()) return demoPersona(description);
  return structured(PersonaSchema, brand, personaTask(description));
}

// ---------- Website assistant (voice chat) ----------

/** Short spoken answer for the website assistant. Low effort keeps the reply fast enough for a voice conversation. */
export async function chatReply(system: string, knowledge: string, history: ChatMessage[]): Promise<string> {
  const response = await anthropic().beta.messages.create({
    model: MODEL,
    max_tokens: 2000,
    betas: ["server-side-fallback-2026-07-01"],
    fallbacks: "default",
    output_config: { effort: "low" },
    system: `${system}\n\nWiedza o firmie:\n${knowledge || "(brak — kieruj do kontaktu z zespołem)"}`,
    messages: history.slice(-20).map((m) => ({ role: m.role, content: m.content })),
  });
  if (response.stop_reason === "refusal") return "Przepraszam, w tej sprawie nie pomogę. Mogę odpowiedzieć na pytania o naszą ofertę.";
  return response.content
    .flatMap((b) => (b.type === "text" ? [b.text] : []))
    .join(" ")
    .trim();
}

import type { Assistant, Brand, ChatMessage } from "./types";

// Pure helpers shared by the server (Claude, Tavus persona) and the in-browser demo.

export function assistantSystemPrompt(brand: Brand, assistant: Assistant, cloneName: string, cloneDescription: string) {
  const company = brand.name || "firma";
  return `Jesteś ${cloneName} — wirtualnym asystentem wideo firmy ${company} na jej stronie internetowej. Rozmawiasz z odwiedzającymi na żywo, głosem.
${cloneDescription ? `Twoja persona: ${cloneDescription}` : ""}
Zasady:
- Odpowiadasz krótko, jak w rozmowie: 1–3 zdania, bez list, bez emoji, bez formatowania.
- Mówisz w języku: ${assistant.language || brand.language || "polski"}. Jeśli rozmówca mówi w innym języku, odpowiadasz w jego języku.
- Opierasz się wyłącznie na wiedzy poniżej. Nie wymyślasz cen, terminów ani obietnic.
- Gdy czegoś nie wiesz albo ktoś chce kupić, umówić się lub porozmawiać z człowiekiem, kierujesz go tak: ${assistant.handoff || "zostaw kontakt, a zespół odezwie się wkrótce"}.
- Ton: ${brand.tone || "przyjazny i konkretny"}.
- Jeśli ktoś zapyta, czy jesteś człowiekiem, mówisz wprost, że jesteś asystentem AI.`;
}

export function assistantKnowledge(brand: Brand, assistant: Assistant) {
  return [
    brand.name && `Firma: ${brand.name}`,
    brand.industry && `Branża: ${brand.industry}`,
    brand.description && `O firmie i ofercie: ${brand.description}`,
    brand.ctas && `Wezwania do działania: ${brand.ctas}`,
    assistant.knowledge && `Wiedza dla asystenta (FAQ, cennik, godziny): ${assistant.knowledge}`,
    brand.bannedWords && `Nie używaj: ${brand.bannedWords}`,
  ]
    .filter(Boolean)
    .join("\n");
}

/** Keyword-overlap answer used when no LLM is configured, so the demo still responds sensibly. */
export function demoAnswer(assistant: Assistant, brand: Brand, history: ChatMessage[]) {
  const question = history.filter((m) => m.role === "user").at(-1)?.content.toLowerCase() ?? "";
  // Crude stemming for Polish inflection: compare 5-letter prefixes ("otwarcia" ~ "otwarte").
  const stems = question
    .split(/[^\p{L}\p{N}]+/u)
    .filter((w) => w.length > 3)
    .map((w) => w.slice(0, 5));
  const sentences = `${assistant.knowledge} ${brand.description}`
    .split(/(?<=[.!?\n])\s+/)
    .map((s) => s.trim())
    .filter(Boolean);
  let best = "";
  let bestScore = 0;
  for (const s of sentences) {
    const lower = s.toLowerCase();
    const score = stems.filter((w) => lower.includes(w)).length;
    if (score > bestScore) [best, bestScore] = [s, score];
  }
  if (best) return `${best} Czy mogę pomóc w czymś jeszcze?`;
  return `Dobre pytanie. Najlepiej odpowie na nie nasz zespół: ${assistant.handoff || "zostaw kontakt, a odezwiemy się wkrótce"}.`;
}

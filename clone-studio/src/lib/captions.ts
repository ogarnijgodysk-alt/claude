import type { Brand } from "./types";

export const AI_DISCLOSURE = "Treść wygenerowana z użyciem AI.";

/** Appends the AI-content disclosure (EU AI Act art. 50) when the brand has it switched on. */
export function finalCaption(brand: Brand, caption: string) {
  if (!brand.aiDisclosure || caption.includes(AI_DISCLOSURE)) return caption;
  return `${caption.trim()}\n\n${AI_DISCLOSURE}`.trim();
}

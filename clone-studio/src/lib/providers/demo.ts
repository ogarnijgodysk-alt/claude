// Placeholder media used when a provider has no API key, so every flow stays clickable.

function hash(seed: string) {
  let h = 2166136261;
  for (let i = 0; i < seed.length; i++) {
    h ^= seed.charCodeAt(i);
    h = Math.imul(h, 16777619);
  }
  return h >>> 0;
}

const PALETTES = [
  ["#6d28d9", "#db2777"],
  ["#0e7490", "#4f46e5"],
  ["#b45309", "#be123c"],
  ["#047857", "#0369a1"],
  ["#7c3aed", "#0891b2"],
  ["#be185d", "#ea580c"],
];

function escapeXml(s: string) {
  return s.replace(/[<>&"']/g, (c) => `&#${c.charCodeAt(0)};`);
}

/** Portrait-like SVG: gradient backdrop, head-and-shoulders silhouette, caption. */
export function demoPortraitSvg(seed: string, label: string, aspect: "9:16" | "1:1" | "16:9" = "1:1") {
  const h = hash(seed);
  const [a, b] = PALETTES[h % PALETTES.length];
  const [w, ht] = aspect === "9:16" ? [720, 1280] : aspect === "16:9" ? [1280, 720] : [1024, 1024];
  const cx = w / 2;
  const headR = Math.min(w, ht) * 0.16;
  const headY = ht * 0.42;
  const skin = ["#f1c7a8", "#d9a07a", "#a8714f", "#7a4e33", "#f5d6c0"][(h >> 3) % 5];
  const hair = ["#2b1d14", "#4a3020", "#c89b5a", "#111", "#7b3f1d"][(h >> 6) % 5];
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${ht}" viewBox="0 0 ${w} ${ht}">
  <defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${a}"/><stop offset="1" stop-color="${b}"/></linearGradient></defs>
  <rect width="100%" height="100%" fill="url(#g)"/>
  <circle cx="${cx}" cy="${headY - headR * 0.25}" r="${headR * 1.12}" fill="${hair}"/>
  <ellipse cx="${cx}" cy="${ht * 0.95}" rx="${headR * 2.3}" ry="${headR * 1.9}" fill="rgba(15,15,30,.85)"/>
  <rect x="${cx - headR * 0.35}" y="${headY + headR * 0.7}" width="${headR * 0.7}" height="${headR * 0.8}" fill="${skin}"/>
  <circle cx="${cx}" cy="${headY}" r="${headR}" fill="${skin}"/>
  <text x="${cx}" y="${ht * 0.1}" font-family="system-ui,sans-serif" font-size="${Math.round(w * 0.04)}" font-weight="700" fill="#fff" text-anchor="middle">${escapeXml(label)}</text>
  <text x="${cx}" y="${ht * 0.1 + w * 0.05}" font-family="system-ui,sans-serif" font-size="${Math.round(w * 0.026)}" fill="rgba(255,255,255,.8)" text-anchor="middle">PODGLĄD DEMO</text>
</svg>`;
}

/** Demo jobs finish after a short delay so the UI shows a realistic progress state. */
export const DEMO_JOB_MS = 6000;

export function demoJobDone(createdAt: string) {
  return Date.now() - new Date(createdAt).getTime() > DEMO_JOB_MS;
}

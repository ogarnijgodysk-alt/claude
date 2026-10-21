// Builds a single self-contained HTML file of the app in browser-storage mode,
// for the claude.ai preview (one page, no server): node scripts/build-preview.mjs [outFile]
import { execFileSync } from "node:child_process";
import { mkdirSync, readFileSync, writeFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import * as esbuild from "esbuild";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const out = path.resolve(process.argv[2] ?? path.join(root, "preview-dist/index.html"));
const tmp = path.join(root, "preview-dist");
mkdirSync(tmp, { recursive: true });
mkdirSync(path.dirname(out), { recursive: true });

execFileSync(
  path.join(root, "node_modules/.bin/tailwindcss"),
  ["-i", "src/app/globals.css", "-o", path.join(tmp, "app.css"), "--minify"],
  { cwd: root, stdio: "inherit" },
);

const result = await esbuild.build({
  entryPoints: [path.join(root, "preview/main.tsx")],
  bundle: true,
  write: false,
  format: "iife",
  minify: true,
  jsx: "automatic",
  target: ["es2020", "safari15"],
  alias: {
    "next/link": path.join(root, "preview/shims/link.tsx"),
    "next/navigation": path.join(root, "preview/shims/navigation.ts"),
  },
  define: {
    "process.env.NODE_ENV": '"production"',
    "process.env.NEXT_PUBLIC_STORAGE": '"browser"',
    // No app server behind the preview: AI comes from the claude.ai viewer or demo content.
    "process.env.NEXT_PUBLIC_SERVER_AI": '"0"',
  },
  logOverride: { "unsupported-directive": "silent" },
});

const js = result.outputFiles[0].text.replace(/<\/script/gi, "<\\/script");
const css = readFileSync(path.join(tmp, "app.css"), "utf8").replace(/<\/style/gi, "<\\/style");
const html = `<title>CloneStudio</title>
<meta name="description" content="Klony AI, nagrania wideo i asystent na stronę dla firm — wersja testowa">
<style>${css}</style>
<div id="root"></div>
<script>${js}</script>
`;
writeFileSync(out, html);
console.log(`preview: ${out} (${Math.round(html.length / 1024)} KB)`);

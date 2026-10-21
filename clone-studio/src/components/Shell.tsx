"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useState, type ReactNode } from "react";
import type { ProviderStatus } from "@/lib/providers/status";
import { BROWSER_MODE, api } from "./api";
import { cx } from "./ui";

const NAV = [
  { href: "/", label: "Pulpit", icon: "◎" },
  { href: "/klony", label: "Klony AI", icon: "◉" },
  { href: "/studio", label: "Studio nagrań", icon: "●" },
  { href: "/asystent", label: "Asystent na stronę", icon: "◐" },
  { href: "/pomysly", label: "Plan treści", icon: "✦" },
  { href: "/sesja", label: "Sesje zdjęciowe", icon: "▣" },
  { href: "/biblioteka", label: "Biblioteka", icon: "▤" },
  { href: "/marka", label: "Profil marki", icon: "◈" },
  { href: "/ustawienia", label: "Integracje", icon: "⚙" },
];

/** In browser-storage mode, stored files must be registered before anything renders them. */
function useBrowserReady() {
  const [ready, setReady] = useState(!BROWSER_MODE);
  useEffect(() => {
    if (BROWSER_MODE) import("@/lib/browser/backend").then((b) => b.ready()).then(() => setReady(true));
  }, []);
  return ready;
}

export function Shell({ children }: { children: ReactNode }) {
  const path = usePathname();
  const ready = useBrowserReady();
  const [open, setOpen] = useState(false);
  const [status, setStatus] = useState<ProviderStatus | null>(null);

  useEffect(() => {
    api<ProviderStatus>("/api/status").then(setStatus, () => undefined);
  }, []);
  useEffect(() => setOpen(false), [path]);

  if (!ready) return <div className="grid min-h-screen place-items-center text-sm text-zinc-400">Ładowanie…</div>;
  // The website widget renders on its own, without the studio's navigation.
  if (path.startsWith("/widget")) return <>{children}</>;

  const demo = status && (status.browser || !status.claude || !status.heygen || !(status.kie || status.fal));

  return (
    <div className="min-h-screen md:flex">
      <header className="flex items-center justify-between border-b border-zinc-200 bg-white px-4 py-3 md:hidden">
        <Logo />
        <button onClick={() => setOpen((o) => !o)} className="rounded-lg px-3 py-1.5 text-sm ring-1 ring-zinc-200" aria-label="Menu">
          Menu
        </button>
      </header>
      <aside
        className={cx(
          "border-r border-zinc-200 bg-white md:sticky md:top-0 md:block md:h-screen md:w-60 md:shrink-0",
          open ? "block" : "hidden",
        )}
      >
        <div className="hidden px-5 py-5 md:block">
          <Logo />
        </div>
        <nav className="space-y-0.5 px-3 pb-4">
          {NAV.map((n) => {
            const active = n.href === "/" ? path === "/" : path.startsWith(n.href);
            return (
              <Link
                key={n.href}
                href={n.href}
                className={cx(
                  "flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition",
                  active ? "bg-brand-50 text-brand-700" : "text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900",
                )}
              >
                <span className="w-4 text-center text-xs opacity-70">{n.icon}</span>
                {n.label}
              </Link>
            );
          })}
        </nav>
        {demo && (
          <Link href="/ustawienia" className="mx-3 mb-4 block rounded-xl bg-amber-50 p-3 text-xs text-amber-800 ring-1 ring-amber-200">
            {status?.browser ? (
              <>
                <b>Wersja testowa.</b> Dane zostają tylko w tej przeglądarce, wideo i zdjęcia są przykładowe →
              </>
            ) : (
              <>
                <b>Tryb demo</b> dla części funkcji. Dodaj klucze API, aby generować prawdziwe klony i wideo →
              </>
            )}
          </Link>
        )}
      </aside>
      <main className="min-w-0 flex-1 px-4 py-6 md:px-10 md:py-8">
        <div className="mx-auto max-w-6xl">{children}</div>
      </main>
    </div>
  );
}

function Logo() {
  return (
    <Link href="/" className="flex items-center gap-2">
      <span className="grid h-8 w-8 place-items-center rounded-lg bg-gradient-to-br from-brand-500 to-pink-500 text-sm font-bold text-white">C</span>
      <span className="font-semibold tracking-tight">CloneStudio</span>
    </Link>
  );
}

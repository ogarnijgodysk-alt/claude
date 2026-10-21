"use client";

import { createContext, useContext, useMemo } from "react";

// Stand-in for next/navigation in the single-file preview: an in-memory router,
// because the claude.ai viewer doesn't expose path routes or a usable URL.

export interface RouterState {
  path: string;
  query: string;
  params: Record<string, string>;
  navigate(href: string, replace?: boolean): void;
  back(): void;
}

export const RouterContext = createContext<RouterState>({
  path: "/",
  query: "",
  params: {},
  navigate: () => undefined,
  back: () => undefined,
});

export function useRouter() {
  const r = useContext(RouterContext);
  return { push: (h: string) => r.navigate(h), replace: (h: string) => r.navigate(h, true), back: r.back, refresh: () => undefined, prefetch: () => undefined };
}

export function usePathname() {
  return useContext(RouterContext).path;
}

export function useSearchParams() {
  const q = useContext(RouterContext).query;
  return useMemo(() => new URLSearchParams(q), [q]);
}

export function useParams<T extends Record<string, string>>() {
  return useContext(RouterContext).params as T;
}

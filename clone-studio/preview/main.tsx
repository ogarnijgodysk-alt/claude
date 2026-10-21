import { useCallback, useMemo, useRef, useState, type ComponentType } from "react";
import { createRoot } from "react-dom/client";
import AssistantPage from "@/app/asystent/page";
import LibraryPage from "@/app/biblioteka/page";
import CloneDetailPage from "@/app/klony/[id]/page";
import NewClonePage from "@/app/klony/nowy/page";
import ClonesPage from "@/app/klony/page";
import BrandPage from "@/app/marka/page";
import Dashboard from "@/app/page";
import IdeasPage from "@/app/pomysly/page";
import PhotoShootPage from "@/app/sesja/page";
import StudioPage from "@/app/studio/page";
import SettingsPage from "@/app/ustawienia/page";
import WidgetPage from "@/app/widget/[id]/page";
import { Shell } from "@/components/Shell";
import { RouterContext, type RouterState } from "./shims/navigation";

// Single-file preview of the app for the claude.ai viewer: the real pages and components,
// browser storage, and an in-memory router in place of Next.js routing.

const ROUTES: [RegExp, ComponentType, string[]][] = [
  [/^\/$/, Dashboard, []],
  [/^\/klony$/, ClonesPage, []],
  [/^\/klony\/nowy$/, NewClonePage, []],
  [/^\/klony\/([^/]+)$/, CloneDetailPage, ["id"]],
  [/^\/studio$/, StudioPage, []],
  [/^\/asystent$/, AssistantPage, []],
  [/^\/widget\/([^/]+)$/, WidgetPage, ["id"]],
  [/^\/pomysly$/, IdeasPage, []],
  [/^\/sesja$/, PhotoShootPage, []],
  [/^\/biblioteka$/, LibraryPage, []],
  [/^\/marka$/, BrandPage, []],
  [/^\/ustawienia$/, SettingsPage, []],
];

// Plain #tokens are the only deep links the viewer passes through (e.g. …#asystent).
const TOKENS: Record<string, string> = { klony: "/klony", nowy: "/klony/nowy", studio: "/studio", asystent: "/asystent", pomysly: "/pomysly", sesja: "/sesja", biblioteka: "/biblioteka", marka: "/marka", integracje: "/ustawienia" };

function initial() {
  const token = location.hash.replace(/^#/, "");
  return { path: TOKENS[token] ?? "/", query: "" };
}

function App() {
  const [loc, setLoc] = useState(initial);
  const history = useRef<{ path: string; query: string }[]>([]);

  const navigate = useCallback((href: string, replace?: boolean) => {
    const [path, query = ""] = href.split("?");
    setLoc((cur) => {
      if (!replace) history.current.push(cur);
      return { path: path || "/", query };
    });
    window.scrollTo(0, 0);
  }, []);
  const back = useCallback(() => setLoc((cur) => history.current.pop() ?? cur), []);

  const match = ROUTES.map(([re, C, names]) => ({ m: loc.path.match(re), C, names })).find((r) => r.m);
  const params = useMemo(() => Object.fromEntries((match?.names ?? []).map((n, i) => [n, decodeURIComponent(match!.m![i + 1])])), [match]);
  const value: RouterState = { ...loc, params, navigate, back };
  const Page = match?.C ?? Dashboard;

  return (
    <RouterContext.Provider value={value}>
      <Shell>
        <Page key={`${loc.path}?${loc.query}`} />
      </Shell>
    </RouterContext.Provider>
  );
}

createRoot(document.getElementById("root")!).render(<App />);

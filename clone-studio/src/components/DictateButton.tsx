"use client";

import { useEffect, useRef, useState } from "react";
import { cx } from "./ui";
import { recognitionCtor, type Recognition } from "./voice";

/** Microphone button that types what you say into a field. Hidden where the browser can't do it. */
export function DictateButton({ onText, lang = "pl-PL", className }: { onText: (text: string) => void; lang?: string; className?: string }) {
  const [supported, setSupported] = useState(false);
  const [listening, setListening] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const recRef = useRef<Recognition | null>(null);
  // Results arrive long after the click; always hand them to the latest callback.
  const onTextRef = useRef(onText);
  onTextRef.current = onText;

  useEffect(() => {
    setSupported(Boolean(recognitionCtor()));
    return () => recRef.current?.abort();
  }, []);

  if (!supported) return null;

  function toggle() {
    if (listening) {
      recRef.current?.stop();
      return;
    }
    const Ctor = recognitionCtor()!;
    const rec = new Ctor();
    rec.lang = lang;
    rec.continuous = true;
    rec.interimResults = false;
    rec.onresult = (e) => {
      for (let i = e.resultIndex; i < e.results.length; i++) {
        if (e.results[i].isFinal) onTextRef.current(e.results[i][0].transcript.trim());
      }
    };
    rec.onerror = (e) => setError(e.error === "not-allowed" ? "Zezwól na mikrofon" : null);
    rec.onend = () => setListening(false);
    recRef.current = rec;
    setError(null);
    setListening(true);
    rec.start();
  }

  return (
    <button
      type="button"
      onClick={toggle}
      title={listening ? "Zakończ dyktowanie" : "Dyktuj głosem"}
      aria-label={listening ? "Zakończ dyktowanie" : "Dyktuj głosem"}
      className={cx(
        "inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg px-2.5 text-sm font-medium ring-1 transition",
        listening ? "bg-red-50 text-red-600 ring-red-200" : "bg-white text-zinc-600 ring-zinc-200 hover:bg-zinc-50",
        className,
      )}
    >
      <MicIcon className={listening ? "animate-pulse" : ""} />
      <span className="hidden sm:inline">{error ?? (listening ? "Słucham…" : "Dyktuj")}</span>
    </button>
  );
}

export function MicIcon({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" className={className} aria-hidden>
      <rect x="9" y="3" width="6" height="11" rx="3" />
      <path d="M5 11a7 7 0 0 0 14 0M12 18v3" />
    </svg>
  );
}

/** Appends dictated text to an existing value with a separating space. */
export function appendText(current: string, addition: string) {
  if (!current.trim()) return addition;
  return `${current.replace(/\s+$/, "")} ${addition}`;
}

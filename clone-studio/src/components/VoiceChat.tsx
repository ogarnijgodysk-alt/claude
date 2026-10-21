"use client";

import { useEffect, useRef, useState } from "react";
import type { ChatMessage } from "@/lib/types";
import { api, apiAudio } from "./api";
import { MicIcon } from "./DictateButton";
import { cx } from "./ui";
import { langCode, recognitionCtor, speak, stopSpeaking, type Recognition } from "./voice";

type Phase = "idle" | "listening" | "thinking" | "speaking";

/**
 * Hands-free voice conversation with a clone: the visitor speaks, Claude answers from the
 * company's knowledge, and the answer is spoken in the clone's voice (or the device voice).
 * Works on phones; falls back to typing where speech recognition is unavailable.
 */
export function VoiceChat({
  assistantId,
  name,
  cover,
  greeting,
  language,
  compact,
}: {
  assistantId: string;
  name: string;
  cover?: string;
  greeting: string;
  language?: string;
  compact?: boolean;
}) {
  const lang = langCode(language);
  const [active, setActive] = useState(false);
  const [phase, setPhase] = useState<Phase>("idle");
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [interim, setInterim] = useState("");
  const [text, setText] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [canListen, setCanListen] = useState(false);
  const activeRef = useRef(false);
  // Only a conversation started by voice reopens the microphone after each answer; typing never does.
  const handsFree = useRef(false);
  const recRef = useRef<Recognition | null>(null);
  const messagesRef = useRef<ChatMessage[]>([]);
  const silentEnds = useRef(0);
  const logRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    setCanListen(Boolean(recognitionCtor()));
    // Voices load asynchronously in Chrome; touching the list early warms it up.
    window.speechSynthesis?.getVoices();
    return () => stop();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    logRef.current?.scrollTo({ top: logRef.current.scrollHeight, behavior: "smooth" });
  }, [messages, interim]);

  function push(m: ChatMessage) {
    messagesRef.current = [...messagesRef.current, m];
    setMessages(messagesRef.current);
  }

  async function say(reply: string) {
    setPhase("speaking");
    const mp3 = await apiAudio(`/api/assistants/${assistantId}/speech`, { text: reply }).catch(() => null);
    await speak(reply, lang, mp3);
    if (!activeRef.current) return;
    if (handsFree.current && recognitionCtor()) listen();
    else setPhase("idle");
  }

  function listen() {
    const Ctor = recognitionCtor();
    if (!Ctor || !activeRef.current) return setPhase("idle");
    const rec = new Ctor();
    rec.lang = lang;
    rec.continuous = false;
    rec.interimResults = true;
    let finalText = "";
    rec.onresult = (e) => {
      let partial = "";
      for (let i = e.resultIndex; i < e.results.length; i++) {
        const t = e.results[i][0].transcript;
        if (e.results[i].isFinal) finalText += t;
        else partial += t;
      }
      setInterim(finalText || partial);
    };
    rec.onerror = (e) => {
      if (e.error === "not-allowed" || e.error === "service-not-allowed") {
        setError("Przeglądarka nie dała dostępu do mikrofonu. Możesz pisać poniżej.");
        setCanListen(false);
      }
    };
    rec.onend = () => {
      setInterim("");
      if (!activeRef.current) return;
      if (finalText.trim()) {
        silentEnds.current = 0;
        send(finalText.trim());
      } else if (++silentEnds.current < 3) {
        listen();
      } else {
        setPhase("idle"); // stop the loop after a long silence; tap the mic to continue
      }
    };
    recRef.current = rec;
    setPhase("listening");
    try {
      rec.start();
    } catch {
      setPhase("idle");
    }
  }

  async function send(question: string) {
    if (!question.trim()) return;
    activeRef.current = true;
    setActive(true);
    recRef.current?.abort();
    push({ role: "user", content: question });
    setPhase("thinking");
    setError(null);
    try {
      const { reply } = await api<{ reply: string }>(`/api/assistants/${assistantId}/chat`, { method: "POST", json: { messages: messagesRef.current } });
      push({ role: "assistant", content: reply });
      await say(reply);
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
      setPhase("idle");
    }
  }

  function start() {
    handsFree.current = Boolean(recognitionCtor());
    activeRef.current = true;
    setActive(true);
    silentEnds.current = 0;
    setError(null);
    if (!messagesRef.current.length) {
      push({ role: "assistant", content: greeting });
      say(greeting);
    } else {
      listen();
    }
  }

  function stop() {
    activeRef.current = false;
    handsFree.current = false;
    setActive(false);
    recRef.current?.abort();
    stopSpeaking();
    setPhase("idle");
    setInterim("");
  }

  function micTap() {
    handsFree.current = true;
    if (phase === "listening") return recRef.current?.stop();
    if (phase === "speaking") stopSpeaking();
    if (!active) return start();
    silentEnds.current = 0;
    listen();
  }

  const status: Record<Phase, string> = {
    idle: active ? "Dotknij mikrofonu, aby mówić" : "Gotowy do rozmowy",
    listening: "Słucham…",
    thinking: "Myślę…",
    speaking: "Mówię…",
  };

  return (
    <div className={cx("flex h-full min-h-0 flex-col", compact ? "gap-3" : "gap-4")}>
      <div className="flex flex-col items-center gap-3 pt-2">
        <div className="relative">
          <span className={cx("absolute inset-0 rounded-full bg-brand-500/30 transition", phase === "speaking" ? "animate-ping" : "opacity-0")} />
          <span className={cx("absolute -inset-1.5 rounded-full ring-4 transition", phase === "listening" ? "ring-emerald-400" : phase === "speaking" ? "ring-brand-400" : "ring-transparent")} />
          {cover ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={cover} alt={name} className={cx("relative rounded-full object-cover shadow-lg transition-transform", compact ? "h-24 w-24" : "h-32 w-32", phase === "speaking" && "scale-105")} />
          ) : (
            <div className={cx("relative rounded-full bg-gradient-to-br from-brand-400 to-pink-400", compact ? "h-24 w-24" : "h-32 w-32")} />
          )}
        </div>
        <div className="text-center">
          <p className="font-semibold">{name}</p>
          <p className="text-xs text-zinc-500" aria-live="polite">
            {status[phase]}
          </p>
        </div>
      </div>

      <div ref={logRef} className="min-h-0 flex-1 space-y-2 overflow-y-auto px-1">
        {messages.map((m, i) => (
          <div key={i} className={cx("max-w-[85%] rounded-2xl px-3 py-2 text-sm", m.role === "user" ? "ml-auto bg-brand-600 text-white" : "bg-zinc-100 text-zinc-800")}>
            {m.content}
          </div>
        ))}
        {interim && <div className="ml-auto max-w-[85%] rounded-2xl bg-brand-100 px-3 py-2 text-sm italic text-brand-700">{interim}</div>}
      </div>

      {error && <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">{error}</p>}

      <div className="flex items-center gap-2">
        {canListen && (
          <button
            type="button"
            onClick={micTap}
            aria-label={phase === "listening" ? "Zakończ mówienie" : "Mów"}
            className={cx(
              "grid h-12 w-12 shrink-0 place-items-center rounded-full text-white shadow transition",
              phase === "listening" ? "bg-emerald-500" : "bg-brand-600 hover:bg-brand-700",
            )}
          >
            <MicIcon className="h-5 w-5" />
          </button>
        )}
        <form
          className="flex min-w-0 flex-1 gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            const q = text;
            setText("");
            send(q);
          }}
        >
          <input
            value={text}
            onChange={(e) => setText(e.target.value)}
            placeholder={canListen ? "…albo napisz pytanie" : "Napisz pytanie"}
            className="min-w-0 flex-1 rounded-full bg-white px-4 py-2.5 text-sm ring-1 ring-zinc-200 focus:outline-none focus:ring-2 focus:ring-brand-500"
          />
          <button type="submit" disabled={!text.trim() || phase === "thinking"} className="rounded-full bg-zinc-900 px-4 text-sm font-medium text-white disabled:bg-zinc-300">
            Wyślij
          </button>
        </form>
      </div>
      {!active && messages.length === 0 && (
        <button type="button" onClick={start} className="rounded-full bg-brand-600 py-3 text-sm font-semibold text-white shadow hover:bg-brand-700">
          ▶ Rozpocznij rozmowę głosową
        </button>
      )}
      {active && (
        <button type="button" onClick={stop} className="text-xs text-zinc-500 underline">
          Zakończ rozmowę
        </button>
      )}
    </div>
  );
}

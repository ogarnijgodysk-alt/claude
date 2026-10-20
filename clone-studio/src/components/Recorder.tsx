"use client";

import { useEffect, useRef, useState } from "react";
import { Button, cx } from "./ui";

export interface Recording {
  blob: Blob;
  url: string;
  seconds: number;
  /** A still frame from the camera, used as the clone's cover image. */
  poster?: Blob;
}

const VIDEO_TYPES = ["video/mp4;codecs=avc1,mp4a", "video/mp4", "video/webm;codecs=vp9,opus", "video/webm"];
const AUDIO_TYPES = ["audio/mp4", "audio/webm;codecs=opus", "audio/webm"];

function pickType(candidates: string[]) {
  if (typeof MediaRecorder === "undefined") return "";
  return candidates.find((t) => MediaRecorder.isTypeSupported(t)) ?? "";
}

function fmt(s: number) {
  return `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, "0")}`;
}

export function Recorder({
  kind,
  teleprompter,
  minSeconds = 0,
  maxSeconds = 300,
  onChange,
}: {
  kind: "video" | "audio";
  teleprompter?: string;
  minSeconds?: number;
  maxSeconds?: number;
  onChange: (rec: Recording | null) => void;
}) {
  const liveRef = useRef<HTMLVideoElement>(null);
  const streamRef = useRef<MediaStream | null>(null);
  const recorderRef = useRef<MediaRecorder | null>(null);
  const promptRef = useRef<HTMLDivElement>(null);
  const startedAt = useRef(0);
  const posterRef = useRef<Blob | undefined>(undefined);

  const [state, setState] = useState<"idle" | "ready" | "countdown" | "recording" | "done">("idle");
  const [countdown, setCountdown] = useState(3);
  const [elapsed, setElapsed] = useState(0);
  const [result, setResult] = useState<Recording | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [speed, setSpeed] = useState(2);
  const [level, setLevel] = useState(0);

  const stopStream = () => {
    streamRef.current?.getTracks().forEach((t) => t.stop());
    streamRef.current = null;
  };
  useEffect(() => stopStream, []);

  // Microphone level meter, so people can see the mic actually works before recording.
  useEffect(() => {
    if (state === "idle" || state === "done" || !streamRef.current) return;
    const ctx = new AudioContext();
    const analyser = ctx.createAnalyser();
    analyser.fftSize = 256;
    ctx.createMediaStreamSource(streamRef.current).connect(analyser);
    const buf = new Uint8Array(analyser.frequencyBinCount);
    let raf = 0;
    const loop = () => {
      analyser.getByteTimeDomainData(buf);
      let peak = 0;
      for (const v of buf) peak = Math.max(peak, Math.abs(v - 128));
      setLevel(peak / 128);
      raf = requestAnimationFrame(loop);
    };
    loop();
    return () => {
      cancelAnimationFrame(raf);
      ctx.close();
    };
  }, [state]);

  useEffect(() => {
    if (state !== "recording") return;
    const id = setInterval(() => {
      const s = (Date.now() - startedAt.current) / 1000;
      setElapsed(s);
      if (s >= maxSeconds) stop();
      if (promptRef.current) promptRef.current.scrollTop += speed * 0.6;
    }, 50);
    return () => clearInterval(id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [state, speed, maxSeconds]);

  async function enable() {
    setError(null);
    try {
      const stream = await navigator.mediaDevices.getUserMedia(
        kind === "video"
          ? { video: { width: { ideal: 1920 }, height: { ideal: 1080 }, facingMode: "user" }, audio: { echoCancellation: true, noiseSuppression: true } }
          : { audio: { echoCancellation: true, noiseSuppression: true } },
      );
      streamRef.current = stream;
      setState("ready");
      if (kind === "video") requestAnimationFrame(() => liveRef.current && (liveRef.current.srcObject = stream));
    } catch {
      setError(kind === "video" ? "Brak dostępu do kamery lub mikrofonu. Zezwól na dostęp w przeglądarce." : "Brak dostępu do mikrofonu. Zezwól na dostęp w przeglądarce.");
    }
  }

  function capturePoster() {
    const v = liveRef.current;
    if (!v || !v.videoWidth) return;
    const c = document.createElement("canvas");
    c.width = v.videoWidth;
    c.height = v.videoHeight;
    c.getContext("2d")!.drawImage(v, 0, 0);
    c.toBlob((b) => (posterRef.current = b ?? undefined), "image/jpeg", 0.9);
  }

  function begin() {
    setState("countdown");
    setCountdown(3);
    let n = 3;
    const id = setInterval(() => {
      n -= 1;
      setCountdown(n);
      if (n === 0) {
        clearInterval(id);
        record();
      }
    }, 1000);
  }

  function record() {
    const stream = streamRef.current!;
    const mimeType = pickType(kind === "video" ? VIDEO_TYPES : AUDIO_TYPES);
    const rec = new MediaRecorder(stream, mimeType ? { mimeType, videoBitsPerSecond: 6_000_000 } : undefined);
    const chunks: Blob[] = [];
    rec.ondataavailable = (e) => e.data.size && chunks.push(e.data);
    rec.onstop = () => {
      const blob = new Blob(chunks, { type: (rec.mimeType || mimeType || (kind === "video" ? "video/webm" : "audio/webm")).split(";")[0] });
      const seconds = (Date.now() - startedAt.current) / 1000;
      const r = { blob, url: URL.createObjectURL(blob), seconds, poster: posterRef.current };
      setResult(r);
      setState("done");
      stopStream();
      onChange(r);
    };
    recorderRef.current = rec;
    startedAt.current = Date.now();
    setElapsed(0);
    if (promptRef.current) promptRef.current.scrollTop = 0;
    rec.start(1000);
    setState("recording");
    if (kind === "video") setTimeout(capturePoster, 1200);
  }

  function stop() {
    if (recorderRef.current?.state === "recording") recorderRef.current.stop();
  }

  function reset() {
    if (result) URL.revokeObjectURL(result.url);
    setResult(null);
    onChange(null);
    setState("idle");
    enable();
  }

  const tooShort = state === "recording" && elapsed < minSeconds;

  return (
    <div className="space-y-3">
      <div className={cx("relative overflow-hidden rounded-xl bg-zinc-900", kind === "video" ? "aspect-video" : "h-40")}>
        {kind === "video" && state !== "done" && <video ref={liveRef} autoPlay muted playsInline className="h-full w-full -scale-x-100 object-cover" />}
        {kind === "video" && state === "done" && result && <video src={result.url} controls playsInline className="h-full w-full bg-black object-contain" />}
        {kind === "audio" && state === "done" && result && (
          <div className="flex h-full items-center justify-center px-6">
            <audio src={result.url} controls className="w-full" />
          </div>
        )}
        {kind === "audio" && state !== "done" && state !== "idle" && (
          <div className="flex h-full items-center justify-center gap-1">
            {Array.from({ length: 24 }, (_, i) => (
              <span key={i} className="w-1.5 rounded-full bg-brand-500 transition-all" style={{ height: `${8 + level * 100 * (0.4 + Math.abs(Math.sin(i * 1.7)) * 0.6)}px` }} />
            ))}
          </div>
        )}

        {state === "idle" && (
          <div className="absolute inset-0 grid place-items-center">
            <Button onClick={enable}>{kind === "video" ? "Włącz kamerę" : "Włącz mikrofon"}</Button>
          </div>
        )}
        {state === "countdown" && <div className="absolute inset-0 grid place-items-center bg-black/40 text-7xl font-bold text-white">{countdown}</div>}
        {state === "recording" && (
          <div className="absolute left-3 top-3 flex items-center gap-2 rounded-full bg-black/60 px-3 py-1 text-sm font-medium text-white">
            <span className="rec-dot h-2.5 w-2.5 rounded-full bg-red-500" />
            {fmt(elapsed)} / {fmt(maxSeconds)}
          </div>
        )}
        {(state === "ready" || state === "recording") && (
          <div className="absolute bottom-3 left-3 h-1.5 w-28 overflow-hidden rounded-full bg-white/20">
            <div className="h-full bg-emerald-400 transition-all" style={{ width: `${Math.min(100, level * 180)}%` }} />
          </div>
        )}
      </div>

      {teleprompter && (state === "ready" || state === "countdown" || state === "recording") && (
        <div>
          <div ref={promptRef} className="teleprompter h-36 overflow-y-auto rounded-xl bg-zinc-900 px-6 py-10 text-center text-xl leading-relaxed text-white">
            {teleprompter}
            <div className="h-24" />
          </div>
          <label className="mt-2 flex items-center gap-2 text-xs text-zinc-500">
            Tempo promptera
            <input type="range" min={0} max={5} step={0.5} value={speed} onChange={(e) => setSpeed(Number(e.target.value))} />
          </label>
        </div>
      )}

      <div className="flex flex-wrap items-center gap-2">
        {state === "ready" && <Button onClick={begin}>● Nagrywaj</Button>}
        {state === "recording" && (
          <Button variant="danger" onClick={stop} disabled={tooShort}>
            ■ Zakończ {tooShort && `(min. ${minSeconds} s)`}
          </Button>
        )}
        {state === "done" && (
          <>
            <span className="text-sm text-emerald-700">✓ Nagrano {fmt(result?.seconds ?? 0)}</span>
            <Button variant="secondary" onClick={reset}>
              Nagraj ponownie
            </Button>
          </>
        )}
      </div>
      {error && <p className="text-sm text-red-600">{error}</p>}
    </div>
  );
}

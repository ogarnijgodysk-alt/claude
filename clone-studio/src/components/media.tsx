"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import type { AspectRatio, Clone, Video } from "@/lib/types";
import { cx } from "./ui";

export function fileSrc(name?: string) {
  return name ? `/api/files/${name}` : undefined;
}

export const ASPECT_CLASS: Record<AspectRatio, string> = {
  "9:16": "aspect-[9/16]",
  "1:1": "aspect-square",
  "16:9": "aspect-video",
};

const KIND_LABEL: Record<Clone["kind"], string> = {
  digital_twin: "Cyfrowy bliźniak",
  photo_clone: "Klon ze zdjęć",
  ai_influencer: "AI influencer",
};

export function kindLabel(kind: Clone["kind"]) {
  return KIND_LABEL[kind];
}

export function CloneAvatar({ clone, className }: { clone?: Clone; className?: string }) {
  if (!clone?.coverFile) return <div className={cx("bg-gradient-to-br from-brand-200 to-pink-200", className)} />;
  // eslint-disable-next-line @next/next/no-img-element
  return <img src={fileSrc(clone.coverFile)} alt={clone.name} className={cx("object-cover", className)} />;
}

function sentences(text: string) {
  return text.match(/[^.!?…]+[.!?…]*/g)?.map((s) => s.trim()).filter(Boolean) ?? [text];
}

/**
 * "width" fills the container (grid cells); "height" gives vertical videos a fixed
 * height so they don't grow huge in wide columns or collapse to zero width in flex columns.
 */
export function sizeClass(aspect: AspectRatio, fit: "width" | "height") {
  if (fit === "height" && aspect === "9:16") return "mx-auto h-[440px] max-h-[70vh] max-w-full";
  if (fit === "height" && aspect === "1:1") return "mx-auto w-full max-w-[440px]";
  return "w-full";
}

/**
 * Finished renders play as real video. Demo renders (no HeyGen key) are simulated:
 * the clone's photo with gentle motion and captions paced to the script (or to the recorded audio).
 */
export function VideoPreview({ video, clone, fit = "width" }: { video: Video; clone?: Clone; fit?: "width" | "height" }) {
  const size = sizeClass(video.aspectRatio, fit);
  if (video.videoUrl) {
    return <video src={video.videoUrl} poster={video.thumbnailUrl} controls playsInline className={cx("rounded-xl bg-black object-contain", ASPECT_CLASS[video.aspectRatio], size)} />;
  }
  return <DemoPlayer video={video} clone={clone} className={size} />;
}

function DemoPlayer({ video, clone, className }: { video: Video; clone?: Clone; className?: string }) {
  const lines = useMemo(() => sentences(video.script || "…"), [video.script]);
  const [playing, setPlaying] = useState(false);
  const [idx, setIdx] = useState(0);
  const audioRef = useRef<HTMLAudioElement>(null);
  const ready = video.status === "ready";

  useEffect(() => {
    if (!playing) return;
    if (video.audioFile) {
      const a = audioRef.current;
      if (!a) return;
      const onTime = () => a.duration && setIdx(Math.min(lines.length - 1, Math.floor((a.currentTime / a.duration) * lines.length)));
      const onEnd = () => setPlaying(false);
      a.addEventListener("timeupdate", onTime);
      a.addEventListener("ended", onEnd);
      a.play();
      return () => {
        a.pause();
        a.removeEventListener("timeupdate", onTime);
        a.removeEventListener("ended", onEnd);
      };
    }
    // ~2.5 words per second of speech
    let i = 0;
    setIdx(0);
    let timer: ReturnType<typeof setTimeout>;
    const next = () => {
      const words = lines[i]?.split(/\s+/).length ?? 4;
      timer = setTimeout(() => {
        i += 1;
        if (i >= lines.length) return setPlaying(false);
        setIdx(i);
        next();
      }, (words / 2.5) * 1000);
    };
    next();
    return () => clearTimeout(timer);
  }, [playing, lines, video.audioFile]);

  return (
    <div className={cx("relative overflow-hidden rounded-xl bg-zinc-900", ASPECT_CLASS[video.aspectRatio], className)}>
      <CloneAvatar clone={clone} className={cx("absolute inset-0 h-full w-full transition-transform duration-[4000ms]", playing && "scale-110")} />
      <div className="absolute inset-0 bg-gradient-to-t from-black/70 via-transparent to-black/20" />
      <span className="absolute left-2 top-2 rounded-full bg-black/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">
        {video.demo ? "podgląd demo" : "renderowanie…"}
      </span>
      {video.audioFile && <audio ref={audioRef} src={fileSrc(video.audioFile)} />}
      {playing ? (
        <p key={idx} className="caption-in absolute inset-x-3 bottom-4 text-center text-sm font-semibold leading-snug text-white drop-shadow md:text-base">
          {lines[idx]}
        </p>
      ) : (
        ready && (
          <button onClick={() => setPlaying(true)} className="absolute inset-0 grid place-items-center" aria-label="Odtwórz">
            <span className="grid h-14 w-14 place-items-center rounded-full bg-white/90 text-xl text-zinc-900 shadow-lg">▶</span>
          </button>
        )
      )}
      {!ready && video.status === "processing" && (
        <div className="absolute inset-0 grid place-items-center">
          <span className="h-10 w-10 animate-spin rounded-full border-4 border-white/30 border-t-white" />
        </div>
      )}
    </div>
  );
}

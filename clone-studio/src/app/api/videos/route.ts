import { readDb } from "@/lib/db";
import { fail, file, ok, str } from "@/lib/http";
import { createCloneVideo, refreshVideo } from "@/lib/services/videos";
import { PLATFORMS, type Platform } from "@/lib/types";

export const dynamic = "force-dynamic";
export const maxDuration = 120;

export async function GET() {
  const { videos } = await readDb();
  const fresh = await Promise.all(videos.map((v) => (v.status === "processing" ? refreshVideo(v.id) : v)));
  return ok(fresh.filter(Boolean));
}

export async function POST(req: Request) {
  try {
    const form = await req.formData();
    const platform = str(form, "platform") as Platform;
    const video = await createCloneVideo({
      cloneId: str(form, "cloneId"),
      title: str(form, "title"),
      platform: platform in PLATFORMS ? platform : "tiktok",
      mode: str(form, "mode") === "voice" ? "voice" : "script",
      script: str(form, "script"),
      audio: file(form, "audio"),
      caption: str(form, "caption"),
      hashtags: str(form, "hashtags").split(/[\s,]+/),
    });
    return ok(video, { status: 201 });
  } catch (err) {
    return fail(err);
  }
}

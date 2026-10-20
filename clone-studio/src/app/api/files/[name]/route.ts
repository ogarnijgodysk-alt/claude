import { isSafeName, readStored } from "@/lib/files";

export const dynamic = "force-dynamic";

/** Serves stored uploads, with byte ranges so <video> can seek (required by Safari). */
export async function GET(req: Request, { params }: { params: Promise<{ name: string }> }) {
  const { name } = await params;
  if (!isSafeName(name)) return new Response("Not found", { status: 404 });
  let stored;
  try {
    stored = await readStored(name);
  } catch {
    return new Response("Not found", { status: 404 });
  }
  const { data, type } = stored;
  const headers: Record<string, string> = {
    "content-type": type,
    "accept-ranges": "bytes",
    "cache-control": "private, max-age=31536000, immutable",
    // Uploaded SVGs must never run script in our origin.
    "content-security-policy": "default-src 'none'; style-src 'unsafe-inline'; sandbox",
    "x-content-type-options": "nosniff",
  };

  const range = req.headers.get("range")?.match(/^bytes=(\d*)-(\d*)$/);
  if (range && (range[1] || range[2])) {
    const size = data.length;
    let start = range[1] ? Number(range[1]) : size - Number(range[2]);
    let end = range[1] && range[2] ? Number(range[2]) : size - 1;
    start = Math.max(0, start);
    end = Math.min(end, size - 1);
    if (start > end) return new Response(null, { status: 416, headers: { "content-range": `bytes */${size}` } });
    return new Response(new Uint8Array(data.subarray(start, end + 1)), {
      status: 206,
      headers: { ...headers, "content-range": `bytes ${start}-${end}/${size}`, "content-length": String(end - start + 1) },
    });
  }
  return new Response(new Uint8Array(data), { headers: { ...headers, "content-length": String(data.length) } });
}

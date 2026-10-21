import { promises as fs } from "fs";
import path from "path";
import type { Brand, Database } from "./types";

export const DATA_DIR = path.resolve(/*turbopackIgnore: true*/ process.env.DATA_DIR || ".data");
const DB_FILE = path.join(DATA_DIR, "db.json");

const DEFAULT_BRAND: Brand = {
  name: "",
  industry: "",
  description: "",
  audience: "",
  tone: "Przyjazny, ekspercki, konkretny",
  language: "polski",
  ctas: "",
  bannedWords: "",
  aiDisclosure: true,
  updatedAt: new Date(0).toISOString(),
};

function emptyDb(): Database {
  return { brand: { ...DEFAULT_BRAND }, clones: [], videos: [], photos: [], assistants: [] };
}

async function load(): Promise<Database> {
  try {
    const raw = await fs.readFile(DB_FILE, "utf8");
    return { ...emptyDb(), ...JSON.parse(raw) };
  } catch (err) {
    if ((err as NodeJS.ErrnoException).code === "ENOENT") return emptyDb();
    throw err;
  }
}

async function save(db: Database) {
  await fs.mkdir(DATA_DIR, { recursive: true });
  const tmp = `${DB_FILE}.${process.pid}.tmp`;
  await fs.writeFile(tmp, JSON.stringify(db, null, 2));
  await fs.rename(tmp, DB_FILE);
}

// Serialize all access so concurrent requests never interleave read-modify-write.
let queue: Promise<unknown> = Promise.resolve();

export function readDb(): Promise<Database> {
  const next = queue.then(load);
  queue = next.catch(() => undefined);
  return next;
}

export function updateDb<T>(fn: (db: Database) => T | Promise<T>): Promise<T> {
  const next = queue.then(async () => {
    const db = await load();
    const result = await fn(db);
    await save(db);
    return result;
  });
  queue = next.catch(() => undefined);
  return next;
}

export function newId(prefix: string) {
  return `${prefix}_${crypto.randomUUID().replace(/-/g, "").slice(0, 16)}`;
}

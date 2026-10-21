// Object URLs for files kept in the browser (browser-storage mode). Filled by the store at start-up,
// read synchronously by components that render <img>/<video>.
const urls = new Map<string, string>();

export function registerFile(name: string, blob: Blob) {
  const old = urls.get(name);
  if (old) URL.revokeObjectURL(old);
  urls.set(name, URL.createObjectURL(blob));
}

export function browserFileUrl(name: string) {
  return urls.get(name);
}

export function forgetFile(name: string) {
  const old = urls.get(name);
  if (old) URL.revokeObjectURL(old);
  urls.delete(name);
}

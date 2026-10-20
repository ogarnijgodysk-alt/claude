import { hasClaude } from "./claude";
import { hasElevenLabs } from "./elevenlabs";
import { hasFal } from "./fal";
import { hasHeygen } from "./heygen";

export function providerStatus() {
  return {
    claude: hasClaude(),
    heygen: hasHeygen(),
    elevenlabs: hasElevenLabs(),
    fal: hasFal(),
    publicUrl: Boolean(process.env.PUBLIC_BASE_URL),
  };
}

export type ProviderStatus = ReturnType<typeof providerStatus>;

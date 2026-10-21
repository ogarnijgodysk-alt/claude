import { hasClaude } from "./claude";
import { hasElevenLabs } from "./elevenlabs";
import { hasFal } from "./fal";
import { hasHeygen } from "./heygen";
import { hasKie } from "./kie";
import { hasTavus } from "./tavus";

export function providerStatus() {
  return {
    claude: hasClaude(),
    heygen: hasHeygen(),
    elevenlabs: hasElevenLabs(),
    kie: hasKie(),
    fal: hasFal(),
    tavus: hasTavus(),
    publicUrl: Boolean(process.env.PUBLIC_BASE_URL),
    /** Data lives in the visitor's browser (public test deployment / claude.ai preview). */
    browser: false as boolean,
  };
}

export type ProviderStatus = ReturnType<typeof providerStatus>;

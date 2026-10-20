"use client";

import Link from "next/link";
import { usePolling } from "@/components/api";
import { CloneAvatar, kindLabel } from "@/components/media";
import { DemoBadge, Empty, ErrorNote, LinkButton, PageHeader, StatusBadge } from "@/components/ui";
import type { Clone } from "@/lib/types";

export default function ClonesPage() {
  const { data: clones, error } = usePolling<Clone[]>("/api/clones", (cs) => cs.some((c) => c.status === "processing" || c.loraStatus === "processing"), 6000);

  return (
    <>
      <PageHeader title="Klony AI" subtitle="Cyfrowe kopie Ciebie, Twojego zespołu albo wirtualni ambasadorzy marki." actions={<LinkButton href="/klony/nowy">+ Nowy klon</LinkButton>} />
      <ErrorNote error={error} />
      {clones && clones.length === 0 && (
        <Empty title="Nie masz jeszcze żadnego klona">
          <LinkButton href="/klony/nowy">Stwórz pierwszego klona</LinkButton>
        </Empty>
      )}
      <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
        {clones?.map((c) => (
          <Link key={c.id} href={`/klony/${c.id}`} className="group overflow-hidden rounded-2xl bg-white ring-1 ring-zinc-200/70 transition hover:-translate-y-0.5 hover:shadow-md">
            <div className="relative">
              <CloneAvatar clone={c} className="aspect-[4/5] w-full" />
              <div className="absolute left-2 top-2 flex gap-1">{c.demo && <DemoBadge />}</div>
            </div>
            <div className="space-y-1 p-3">
              <p className="truncate font-medium">{c.name}</p>
              <p className="text-xs text-zinc-500">{kindLabel(c.kind)}</p>
              <StatusBadge status={c.status} label={c.status === "processing" ? "Trenuje się…" : undefined} />
            </div>
          </Link>
        ))}
      </div>
    </>
  );
}

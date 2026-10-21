"use client";

import { useParams } from "next/navigation";
import { WidgetPanel } from "@/components/WidgetPanel";

/** Full-screen conversation page; embed.js opens it in an iframe on the customer's website. */
export default function WidgetPage() {
  const { id } = useParams<{ id: string }>();
  return (
    <div className="flex h-dvh justify-center bg-zinc-100 sm:items-center sm:p-6">
      <WidgetPanel assistantId={id} className="w-full overflow-hidden sm:h-[640px] sm:max-w-md sm:rounded-2xl sm:shadow-xl" />
    </div>
  );
}

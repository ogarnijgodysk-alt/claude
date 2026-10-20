import type { Metadata } from "next";
import { Shell } from "@/components/Shell";
import "./globals.css";

export const metadata: Metadata = {
  title: "CloneStudio — klony AI i wideo dla firm",
  description: "Twórz cyfrowe klony i wirtualnych influencerów, a potem nagrywaj nimi treści do social mediów.",
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="pl">
      <body className="antialiased">
        <Shell>{children}</Shell>
      </body>
    </html>
  );
}

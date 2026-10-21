"use client";

import { useEffect, useState } from "react";
import { appendText, DictateButton } from "@/components/DictateButton";
import { api } from "@/components/api";
import { Button, Card, ErrorNote, Field, Input, PageHeader, Textarea } from "@/components/ui";
import type { Brand } from "@/lib/types";

export default function BrandPage() {
  const [brand, setBrand] = useState<Brand | null>(null);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api<Brand>("/api/brand").then(setBrand, (e) => setError(e.message));
  }, []);

  if (!brand) return <ErrorNote error={error} />;

  const set = <K extends keyof Brand>(k: K) => (v: Brand[K]) => {
    setBrand({ ...brand, [k]: v });
    setSaved(false);
  };

  async function save() {
    setSaving(true);
    setError(null);
    try {
      setBrand(await api<Brand>("/api/brand", { method: "PUT", json: brand }));
      setSaved(true);
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      setSaving(false);
    }
  }

  return (
    <>
      <PageHeader title="Profil marki" subtitle="AI korzysta z tych informacji przy każdym skrypcie, opisie i planie treści. Im konkretniej, tym lepsze teksty." />
      <Card className="space-y-5">
        <div className="grid gap-4 md:grid-cols-2">
          <Field label="Nazwa firmy">
            <Input value={brand.name} onChange={(e) => set("name")(e.target.value)} placeholder="np. Kawiarnia Ziarno" />
          </Field>
          <Field label="Branża">
            <Input value={brand.industry} onChange={(e) => set("industry")(e.target.value)} placeholder="np. gastronomia, nieruchomości, e-commerce" />
          </Field>
        </div>
        <Field label="Czym się zajmujecie i co sprzedajecie" hint="Produkty, usługi, wyróżniki, fakty, które AI może bezpiecznie powtarzać.">
          <Textarea value={brand.description} onChange={(e) => set("description")(e.target.value)} rows={4} />
        </Field>
        <DictateButton onText={(t) => set("description")(appendText(brand.description, t))} />
        <Field label="Grupa docelowa">
          <Textarea value={brand.audience} onChange={(e) => set("audience")(e.target.value)} rows={2} placeholder="np. właściciele małych firm 30–50 lat, którzy nie mają czasu na marketing" />
        </Field>
        <div className="grid gap-4 md:grid-cols-2">
          <Field label="Ton komunikacji">
            <Input value={brand.tone} onChange={(e) => set("tone")(e.target.value)} />
          </Field>
          <Field label="Język treści">
            <Input value={brand.language} onChange={(e) => set("language")(e.target.value)} placeholder="polski" />
          </Field>
        </div>
        <Field label="Wezwania do działania (CTA)" hint="np. Umów darmową konsultację, link w bio, kod rabatowy ZIARNO10">
          <Textarea value={brand.ctas} onChange={(e) => set("ctas")(e.target.value)} rows={2} />
        </Field>
        <Field label="Czego unikać" hint="Słowa, tematy i obietnice, których AI ma nie używać.">
          <Textarea value={brand.bannedWords} onChange={(e) => set("bannedWords")(e.target.value)} rows={2} />
        </Field>
        <label className="flex items-start gap-3 rounded-xl bg-zinc-50 p-4 text-sm">
          <input type="checkbox" checked={brand.aiDisclosure} onChange={(e) => set("aiDisclosure")(e.target.checked)} className="mt-0.5" />
          <span>
            <b>Oznaczaj treści jako wygenerowane przez AI.</b> Do opisów postów dopisujemy krótką informację. Unijny AI Act (art. 50) nakłada obowiązek oznaczania treści typu deepfake — zalecamy zostawić włączone.
          </span>
        </label>
        <ErrorNote error={error} />
        <div className="flex items-center gap-3">
          <Button onClick={save} loading={saving}>
            Zapisz profil
          </Button>
          {saved && <span className="text-sm text-emerald-700">✓ Zapisano</span>}
        </div>
      </Card>
    </>
  );
}

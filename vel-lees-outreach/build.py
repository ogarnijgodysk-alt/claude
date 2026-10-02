"""Builds the outreach page, CSV and Markdown from emails.json."""
import csv
import json
from pathlib import Path

HERE = Path(__file__).parent
NAME = "Mark"
SITE = "vellees.com"

source = json.loads((HERE / "emails.json").read_text(encoding="utf-8"))
people = source["people"]
# Shared paragraphs ({product}, {quote}) are expanded here; {name} stays for the page's sender field.
for p in people:
    for key, text in source["snippets"].items():
        p["body"] = p["body"].replace("{" + key + "}", text)


def full_text(p):
    body = p["body"].replace("{name}", NAME)
    return f'{p["greeting"]}\n\n{body}\n\n{p["signoff"]},\n{NAME}\nVEL LEES\n{SITE}'


template = (HERE / "template.html").read_text(encoding="utf-8")
data = json.dumps(people, ensure_ascii=False, indent=1).replace("</", "<\\/")
(HERE / "vel-lees-outreach.html").write_text(template.replace("/*DATA*/", data), encoding="utf-8")

with open(HERE / "maile.csv", "w", newline="", encoding="utf-8") as f:
    w = csv.writer(f)
    w.writerow(["#", "Kanał", "Handle", "Email", "Temat", "Treść", "Uwagi"])
    for p in people:
        w.writerow([p["row"], p["channel"], p["handle"], p["email"], p["subject"], full_text(p), p.get("flag", "")])

lines = ["# VEL LEES – maile do twórców USA", "",
         f"{len(people)} maili dla kanałów z adresem w arkuszu `pipeline_tworcy_usa_vellees`. "
         f"Podpis: {NAME}.", ""]
for p in people:
    lines += [f'## #{p["row"]} {p["channel"]} ({p["handle"]})', "",
              f'**Do:** {p["email"]}  ', f'**Temat:** {p["subject"]}', ""]
    if p.get("flag"):
        lines += [f'> Uwaga: {p["flag"]}', ""]
    lines += ["```", full_text(p), "```", ""]
(HERE / "maile.md").write_text("\n".join(lines), encoding="utf-8")

print(f"built {len(people)} emails")

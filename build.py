#!/usr/bin/env python3
"""Fabrique index.html (site autonome breizh.tribuneouest.fr) à partir de la page Tribune Breizh publiée sur Claude.

Usage : python3 build.py <page_artifact.html>
Le fichier d'entrée est la page telle que l'outil Artifact la renvoie (avec ou sans l'enveloppe <!doctype>…<body> ajoutée par Claude).
"""
import re
import sys
from pathlib import Path

HEAD = """<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="google-site-verification" content="zlcjk-kSuIzvyjDgvaxEaBcJ_vQKCQsjVimZx1mhN3E">
<meta name="description" content="Tribune Breizh : tout le sport breton, club par club. Résultats, classements, agenda TV, foot amateur, Route du Rhum et fiches de tous les clubs de Bretagne, mis à jour chaque jour.">
<meta property="og:title" content="Tribune Breizh · le sport breton, club par club">
<meta property="og:description" content="Résultats, classements, agenda TV et fiches clubs : tout le sport breton, mis à jour chaque jour.">
<meta property="og:type" content="website">
<meta property="og:url" content="https://breizh.tribuneouest.fr/">
<meta property="og:locale" content="fr_FR">
<meta property="og:site_name" content="Tribune Breizh">
<meta property="og:image" content="https://breizh.tribuneouest.fr/og.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Tribune Breizh, le sport breton club par club">
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="#121417">
<link rel="canonical" href="https://breizh.tribuneouest.fr/">
<link rel="preload" href="/fonts/big-shoulders-display.woff" as="font" type="font/woff" crossorigin>
<link rel="preload" href="/fonts/archivo.woff" as="font" type="font/woff" crossorigin>
<style>
/* Polices hébergées sur le site (aucun appel à Google) · licence SIL OFL, voir /fonts */
@font-face{font-family:"Big Shoulders Display";src:url("/fonts/big-shoulders-display.woff") format("woff");font-weight:600 900;font-display:swap}
@font-face{font-family:"Archivo";src:url("/fonts/archivo.woff") format("woff");font-weight:400 700;font-display:swap}
</style>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 90 60'%3E%3Crect width='90' height='60' fill='%23121417'/%3E%3Crect y='6.67' width='90' height='6.67' fill='%23fff'/%3E%3Crect y='20' width='90' height='6.67' fill='%23fff'/%3E%3Crect y='33.33' width='90' height='6.67' fill='%23fff'/%3E%3Crect y='46.67' width='90' height='6.67' fill='%23fff'/%3E%3Crect width='40' height='33.33' fill='%23fff'/%3E%3C/svg%3E">
<style>:root{color-scheme:light;box-sizing:border-box;padding-top:env(safe-area-inset-top,0px);padding-bottom:env(safe-area-inset-bottom,0px)}html{scroll-padding-top:env(safe-area-inset-top,0px)}body{margin:0;padding:0;font:14px -apple-system,BlinkMacSystemFont,sans-serif;background:#fff;color:#000}img{max-width:100%}[hidden]:not([hidden=until-found i]){display:none!important}</style>
"""


def strip_skeleton(s: str) -> str:
    """Retire l'enveloppe ajoutée par Claude (doctype, head minimal, body) si elle est présente."""
    m = re.match(r"\s*<!doctype html>.*?<body>\s*", s, re.S | re.I)
    if m:
        s = s[m.end():]
    s = re.sub(r"\s*</body>\s*</html>\s*$", "", s, flags=re.I)
    return s


def main() -> None:
    src = Path(sys.argv[1]).read_text(encoding="utf-8")
    body = strip_skeleton(src)
    # Le <title>, la balise viewport-fit et les feuilles de style vont dans <head>
    head_bits = []
    for pat in (r"<title>.*?</title>", r'<meta name="claude:viewport-fit-sides"[^>]*>'):
        m = re.search(pat, body, re.S)
        if m:
            head_bits.append(m.group(0))
            body = body.replace(m.group(0), "", 1)
    # Les polices Google sont remplacées par celles hébergées sur le site (voir HEAD)
    body = re.sub(r'<link rel="preconnect" href="https://fonts\.googleapis\.com"[^>]*>\s*', "", body)
    body = re.sub(r'<link rel="stylesheet" href="https://fonts\.googleapis\.com[^>]*>\s*', "", body)
    head_bits = [b.replace("<title>Tribune Breizh</title>", "<title>Tribune Breizh · le sport breton, club par club</title>") for b in head_bits]
    out = HEAD + "\n".join(head_bits) + "\n</head>\n<body>\n" + body.strip() + "\n</body>\n</html>\n"
    if "WXvNyWF5ypo7PuAsgFRJp6" not in out or "const DATA=" not in out:
        sys.exit("Page source inattendue : index.html non généré")
    Path(__file__).with_name("index.html").write_text(out, encoding="utf-8")
    print("index.html généré :", len(out), "octets")


if __name__ == "__main__":
    main()

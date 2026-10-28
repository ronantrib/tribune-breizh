#!/usr/bin/env python3
"""Tribune Breizh · file d'attente des posts Instagram (insta/queue.json).

Utilisé par les tâches automatiques, depuis la racine du dépôt :
  python3 bot/queue.py add ID QUAND TYPE LEGENDE.txt image1.jpg [image2.jpg ...]
      ID     : identifiant unique, ex. 2026-11-02-resultats (réutiliser un ID remplace le post)
      QUAND  : date et heure de Paris, ex. 2026-11-02T12:30
      TYPE   : feed (une image), carousel (2 à 10 images) ou story (une image 1080x1920)
      LEGENDE.txt : fichier texte de la légende (ignoré pour une story : mettre -)
  python3 bot/queue.py cancel ID      annule un post pas encore publié
  python3 bot/queue.py prune          retire les posts de plus de 7 jours et leurs images
  python3 bot/queue.py list           affiche la file
La publication elle-même est faite par bot/publish.php sur o2switch.
"""
import json, os, re, shutil, struct, sys
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
QF = os.path.join(ROOT, "insta", "queue.json")
IMG = os.path.join(ROOT, "insta", "img")
PARIS = ZoneInfo("Europe/Paris")


def load():
    try:
        with open(QF, encoding="utf-8") as f:
            return json.load(f)
    except FileNotFoundError:
        return {"posts": []}


def save(q):
    q["posts"].sort(key=lambda p: p["at"])
    os.makedirs(os.path.dirname(QF), exist_ok=True)
    with open(QF, "w", encoding="utf-8") as f:
        json.dump(q, f, ensure_ascii=False, indent=1)
        f.write("\n")


def jpeg_size(path):
    with open(path, "rb") as f:
        data = f.read()
    if data[:2] != b"\xff\xd8":
        raise SystemExit(f"{path} n'est pas un JPEG.")
    i = 2
    while i < len(data):
        if data[i] != 0xFF:
            i += 1
            continue
        m = data[i + 1]
        if m in (0xC0, 0xC1, 0xC2):
            h, w = struct.unpack(">HH", data[i + 5:i + 9])
            return w, h
        i += 2 + struct.unpack(">H", data[i + 2:i + 4])[0]
    raise SystemExit(f"{path} : dimensions illisibles.")


def when(s):
    d = datetime.fromisoformat(s)
    if d.tzinfo is None:
        d = d.replace(tzinfo=PARIS)
    return d


def add(pid, at, typ, capf, imgs):
    if not re.fullmatch(r"[a-z0-9-]{3,60}", pid):
        raise SystemExit("ID : lettres minuscules, chiffres et tirets seulement.")
    if typ not in ("feed", "carousel", "story"):
        raise SystemExit("TYPE : feed, carousel ou story.")
    d = when(at)
    if d < datetime.now(PARIS) + timedelta(minutes=20):
        raise SystemExit("QUAND doit être au moins 20 minutes dans le futur (le serveur récupère la file toutes les 10 minutes).")
    n = len(imgs)
    if (typ == "carousel" and not 2 <= n <= 10) or (typ != "carousel" and n != 1):
        raise SystemExit("Nombre d'images incorrect pour ce type.")
    for p in imgs:
        w, h = jpeg_size(p)
        want = (1080, 1920) if typ == "story" else (1080, 1080)
        if (w, h) != want:
            raise SystemExit(f"{p} fait {w}x{h}, attendu {want[0]}x{want[1]}.")
    cap = ""
    if typ != "story":
        with open(capf, encoding="utf-8") as f:
            cap = f.read().strip()
        if not cap:
            raise SystemExit("Légende vide.")
        if len(cap) > 2200:
            raise SystemExit("Légende trop longue (2200 caractères au plus).")
        if len(re.findall(r"#\w", cap)) > 30:
            raise SystemExit("Trop de hashtags (30 au plus).")
    q = load()
    old = [p for p in q["posts"] if p["id"] == pid]
    for p in old:
        for f in p.get("images", []):
            fp = os.path.join(ROOT, f)
            if os.path.exists(fp):
                os.remove(fp)
    q["posts"] = [p for p in q["posts"] if p["id"] != pid]
    os.makedirs(IMG, exist_ok=True)
    rel = []
    for k, p in enumerate(imgs, 1):
        name = f"{pid}-{k}.jpg" if n > 1 else f"{pid}.jpg"
        shutil.copyfile(p, os.path.join(IMG, name))
        rel.append(f"insta/img/{name}")
    post = {"id": pid, "at": d.isoformat(timespec="minutes"), "type": typ, "images": rel}
    if cap:
        post["caption"] = cap
    q["posts"].append(post)
    save(q)
    print(f"Ajouté : {pid} le {d.strftime('%d/%m à %Hh%M')} ({typ}, {n} image(s)).")


def cancel(pid):
    q = load()
    hit = False
    for p in q["posts"]:
        if p["id"] == pid:
            p["cancel"] = True
            hit = True
    if not hit:
        raise SystemExit(f"Aucun post {pid}.")
    save(q)
    print(f"Annulé : {pid}")


def prune():
    q = load()
    limit = datetime.now(PARIS) - timedelta(days=7)
    keep = [p for p in q["posts"] if when(p["at"]) >= limit]
    used = {f for p in keep for f in p.get("images", [])}
    q["posts"] = keep
    save(q)
    gone = 0
    if os.path.isdir(IMG):
        for f in os.listdir(IMG):
            if f.endswith(".jpg") and f"insta/img/{f}" not in used:
                os.remove(os.path.join(IMG, f))
                gone += 1
    print(f"File : {len(keep)} post(s) gardé(s), {gone} image(s) supprimée(s).")


def show():
    for p in load()["posts"]:
        flag = " (annulé)" if p.get("cancel") else ""
        print(f"{p['at']}  {p['type']:8} {p['id']}{flag}")


if __name__ == "__main__":
    a = sys.argv[1:]
    if not a:
        print(__doc__)
    elif a[0] == "add" and len(a) >= 6:
        add(a[1], a[2], a[3], a[4], a[5:])
    elif a[0] == "cancel" and len(a) == 2:
        cancel(a[1])
    elif a[0] == "prune":
        prune()
    elif a[0] == "list":
        show()
    else:
        print(__doc__)
        sys.exit(2)

# Tribune Breizh

Site autonome de Tribune Breizh, servi sur https://breizh.tribuneouest.fr (hébergement o2switch).

- `index.html` : la page du site, générée par `build.py` à partir de la version publiée sur Claude (https://claude.ai/artifact/WXvNyWF5ypo7PuAsgFRJp6). Ne pas la modifier à la main : elle est régénérée chaque nuit.
- `build.py` : `python3 build.py page_artifact.html` produit `index.html`.
- `mentions-legales.html`, `robots.txt`, `.htaccess` : fichiers fixes.

Chaque nuit, la tâche de mise à jour publie la page sur Claude, régénère `index.html` et l'enregistre ici. Chez o2switch, une tâche cron fait `git pull` toutes les 10 minutes dans le dossier du sous-domaine.

## Instagram (publication automatique)

- `bot/render.js` : `node bot/render.js . /tmp/vis [ids]` dessine les visuels du site en JPEG 1080×1080 (story 1080×1920).
- `bot/queue.py` : ajoute, annule ou nettoie les posts de `insta/queue.json` ; les images vont dans `insta/img/`.
- `bot/publish.php` : lancé par le cron d'o2switch, publie chaque post à son heure via l'API Instagram. Le jeton et l'historique sont dans `~/insta/` sur le serveur, jamais dans ce dépôt. Le dossier `bot/` n'est pas servi sur le web.
- `bot/card.js` : `node bot/card.js carte.json sortie.jpg` met en forme une carte 1080×1350 (titre, texte, petit tableau) aux couleurs du site, pour les posts des jours libres.
- `bot/data.js` : `node bot/data.js` affiche en JSON l'essentiel des données du site (affiche, Rhum, agenda 7 jours, brèves…), pour que la tâche Instagram n'ait pas à lire toute la page.

## Ouverture du site (page d'attente)

Jusqu'au vendredi 30 octobre 2026 à 12h00 (Paris), `gate.php` affiche `attente.html` (compte à rebours) à la place du site ;
ensuite il sert `index.html` tel quel. Aperçu du vrai site avant l'ouverture : `https://breizh.tribuneouest.fr/?apercu=CLE`
(la clé est dans `gate.php`). Les fichiers `index.html`, `build.py` et la chaîne de mise à jour ne changent pas.
Pour retirer le mécanisme après l'ouverture : dans `.htaccess`, remettre `DirectoryIndex index.html` et `ErrorDocument 404 /index.html`,
et supprimer la règle `RewriteRule ^index\.html$ / …`.

## Envoi du site (depuis le 28 octobre 2026)

Les tâches automatiques n'exécutent plus aucun script : elles déposent la page publiée sur Claude telle quelle
dans `src/index.html` (outil Artifact, `out_dir` = dossier `src` du dépôt), puis la poussent sur GitHub.
Sur le serveur, `gate.php` fabrique la page du site à partir de `src/index.html` (fonction de `bot/build.php`,
même transformation que `build.py`) et la garde dans `cache/` (ignoré par Git, inaccessible depuis le web).
`index.html` à la racine n'est plus qu'une page de secours. `build.py` reste disponible pour un usage manuel.

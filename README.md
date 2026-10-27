# Tribune Breizh

Site autonome de Tribune Breizh, servi sur https://breizh.tribuneouest.fr (hébergement o2switch).

- `index.html` : la page du site, générée par `build.py` à partir de la version publiée sur Claude (https://claude.ai/artifact/WXvNyWF5ypo7PuAsgFRJp6). Ne pas la modifier à la main : elle est régénérée chaque nuit.
- `build.py` : `python3 build.py page_artifact.html` produit `index.html`.
- `mentions-legales.html`, `robots.txt`, `.htaccess` : fichiers fixes.

Chaque nuit, la tâche de mise à jour publie la page sur Claude, régénère `index.html` et l'enregistre ici. Chez o2switch, une tâche cron fait `git pull` toutes les 10 minutes dans le dossier du sous-domaine.

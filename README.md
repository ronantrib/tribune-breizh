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

## Nettoyage automatique des fichiers publiés (depuis le 4 novembre 2026)

À la fin de chaque passage, `bot/publish.php` supprime sur le serveur les fichiers de `insta/img/`, `insta/reels/` et `insta/gen/`
(`images`, `video`, `cover`) des posts terminés (`bot/cleanup.php`). Un post est « terminé » quand :
- Instagram l'a publié (statut « publié » dans `~/insta/done.json`) : un post « abandonné » ou « à vérifier » garde ses fichiers pour l'enquête ;
- s'il a un volet Facebook (et que Facebook est configuré), Facebook a fini aussi (« publié » ou « abandonné » dans `fb_done.json`) ;
- 1 h s'est écoulée depuis (`CLEAN_DELAY`) ;
- aucun autre post pas encore terminé n'utilise le même fichier.

Les cartes dessinées par le serveur (`insta/gen/ID-N-….jpg`) partent avec leur post (gardées si un post pas fini a un id qui ressemble). `insta/queue.json` et `.gitkeep` ne sont jamais touchés. Chaque suppression est notée dans `~/insta/log.txt`.
Pour voir ce qui serait supprimé sans rien supprimer : `php ~/repositories/tribune-breizh/bot/publish.php nettoyage` (terminal cPanel).
Côté dépôt GitHub, rien n'est supprimé automatiquement : `python3 bot/queue.py prune` retire les posts de plus de 7 jours
et leurs fichiers (img et reels). Sur le serveur, un fichier supprimé qui est encore suivi par Git ne gêne pas le `git pull`.

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

## Instagram sans script côté Claude (depuis le 28 octobre 2026)

Les tâches automatiques n'exécutent plus `bot/*.js` ni `bot/queue.py` (bloqués par le contrôle de sécurité des tâches).
Elles écrivent directement dans `insta/queue.json` des posts décrits par des cartes :
`{"id","at","type","caption","cards":[{…}]}` (voir l'en-tête de `bot/card.php` pour le format des cartes, dont l'affiche de match).
Au moment de publier, `bot/publish.php` dessine les cartes sur le serveur (`bot/card.php`, GD, polices `bot/fonts/`)
dans `insta/gen/` (ignoré par Git), puis les envoie à Instagram. Les posts avec `images` (JPEG déjà prêts) restent possibles.
Aperçu d'un post : `php ~/repositories/tribune-breizh/bot/publish.php apercu ID` dans le terminal de cPanel.

Visuels d'origine du site : un post peut aussi être décrit par `"visuels": ["resultats", "classement"]` (derby, classement,
resultats, buteurs, face, rhum, story). `bot/visuels.php` les redessine à l'identique de l'onglet Visuels, avec les données
de `src/index.html` au moment de publier. Test à la main : `php bot/visuels.php derby /tmp/derby.jpg`.

## Rythme des posts, garde de 90 minutes et mentions des clubs (depuis le 4 novembre 2026)

- **Garde** (`bot/garde.php`, utilisée par `publish.php` et `facebook.php`) : aucun nouveau post sur un réseau moins de 90 minutes après le
  précédent, urgents et stories compris. Un post qui attend n'est ni abandonné ni compté comme un essai ; il part au premier passage du cron
  où les 90 minutes sont écoulées (sinon, abandon au bout de la fenêtre de 6 h). Les posts attendent dans l'ordre de leur heure. Un statut
  « à vérifier » compte comme une publication. `php bot/publish.php check` affiche l'état de la garde.
- **Rythme** (consignes des tâches planifiées) : au moins 1 et au plus 2 posts Instagram par jour (les stories sont en plus), 1 post Facebook ;
  les sujets forts en trop sont reportés aux jours creux ; heures variées par créneaux 8h-10h, 12h-14h et 18h-21h, 3 h au moins entre deux posts.
- **Facebook et exploits** : un exploit (coupe, titre) repéré par la vérification légère est aussi publié sur Facebook (champ `facebook` du post,
  ou post `facebook` séparé si l'exploit devient une story), au plus un par jour, en plus du post Facebook habituel (90 min d'écart).
- **Mentions des clubs** : `insta/comptes.json` liste les clubs avec `instagram` (ex. `@nomducompte`) et `facebook` (nom exact de la page).
  Les tâches ne recopient que ces valeurs ; **champ vide = aucune mention**. Aucun identifiant n'est jamais deviné.
  Comptes Instagram des 18 clubs vérifiés par Ronan le 4 novembre 2026 ; comptes Facebook encore à remplir (champ vide = aucune mention).

## Pages par club, par sport et sitemap automatique (depuis le 4 novembre 2026)

Pour le référencement, chaque club et chaque sport a sa propre adresse, fabriquée sur le serveur à partir des données de `src/index.html` :
- `/club/NOM-DU-CLUB` (ex. `/club/stade-rennais`) : résumé, derniers résultats, prochains matchs, classement avec le club surligné, fiche, données structurées schema.org (`SportsTeam`). L'ancienne forme avec l'identifiant (`/club/rennes`) redirige en 301 vers l'adresse définitive.
- `/sport/NOM-DU-SPORT` (ex. `/sport/football`) : récit du jour, clubs bretons, résultats, agenda, classements (`CollectionPage`).
- `/sport/foot-amateur` : page du foot amateur (Coupe de France, National 1 et 2, petits poucets, foot régional R1 et R2). Ses données ne sont pas dans `DATA` : `bot/seo.php` les rassemble depuis les constantes `AMATEUR`, `POUCETS`, `R1` et `R2A` à `R2D` et depuis les gabarits `renderAmateur()` et `renderRegional()` (récit, notes des classements, calendrier de la Coupe, libellés de journée). Chaque morceau est facultatif : s'il n'est plus lisible après un changement de la page, il est simplement omis ; si `AMATEUR` disparaît, la page n'est plus publiée.
- `/evenement/route-du-rhum-2026` : page de l'événement (récit, classement des Bretons, départ, flottes, skippers par classe, sources, données schema.org `SportsEvent`), fabriquée depuis la constante `RHUM` et le gabarit `renderVoile()`. Il n'y a volontairement pas de page `/sport/voile` : tout le contenu voile est la course. L'adresse porte l'année du départ : une prochaine édition aura sa propre page.
  **Archive** : à chaque mise à jour du site, une copie de l'événement est écrite dans `archive/` (ignoré par Git, jamais servi : 404). Si la mise à jour de nuit retire le Rhum de la page, la page d'événement reste en ligne avec un bandeau « Archive » et le dernier pointage, et passe en mise à jour annuelle dans le sitemap. Pour la retirer un jour : supprimer `archive/route-du-rhum-2026.json` sur le serveur. L'archive n'est que sur le serveur : si le serveur est refait, elle est perdue (copier alors `archive/` à part).
- Les récits des pages sport portent l'étiquette « Résumé IA », comme sur la page d'accueil.
- `/sitemap.xml` : généré à la volée avec toutes ces pages (le fichier fixe `sitemap.xml` a été supprimé). La date de mise à jour vient de `version.txt`.
- En bas de la page d'accueil, un bloc de liens vers toutes ces pages (ajouté par `bot/build.php`, donc par `gate.php`, pas par `build.py`).
  Les clubs de Loire-Atlantique (`hist:true`) n'y sont visibles que si le mode « Bretagne historique » est activé (même réglage `tb-hist` que la page) ; leurs pages et le sitemap restent publics.
  Sur les pages `/sport/…`, ces clubs et les classements marqués `hist:true` (ex. volley, Ligue A) suivent le même réglage : masqués par défaut, affichés si `tb-hist` vaut `1` dans le navigateur (le réglage se change avec le bouton de la page d'accueil).
  Même règle pour la liste « Autres clubs de… » des pages `/club/…`, sauf sur la page d'un club de Loire-Atlantique, où ces clubs restent visibles.

Fichiers : `pages.php` (routeur appelé par les règles de `.htaccess`), `bot/seo.php` (lecture des données, gabarits, sitemap). Rien à faire à la main :
une nouvelle page apparaît dès qu'un club ou un sport entre dans `ALLCLUBS` ou `DATA`. Les données sont gardées dans `cache/seo-data.json` (ignoré par Git) et relues seulement quand la source change.
Si la page publiée change de forme au point que les données ne se lisent plus, l'ancien cache continue d'être servi.
Vérification à la main : `php bot/seo.php` (liste des pages), `php bot/seo.php club stade-rennais`, `php bot/seo.php sitemap`.
Après la mise en ligne : déclarer `https://breizh.tribuneouest.fr/sitemap.xml` dans la Search Console.
- **Route du Rhum sur Facebook** (`tb_fb_auto` dans `bot/garde.php`, appelée par `publish.php`) : du 5 au 30 novembre 2026, le post « -rhum » du jour
  (visuel « rhum ») est repris automatiquement sur Facebook, en plus du post Facebook habituel (la garde de 90 minutes les espace). Le message est
  la légende Instagram au moment de publier, sans la ligne « lien en bio » ni les hashtags, avec `#TribuneBreizh #RouteDuRhum2026` et un lien vers
  la page de l'événement. Un post qui porte déjà un champ `facebook` (même annulé) n'est pas touché ; pour l'exclure un jour, y mettre `"facebook": {"cancel": true}`.

## Liens vers les pages de club sur la page d'accueil (depuis le 7 novembre 2026)

`bot/clublinks.php`, appelé par `bot/build.php` (donc par `gate.php`), ajoute à la page d'accueil, au moment de la fabriquer, un lien « Page du club → » au bas de chaque carte club
et rend cliquable le nom des clubs suivis dans les classements, les résultats, les prochains matchs, les fiches et l'agenda (adresse `/club/NOM-DU-CLUB`, calculée comme dans `bot/seo.php`).
Rien n'est modifié dans `src/index.html`, réécrit chaque nuit. Tout ou rien : si un motif de la page n'est plus trouvé, aucun lien n'est ajouté (`php bot/clublinks.php` dit si les liens s'appliquent).

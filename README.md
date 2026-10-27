<div align="center">

<img src="public/favicon.svg" alt="" width="72" height="72">

# RecupBuild

**Photographiez vos matériaux de récupération. Recevez un plan de construction.**

Une feuille A4 posée à côté du tas sert d'échelle : l'IA mesure chaque planche,
invente quatre projets à la mesure de votre bois, un moteur place chaque coupe,
et vous repartez avec le plan de coupe, le rendu 3D et les étapes de montage.

![PHP 8.3](https://img.shields.io/badge/PHP-8.3-2f6b4f?style=flat-square&logo=php&logoColor=white)
![MVC sans framework](https://img.shields.io/badge/MVC-sans%20framework-c4622d?style=flat-square)
![SQLite](https://img.shields.io/badge/SQLite-3-1f2a24?style=flat-square&logo=sqlite&logoColor=white)
![Tests](https://img.shields.io/badge/tests-80%20vérifications-2f6b4f?style=flat-square)
![Meta Hackathon](https://img.shields.io/badge/né%20au-hackathon%20Meta-0866ff?style=flat-square&logo=meta&logoColor=white)

</div>

![La page d'accueil : un tas de planches sur un tapis de découpe, mesuré puis rangé en plan de coupe](docs/images/landing.png)

---

## Sommaire

- [Ce que fait RecupBuild](#ce-que-fait-recupbuild)
- [Les pages](#les-pages)
- [L'offre gratuite](#loffre-gratuite)
- [Démarrer en local](#démarrer-en-local)
- [Organisation du code (MVC)](#organisation-du-code-mvc)
- [Les routes](#les-routes)
- [Configuration](#configuration-env)
- [Déploiement sur un VPS](#déploiement-sur-un-vps)
- [Tests](#tests)
- [Notes techniques](#notes-techniques)

---

## Ce que fait RecupBuild

### Du tas au plan

![Les quatre moments : le tas, mesuré, le plan de coupe numéroté, l'étagère montée](docs/images/pile-to-plan.png)

| Étape | Ce qui se passe |
| --- | --- |
| **1. Scan** | Une photo du tas avec une feuille A4 : le modèle de vision repère chaque pièce et la mesure en millimètres, l'épaisseur est estimée. |
| **2. Inventaire** | Vous relisez le tableau des pièces et corrigez une cote si besoin. |
| **3. Projets** | L'IA **invente 4 projets** utiles pour *ce* bois-là (étagère, jardinière, tabouret…) ; le moteur vérifie que chaque pièce est vraiment découpable. |
| **4. Calcul** | Le moteur de découpe place chaque pièce sur vos planches, trait de scie compté, et réduit les dimensions si ça ne passe pas. |
| **5. Résultat** | Rendu 3D avec la texture de **votre** bois, plan de coupe numéroté, liste des pièces, étapes de montage et PDF. |

<p align="center">
  <img src="docs/images/inventory.png" alt="L'inventaire : la photo avec les pièces numérotées, et le tableau des cotes à corriger" width="49%">
  <img src="docs/images/projects.png" alt="Les projets : chaque objet dessiné en perspective, avec sa taille et son statut" width="49%">
</p>

<p align="center">
  <img src="docs/images/plan.png" alt="Le résultat : rendu 3D, dimensions finales et plan de coupe sur le bois de la photo" width="80%">
</p>

> **L'IA propose, le moteur décide.** Aucun chiffre affiché n'est une estimation libre :
> chaque plan a été vérifié pièce par pièce par `Services/Solver.php`.

### Le build inversé

Photographiez un objet fini (une table, un tabouret, une bibliothèque) : RecupBuild vous dit
quelles pièces couper, avec quels outils et dans quel ordre, et le reconstruit en 3D.

<p align="center">
  <img src="docs/images/reverse-build.png" alt="Build inversé d'une table : nom, difficulté, durée, dimensions estimées" width="49%">
  <img src="docs/images/result-3d.png" alt="La table reconstruite en 3D, pièce par pièce" width="49%">
</p>

---

## Les pages

Tout le site est rendu par **PHP** (`src/Views`), avec la même identité visuelle partout :
papier millimétré, vert forêt, cuivre. Un petit fichier JavaScript sans bibliothèque
(`public/js/pages.js`) ajoute ce qui demande un script : l'animation de l'accueil, l'écran
d'attente pendant que l'IA travaille, les fenêtres de confirmation, l'aperçu de la photo.
La 3D du résultat utilise three.js, embarqué dans `public/vendor/three`. Chaque page reste
utilisable sans JavaScript.

| Page | Adresse | Contenu |
| --- | --- | --- |
| Accueil | `/` | le tas qui devient plan (4 moments animés), les étapes, les projets, le build inversé |
| Construire | `/scan` → `/inventory` → `/projects` → `/plans/{id}` | photo, inventaire à corriger, 4 projets, résultat |
| Reproduire un objet | `/rebuild` → `/rebuilds/{id}` | build inversé d'un objet photographié |
| Historique | `/history` | plans et objets reproduits, à rouvrir ou supprimer |
| Tarifs | `/pricing` | Gratuit (disponible), Pro et Personnalisé (bientôt) |
| Mon profil | `/profile` | compte, crédits restants, plans, déconnexion |
| À propos | `/about` | l'histoire du projet, né au **hackathon Meta** |

<p align="center">
  <img src="docs/images/pricing.png" alt="La page Tarifs : Gratuit disponible, Pro et Personnalisé bientôt disponibles" width="49%">
  <img src="docs/images/profile.png" alt="La page Profil : compte, crédits restants en mètre ruban, plans enregistrés" width="49%">
</p>

Supprimer un plan et se déconnecter passent par une **fenêtre de confirmation**
(`<dialog>` natif : clavier, Échap, focus rendu au bouton).

<p align="center">
  <img src="docs/images/confirm-dialog.png" alt="Fenêtre de confirmation avant de supprimer un plan" width="420">
</p>

**Mode démo** : sur `/scan`, l'exemple « Tas de planches et tôle » suit tout le parcours avec
les quatre projets de base calculés par le moteur seul, **sans appel à l'IA ni crédit utilisé**.

Les pages parlent **français** et **anglais** (`?lang=fr|en|mg`, mémorisé dans un cookie, sinon la
langue du navigateur) ; le malagasy reprend le français pour les textes pas encore traduits.

---

## L'offre gratuite

| Offre | Prix | Contenu | Statut |
| --- | --- | --- | --- |
| **Gratuit** | 0 $ | 5 builds + 5 rebuilds, historique, rendu 3D, PDF | **Disponible**, activée automatiquement à la première connexion |
| **Pro** | 10 $ / mois | builds et rebuilds en volume, analyse prioritaire | Bientôt disponible |
| **Personnalisé** | sur devis | associations, écoles, ressourceries | Bientôt disponible |

- un **build** = un plan de coupe réussi (`POST /api/plan`) ;
- un **rebuild** = un build inversé réussi (`POST /api/reverse`) ;
- le scan et les cartes de projets ne sont **pas** comptés ;
- un crédit n'est consommé **qu'en cas de succès**, et à zéro le serveur refuse **avant** d'appeler
  le modèle (`402 {"detail":{"code":"quota_exhausted"}}`).

Les compteurs vivent dans la table `users` (`build_credits`, `rebuild_credits`) ; un seul
`UPDATE … WHERE credits > 0` les décrémente, deux requêtes simultanées ne dépensent donc jamais
le même crédit.

---

## Démarrer en local

```sh
cp .env.example .env               # y mettre MODEL_API_KEY et les identifiants Google
php -S localhost:8080 router.php
```

Puis ouvrir **http://localhost:8080** (et non `127.0.0.1`, sinon le retour de Google ne correspond pas).

| Adresse | Page |
| --- | --- |
| `http://localhost:8080` | l'accueil |
| `http://localhost:8080/scan` | l'application (connexion Google demandée) |
| `http://localhost:8080/pricing` | les tarifs |
| `http://localhost:8080/profile` | le profil (connexion Google demandée) |
| `http://localhost:8080/api/health` | l'état du serveur |

La base SQLite (`storage/recupbuild.sqlite`) se crée et se met à jour seule au premier accès.

---

## Organisation du code (MVC)

Aucun framework, aucune dépendance Composer : seulement les extensions `curl`, `gd`, `exif`,
`fileinfo`, `pdo_sqlite`, `mbstring` et `json`.

```
public/
  index.php          contrôleur frontal : chargement, CORS, puis le routeur
  css/ js/ fonts/    le style, le script et les polices des pages
  vendor/three/      three.js (licence MIT), pour la 3D du résultat
  demo/ examples/    la photo de démonstration et les exemples à essayer
src/
  routes.php         toutes les routes : méthode + chemin -> contrôleur@action
  Core/              Router, Controller, View, Config, DotEnv, Http, Request, Session, Database, Upload, Cors
  Controllers/       Home, Workspace (scan -> projets), PlanPage, Rebuild, Profile, Pricing, Auth,
                     et l'API : Health, Scan, Plan, History
  Models/            Users (comptes, crédits), Plans, Rebuilds, Photos, Workspace (le parcours en session),
                     Projects (définitions de projets)
  Services/          Vision (le modèle), Planner, Solver (moteur de découpe), Scan, Reverse, Image,
                     GoogleAuth, Assembly (montage 3D), Drawing (dessins SVG), Demo
  Errors/            ScanError, VisionError, AuthError
  Views/             layout et une vue par page, leurs textes dans lang/fr.php et lang/en.php
storage/             la base SQLite et les photos (hors de public/, jamais servies directement)
tests/               php tests/run.php
```

- **Modèles** : les données (SQLite via PDO, photos dans `storage/photos`) et leurs règles.
- **Vues** : les pages (`Core\View` + `src/Views`) et les réponses JSON de l'API (`Core\Http`).
- **Contrôleurs** : une classe par domaine ; `member()` exige une session (sinon Google),
  `checkForm()` vérifie le jeton CSRF, `user()` protège l'API.
- **Services** : le travail, sans HTTP ni base (le modèle, le moteur, les images, les dessins).

Ajouter une page, c'est une ligne dans `src/routes.php`, une méthode de contrôleur et une vue ;
un paramètre `{id}` arrive en `int` dans l'action.

---

## Les routes

**Pages**

| Méthode | Route | Rôle | Session |
| --- | --- | --- | --- |
| `GET` | `/` · `/about` · `/pricing` | accueil, à propos, tarifs | |
| `GET` / `POST` | `/scan` | photo du tas (envoi, exemple IA ou démo) | ✓ |
| `GET` / `POST` | `/inventory` | inventaire à corriger | ✓ |
| `GET` | `/projects` · `POST /projects/choose` | 4 projets, puis le plan du projet choisi (1 crédit build) | ✓ |
| `GET` | `/plans/{id}` · `/plans/{id}/photo` | le résultat et la photo d'origine | ✓ |
| `GET` / `POST` | `/rebuild` · `GET /rebuilds/{id}` | build inversé (1 crédit rebuild) | ✓ |
| `GET` | `/history` · `/profile` | historique, profil | ✓ |
| `POST` | `/profile/plans/{id}/delete` · `/profile/rebuilds/{id}/delete` · `/profile/logout` | formulaires confirmés (jeton CSRF) | ✓ |
| `GET` | `/auth/google` · `/auth/google/callback` | connexion Google par le serveur | |

**API JSON** (même moteur, pour d'autres clients)

| Méthode | Route | Rôle |
| --- | --- | --- |
| `GET` | `/api/health` | état de la clé et du modèle (public) |
| `GET` | `/api/me` | l'utilisateur connecté, ou 401 |
| `POST` | `/api/scan` · `/api/reverse` | photo (`file`) → pièces mesurées · objet à reproduire |
| `POST` | `/api/projects` · `/api/plan` | projets inventés · plan de coupe |
| `GET` / `DELETE` | `/api/history` · `/api/history/{id}` | historique des plans |

Les erreurs de l'API ont la forme `{ "detail": { "code": "a4_not_detected" } }` avec le bon statut
(401 non connecté, 402 crédits épuisés, 413 trop lourd, 415 format, 422 analyse, 502 modèle,
504 délai dépassé). Toute autre adresse affiche la page « introuvable ».

---

## Configuration (`.env`)

| Variable | Défaut | Rôle |
| --- | --- | --- |
| `MODEL_API_KEY` (ou `MUSE_IMAGE_API_KEY`) | vide | clé du modèle Meta, obligatoire pour l'IA |
| `META_API_BASE` | `https://api.meta.ai/v1` | point d'entrée du modèle |
| `VISION_MODEL` | `muse-spark-1.3` | modèle interrogé |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | vide | connexion Google (Google Cloud Console → Clients, « Application Web ») |
| `GOOGLE_REDIRECT_URI` | `http(s)://<hôte>/auth/google/callback` | à déclarer tel quel dans « URI de redirection autorisés » |
| `DATABASE_PATH` | `storage/recupbuild.sqlite` | fichier SQLite, à garder hors de `public/` |
| `ALLOWED_ORIGINS` | `http://localhost:5173,...` | CORS, inutile quand le site et l'API partagent l'origine |

`.env` contient la clé du modèle et le secret Google : il est ignoré par git, interdit par
`public/.htaccess` et par `deploy/nginx.conf.example`, et ne doit jamais être publié.

---

## Déploiement sur un VPS

<details>
<summary><b>1. Envoyer le code</b></summary>

```sh
rsync -av --exclude .env --exclude storage/ backend-php/ user@vps:/var/www/recupbuild/backend-php/
ssh user@vps 'cd /var/www/recupbuild/backend-php && nano .env'
```

</details>

<details>
<summary><b>2. Installer PHP et ses extensions</b></summary>

```sh
sudo apt install php-fpm php-gd php-curl php-xml php-mbstring php-sqlite3
```

Dans `/etc/php/8.3/fpm/php.ini` (et `cli/php.ini`) :

```ini
upload_max_filesize = 12M
post_max_size = 14M
max_execution_time = 300
memory_limit = 256M
```

Dans `/etc/php/8.3/fpm/pool.d/www.conf` : `request_terminate_timeout = 300s`, puis
`sudo systemctl restart php8.3-fpm`. Sans cela, la requête est coupée à 30 s alors que
l'invention des projets prend de 45 à 110 s.

Le dossier `storage/` doit être accessible en écriture par PHP-FPM :
`sudo chown -R www-data: storage`.

</details>

<details>
<summary><b>3. Servir le site (nginx ou Apache)</b></summary>

**nginx** : recopier `deploy/nginx.conf.example` dans `/etc/nginx/sites-available/`
(domaine et version de PHP), puis `sudo nginx -t && sudo systemctl reload nginx`.

**Apache** : recopier `deploy/apache-vhost.conf.example` ; `DocumentRoot` pointe sur
`public/`, avec `AllowOverride All` pour le `.htaccess`.

</details>

<details>
<summary><b>4. HTTPS</b></summary>

```sh
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d recupbuild.example.com
```

Puis déclarer `https://recupbuild.example.com` dans les origines JavaScript et
`https://recupbuild.example.com/auth/google/callback` dans les URI de redirection Google.

</details>

---

## Tests

```sh
php tests/run.php            # 80 vérifications : scan, moteur de découpe, projets inventés, build inversé,
                             # connexion Google, SQLite, historique, crédits, dessins, inventaire, textes des pages
php tests/live_plan.php      # appel réel au modèle : cartes, plan et étapes
```

---

## Notes techniques

- **L'IA, en sécurité** : tout ce que le modèle renvoie (projets, pièces, layouts 3D) est borné et
  revalidé par `Models/Projects::sanitize` ou `Services/Reverse::build` avant d'atteindre le navigateur.
- **Connexion Google** : flux *authorization code* côté serveur, avec `state` et `nonce` ; le secret
  ne quitte jamais le serveur, la session est un cookie `HttpOnly` / `SameSite=Lax`.
- **Pages** : chaque texte est échappé, les formulaires portent un jeton CSRF, et une
  *Content-Security-Policy* n'autorise que les scripts et styles du site.
- **Images** : décodage GD (JPEG, PNG, WebP), rotation EXIF, plus grande arête à 1600 px, JPEG 85.
- **Latence** : une requête occupe un *worker* PHP pendant l'appel au modèle ; prévoir
  `pm.max_children` ≥ 5 si plusieurs personnes l'utilisent en même temps.

---

<div align="center">

<img src="public/favicon.svg" alt="" width="28" height="28">

**RecupBuild** est né pendant le **hackathon Meta** : construire avec ce qu'on a déjà sous la main.

*Plan indicatif : vérifiez chaque cote sur vos pièces avant de couper.*

</div>

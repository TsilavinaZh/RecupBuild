# RecupBuild — backend en PHP (VPS)

Ce dossier est **la traduction en PHP 8 du backend FastAPI du projet** (`backend/app/`),
préparée pour un VPS LAMP ou nginx + PHP-FPM. Aucun framework, aucune dépendance Composer :
le code n'utilise que les extensions `curl`, `gd`, `exif`, `fileinfo` et `json`
(toutes présentes sur un Debian/Ubuntu standard avec `apt install php-gd php-curl php-exif php-xml`).

> Le frontend reste Vue (il ne se traduit pas en PHP) : on construit ses fichiers statiques
> et on les copie dans `public/`. PHP sert alors l'API **et** le site, sur une seule origine.

## Les endpoints

| Méthode | Route | Rôle |
| --- | --- | --- |
| `GET` | `/api/health` | état de la clé API et du modèle |
| `POST` | `/api/scan` | photo (*multipart*, champ `file`) → feuille A4 et pièces mesurées |
| `POST` | `/api/plan` | tailles du projet choisi → journal de calcul, plan de découpe, étapes de fabrication |
| `POST` | `/api/projects` | un seul appel IA : 4 projets imaginés pour les pièces de l'utilisateur, vérifiés par le moteur |
| `GET` | `/auth/google` | connexion Google par le serveur (redirige vers Google, `?return=/chemin` pour revenir) |
| `GET` | `/auth/google/callback` | retour de Google : échange du code avec le secret, ouverture de la session |
| `GET` | `/api/me` | l'utilisateur connecté, ou 401 |
| `POST` | `/auth/logout` | déconnexion |
| — | tout le reste | `public/index.html` (l'app Vue construite) si elle existe |

Le corps d'erreur est exactement celui de FastAPI, lu par le frontend :
`{ "detail": { "code": "a4_not_detected" } }` avec le bon statut HTTP
(413 trop lourd, 415 format, 422 analyse, 502 modèle, 504 délai dépassé).

### Le planificateur IA

Le modèle **propose** et le moteur **décide** : sur l'écran Projet, `Planner.php`
lui demande **une taille par projet** (la plus grande qui passe encore), vérifiée
pièce par pièce par `Solver` — un chiffre affiché est toujours coupable. Sur
l'écran Calcul, si les tailles choisies ne passent plus, il propose jusqu'à 4
options plus petites (la plus grande d'abord) puis les étapes de fabrication.
Le moteur prend la main quand les options ne suffisent plus.

Latence observée sur l'exemple du dépôt : appels texte triviaux ~3-4 s, jeu de
tagères ~7 s, tailles et cartes **60 à 110 s** (le modèle raisonne). Les
réglages serveur ci-dessous (300 s) et un *fallback* local côté frontend
(`VITE_PLAN_API=false`) existent pour cela.

## Équivalence avec le code Python

| Python (`backend/app/`) | PHP (`backend-php/src/`) |
| --- | --- |
| `config.py` | `Config.php`, `DotEnv.php` |
| `scan.py` (`prepare_image`) | `Image.php` (librairie **GD** au lieu de Pillow) |
| `scan.py` (`build_response`) | `Scan.php` |
| `providers/vision.py` | `Vision.php` (**cURL** au lieu de httpx) |
| `domain/solver.ts` | `Solver.php` |
| (aucun équivalent) | `Planner.php`, `Projects.php`, `Request.php` |
| `main.py` | `public/index.php` |
| `tests/test_scan.py` | `tests/run.php` |

Les mêmes constantes sont reprises : A4 210 x 297 mm, plus grande arête à 1600 px,
JPEG qualité 85, upload 12 MB max, modèle `muse-spark-1.3`, délai 120 s,
arrondi des cotes au 5 mm, `thickness_mm` toujours `null`.

## Lancer en local

```sh
cp .env.example .env         # y coller MODEL_API_KEY
php -S localhost:8080 router.php
curl http://localhost:8080/api/health
```

`router.php` sert les fichiers statiques de `public/` et le route `/api` vers
`public/index.php` (équivalent de `public/.htaccess` sous Apache).

### Tests

```sh
php tests/run.php            # 34 vérifications (scan + modulo de la solveuse)
php tests/live_plan.php      # appel réel au modèle: cartes + plan + étapes
```

## Configuration (`.env`)

| Variable | Défaut | Rôle |
| --- | --- | --- |
| `MODEL_API_KEY` (ou `MUSE_IMAGE_API_KEY`) | vide | clé Meta, obligatoire pour `/api/scan` |
| `META_API_BASE` | `https://api.meta.ai/v1` | point d'entrée du modèle vision |
| `VISION_MODEL` | `muse-spark-1.3` | modèle interrogé |
| `ALLOWED_ORIGINS` | `http://localhost:5173,...` | CORS, inutile si frontend et API sont sur le même origine |

Le fichier `.env` ne quitte jamais le serveur (déjà ignoré par git, interdit par
`public/.htaccess` et par `deploy/nginx.conf.example`).

## Déploiement sur un VPS

### 1. Envoyer le code

```sh
rsync -av --exclude .env backend-php/ user@vps:/var/www/recupbuild/backend-php/
ssh user@vps 'cd /var/www/recupbuild/backend-php && nano .env'
```

### 2. Installer PHP + les extensions

```sh
sudo apt install php-fpm php-gd php-curl php-xml php-mbstring
```

Réglages dans `/etc/php/8.3/fpm/php.ini` (et `cli/php.ini`) :

```ini
upload_max_filesize = 12M
post_max_size = 14M
max_execution_time = 300
memory_limit = 256M
```

puis dans `/etc/php/8.3/fpm/pool.d/www.conf` :

```ini
request_terminate_timeout = 300s
```

```sh
sudo systemctl restart php8.3-fpm
```

Sans cela, la requête est coupée à 30 s avant que le modèle vision ait répondu.
Les en-têtes nginx/Apache du dépôt sont à 300 s : le planificateur enchaîne
jusqu'à deux appels de modèle (tailles, puis étapes) et carte les 4 projets
d'un seul coup — un de ces appels a déjà pris 110 s de bout en bout.

### 3. Servir le site

**nginx** : recopier `deploy/nginx.conf.example` dans
`/etc/nginx/sites-available/` (y mettre votre domaine et votre version de PHP),
puis `sudo nginx -t && sudo systemctl reload nginx`.

**Apache (LAMP / hébergeur classique)** : recopier `deploy/apache-vhost.conf.example` —
`DocumentRoot` doit pointer sur `public/`, avec `AllowOverride All` pour que le
`.htaccess` fasse les réécritures.

### 4. Publier le frontend

```sh
cd frontend
npm install && npm run build        # sans VITE_API_BASE_URL: l'API est sur la même origine
rsync -av --delete dist/ user@vps:/var/www/recupbuild/backend-php/public/
```

`frontend/dist/index.html` devient `backend-php/public/index.html` : les fichiers réels
(y compris `assets/`) sont servis par nginx/Apache, tout le reste retombe sur `index.php`.
L'app utilise le routage par ancre (`#/`), donc aucune règle supplémentaire n'est nécessaire.

### 5. HTTPS

```sh
sudo apt install certbot python3-certbot-nginx   # ou --apache
sudo certbot --nginx -d recupbuild.example.com
```

Avec HTTPS, pensez à mettre l'origine du domaine dans `ALLOWED_ORIGINS` **seulement si**
le frontend est servi depuis un autre domaine (développement, CDN).

## Vérifier après déploiement

```sh
curl https://recupbuild.example.com/api/health
curl -F "file=@photo.jpg" https://recupbuild.example.com/api/scan
curl -H 'Content-Type: application/json' -H 'Accept-Language: fr' \
  -d '{"project":"shelf","params":{"width":500,"depth":90,"height":600},"pieces":[{"id":1,"kind":"plank","length_mm":1200,"width_mm":140,"thickness_mm":22},{"id":4,"kind":"batten","length_mm":900,"width_mm":40,"thickness_mm":40},{"id":7,"kind":"plank","length_mm":600,"width_mm":180,"thickness_mm":20}]}' \
  https://recupbuild.example.com/api/plan
```

Avec les tailles de la carte projet, `/api/plan` revient en ~7 s (le modèle n'écrit
que les étapes) ; sans elles, il propose d'abord ses propres tailles (~60 s).
`/api/projects` met 60 à 110 s : l'écran Projet affiche l'estimation locale pendant ce
temps et la remplace quand la réponse arrive.

- sans clé : `502 {"detail":{"code":"server"}}`
- photo sans feuille A4 : `422 {"detail":{"code":"a4_not_detected"}}`
- fichier non image : `415 {"detail":{"code":"unsupported_type"}}`
- plus de 12 MB : `413 {"detail":{"code":"file_too_large"}}`

## Notes de conversion

- **Pillow → GD** : décodage photo (JPEG/PNG/WebP), rotation EXIF (orientations 2 à 8),
  redimensionnement à 1600 px sur la plus grande arête, ré-encodage JPEG qualité 85.
- **httpx → cURL** : appel `POST {META_API_BASE}/chat/completions` avec
  `response_format: json_schema`, image en `data:image/jpeg;base64`, délai 120 s
  (`CURLE_OPERATION_TIMEDOUT` → code `timeout`, HTTP 504).
- **round() Python = round() PHP** sur les cotes : l'arrondi au double zéro n'est pas
  atteint par une mesure de photo, les résultats sont identiques à 5 mm près.
- Latence : la requête occupe un *worker* PHP pendant l'appel au modèle, pensez à
  `pm.max_children` de PHP-FPM (≥ 5) si plusieurs scans simultanés.
# Audit des bugs & incohérences — projets_code

> Rapport généré et maintenu automatiquement (boucle de surveillance `/loop`).
> Dernière mise à jour : **2026-06-11**
> Périmètre : dépôt parent `projets_code` + sous-modules (`IOT_n3`, `moodle/index_olution`, `ForetMap`).

Ce document recense les bugs et incohérences détectés. Les chemins sont relatifs à la
racine du dépôt parent. La correction des findings situés **dans les sous-modules** doit
être commitée dans le dépôt du sous-module concerné, puis le pointeur mis à jour ici.

---

## 🔴 Niveau dépôt parent (`projets_code`)

| # | Sévérité | Fichier | Problème |
|---|----------|---------|----------|
| P1 | **MAJEUR** | `.gitmodules` / `ForetMap` | `ForetMap` est un gitlink committé (commits `c5150a4`, `fcde27d`) **jamais enregistré dans `.gitmodules`**. Conséquence : `git submodule update --init --recursive` échoue entièrement (`fatal: No url found for submodule path 'ForetMap'`). Le clonage récursif documenté dans le README est donc cassé. → Ajouter l'entrée `[submodule "ForetMap"]` avec son URL, ou retirer le gitlink. |
| P2 | MAJEUR | `moodle/moodle_magnifier/` | Plugin Moodle versionné comme **dossier ordinaire** (pas un sous-module) ne contenant **que des binaires** (`local_magnifier-0.1.1..0.1.4.zip`, `moodle_magnifier.zip`) + un `version.php` orphelin. **Aucune source** du plugin n'est trackée. Incohérence de version : `version.php` déclare `release = '0.1.5'` (`$plugin->version = 2025031606`) mais le dernier zip est `0.1.4`. |
| P3 | MINEUR | `README.md` | Hors-sync avec le contenu réel : ne mentionne **ni `ForetMap` ni `moodle/moodle_magnifier`**. L'arborescence documentée ne liste que `IOT_n3` et `moodle/index_olution`. |
| P4 | MINEUR | sous-modules `IOT_n3/serveur`, `IOT_n3/firmwires` | Pointeurs épinglés sur des **branches WIP `claude/...`** (`serveur` → `claude/audit-server-interface-…`, `firmwires` → `claude/email-server-concurrency-…`) au lieu de `main`. Surface de régression : le parent suit du code non mergé. |

---

## 🔴 Sous-module `IOT_n3` (serveur PHP Slim4 + firmwares ESP32)

> ⚠️ `serveur/` et `firmwires/` sont eux-mêmes des sous-modules (`n3_serveur`, `n3_firmwires`).

### CRITIQUE
| # | Fichier | Problème |
|---|---------|----------|
| I1 | `IOT_n3/serveur/ffp3/.env` | **Secrets de production réels committés en clair** : `DB_PASS`, `API_KEY`, `API_SIG_SECRET` (clé HMAC), `ADMIN_PASSWORD_HASH`, emails. Le `.gitignore` ignore `.env` à la racine mais ce fichier est entré via subtree `ffp3/`. **→ Considérer tous ces secrets comme compromis : rotation + purge de l'historique (`git filter-repo`).** |
| I2 | `firmwires/*/src/main.cpp`, `…/config.h`, `ota/n3pp/metadata.json` | Firmwares appellent le serveur en **HTTP non chiffré** (`http://iot.olution.info/...`) avec l'`api_key` dans le corps POST → interception triviale. OTA en HTTP, intégrité par MD5 seul (pas de signature). |
| I3 | `IOT_n3/serveur/public/index.php:502` | `addErrorMiddleware(true, true, true)` → **fuite des stack traces / détails d'exception** (chemins, SQL) au client en prod. |

### MAJEUR
| # | Fichier | Problème |
|---|---------|----------|
| I4 | `IOT_n3/serveur/src/Security/AuthService.php:161` | `validateToken()` lit `$_ENV['ADMIN_TOKEN']` — variable **inexistante** (l'env définit `ADMIN_CACHE_TOKEN`). Avec `AUTH_METHOD=token`/`both`, l'auth par token est toujours `false` → **inopérante**. |
| I5 | `IOT_n3/serveur/src/Controller/Gallery/GalleryUploadController.php` | `processUpload()` **n'authentifie pas** l'upload (routes gallery dans `public_paths`). Seule validation : `getClientMediaType()` (en-tête client falsifiable). |
| I6 | `firmwires/shared/n3_hmac/*` ↔ `serveur/src` | **Contrat HMAC cassé** : les firmwares calculent `HMAC-SHA256(body, apiKey)` envoyé dans l'en-tête `X-Signature`, mais **aucun code serveur ne lit `X-Signature`**. Le seul HMAC serveur (`SignatureValidator`, FFP3) signe le *timestamp* avec une *autre* clé/algorithme. Signature firmware = code mort. |
| I7 | `IOT_n3/serveur/src/Controller/AbstractPostDataController.php:79-80` | `if ($expectedKey !== '' && $apiKey !== $expectedKey)` → si `API_KEY` est vide dans l'env, **la vérification est entièrement sautée** (post-data accepté sans auth). |

### MINEUR
| # | Fichier | Problème |
|---|---------|----------|
| I8 | `IOT_n3/serveur/ffp3/`, `IOT_n3/serveur/site initial/` | Code mort massif : `ffp3/` = copie obsolète complète (217 fichiers) ; `site initial/` = 3976 fichiers legacy sur 4544 trackés. ~90 % du dépôt serveur est du legacy. |
| I9 | `IOT_n3/serveur/src/Config/Env.php:52` | Whitelist d'environnements `['prod','test','test3','s3']` désynchronisée de `TableConfig`/`EnvironmentMiddleware`/`config/modules.php` (qui ajoutent `n3pp_test`, `msp_test`). Un `.env` avec `ENV=n3pp_test` → `RuntimeException` 500. |
| I10 | `IOT_n3/CHANGELOG.md:27` vs `serveur/VERSION` | CHANGELOG annonce « serveur 5.0.27 », `serveur/VERSION` = `5.0.173`, `ffp3/VERSION` = `4.9.94`. Conventions mélangées (date `2025.03` à la racine vs semver). |
| I11 | `IOT_n3/serveur/src/Config/Env.php:31` | Charge `.env` depuis `serveur/.env` (**absent** du dépôt), pas depuis `serveur/ffp3/.env` (le seul présent, avec secrets). Confusion de configuration. |
| I12 | `IOT_n3/*.md` | Docs contredites par le code : `AUDIT_GALERIES_SERVEUR.md` parle d'une limite d'upload « 500 Mo » alors que le code est **5 Mo** (`GalleryUploadController.php:23`) ; `RECOMMANDATIONS_IOT.md` interdit de committer `.env` alors que I1 le fait. |

*Vérifiés NON problématiques : pas d'injection SQL exploitable (tables via whitelist `TableConfig`, valeurs en requêtes préparées) ; redirections 301 `/ffp3/*` sautent les POST firmware ; noms de champs n3pp firmware↔serveur cohérents.*

---

## 🟠 Sous-module `moodle/index_olution` (site vitrine PHP statique)

> Surface réduite : page statique, pas de BDD, pas de formulaire traité côté serveur, **aucun secret committé**.

| # | Sévérité | Fichier | Problème |
|---|----------|---------|----------|
| M1 | MAJEUR | `index.php:12,42` | `$base` dérivé de `dirname($_SERVER['SCRIPT_NAME'])` réinjecté dans `<base href>` via `htmlspecialchars` sans `ENT_QUOTES`. Risque réel limité (attribut en guillemets doubles, `SCRIPT_NAME` non contrôlable en config standard) mais seul point d'entrée externe — à durcir. |
| M2 | MAJEUR | `public/index.php:6` vs `index.php:12` | Couplage fragile : `public/index.php` force `$_SERVER['SCRIPT_NAME']='/index.php'`. Si le serveur sert `/public/index.php` sans ce shim, `$base` devient `/public` → tous les assets cassés (page sans CSS/images). |
| M3 | MAJEUR | `docs/DEPLOY.md:37` vs `sitemap.xml`/`robots.txt`/canonical | Incohérence de domaine : déploiement sur `http://index.olution.info/` mais SEO (canonical, OG, sitemap) pointe sur `https://olution.info/`. Le `canonical` dé-référence la page vitrine vers un autre site → risque de désindexation. |
| M4 | MINEUR | `README.md:26-27` | `main.js` listé deux fois dans l'arborescence (copier-coller). |
| M5 | MINEUR | `docs/ARCHITECTURE.md:37` | Avertit d'une typo `filter-proptypage` (ligne ~581) **inexistante** dans le code (tous en `filter-prototypage`). Doc périmée trompeuse. |
| M6 | MINEUR | `docs/ARCHITECTURE.md:22` | Doc dit `rand(1,$nbimages)` ; code utilise `random_int(...)` (index.php:34). |
| M7 | MINEUR | `index.php:107`/`README.md:66` vs `VERSION` | Versions divergentes : template « Laura v4.8.1 » vs `VERSION=4.33` affiché en pied de page aux visiteurs. |
| M8 | MINEUR | `docs/ARCHITECTURE.md:13,76` | Mentionne un « formulaire contact » inexistant (seul un `mailto:` subsiste). |
| M9 | MINEUR | `deploy-fix.sh` | Correctif fragile : `git checkout origin/main -- index.php public/index.php` sur la prod écrase les modifs locales sans sauvegarde et n'adresse pas la cause (Composer obsolète en prod). |
| M10 | MINEUR | `sitemap.xml:5` | `<lastmod>2025-03-04</lastmod>` figé/obsolète (signal SEO « contenu périmé »). |
| M11 | MINEUR | `index.php:34` | Image hero tirée aléatoirement à chaque chargement (CSS inline) → jamais en cache navigateur, pas de `preload` (impact LCP). |

---

## 🟢 Sous-module `ForetMap`

Non analysé : gitlink non résolvable (voir **P1** — absent de `.gitmodules`). À corriger avant audit.

---

## Synthèse / priorités

1. **I1 — Rotation immédiate des secrets** committés dans `serveur/ffp3/.env` + purge historique.
2. **I3, I7, I4, I5 — Durcissement auth/erreurs serveur** (désactiver `displayErrorDetails`, corriger token, auth upload, bypass clé vide).
3. **I2, I6 — Chiffrement transport + contrat de signature** firmware↔serveur.
4. **P1 — Réparer le sous-module `ForetMap`** (clonage récursif cassé).
5. **P2, P3, M3 — Cohérence packaging/doc/SEO.**

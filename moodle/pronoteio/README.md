# pronoteio

Passerelle entre **Moodle 5.1 / 5.2** et **Pronote 2026** via le compte d'un enseignant.

> Statut : **alpha (0.2.2)**. Envoi direct des notes, synchronisation des cohortes et lecture des
> absences validés sur PRONOTE 2026.2.7 avec un vrai compte enseignant.

## Architecture (option B retenue)

```
Moodle (local_pronoteio)  --REST signé HMAC-->  sidecar Node (Pawnote)  --protocole interne-->  Pronote
          \--> connecteur « fichiers » (export CSV notes, import iCal EDT) : repli officiel
```

- `plugin/` : plugin local Moodle `local_pronoteio`, à copier dans `public/local/pronoteio`.
- `sidecar/` : service Node.js/TypeScript (Fastify + [Pawnote](https://www.npmjs.com/package/pawnote)),
  à exécuter sur le même serveur que Moodle (écoute `127.0.0.1`).
- `docs/options.md` : inventaire des options étudiées, intérêts, complexité, risques.

## Flux couverts

| Flux | Sens | Connecteur |
|---|---|---|
| Emploi du temps | Pronote -> calendrier Moodle | sidecar, iCal |
| Travail à faire / cahier de textes | Pronote -> Moodle | sidecar |
| Classes, groupes, élèves | Pronote -> groupes Moodle | sidecar |
| Absences | Pronote -> Moodle (lecture) | sidecar |
| Notes | Moodle -> Pronote | fichier CSV (fiable), sidecar (expérimental) |
| Progression (bloc Completion Progress) | Moodle -> Pronote | fichier CSV, sidecar |

## État d'avancement

| Élément | État |
|---|---|
| Connexion enseignant (identifiant/mot de passe puis jeton) | Validé sur PRONOTE 2026.2.7 (Pawnote corrigé, voir ci-dessous) |
| Double authentification Pronote (code PIN) | Validé : PIN saisi une fois, appareil de confiance, puis jeton |
| EDT -> calendrier (sidecar ou iCal) | Implémenté |
| Travail à faire -> événements de cours | Implémenté côté Moodle ; page enseignant Pronote à valider |
| Liste des classes/groupes | Validé : toutes les classes de l'établissement + groupes enseignés |
| Élèves -> groupes Moodle | Validé (`ListeEleves`, toute classe ou groupe) ; rapprochement par nom |
| Absences | Validé (feuilles d'appel des cours de l'enseignant, voir `docs/capture.md`) ; côté Moodle, seul le volume est journalisé |
| Notes -> fichier Pronote | Implémenté (tous les statuts : Abs, Disp, N.Not, Inap, N.Rdu, Abs0, N.Rdu0) |
| Notes -> Pronote direct | Validé : simulation, création, mise à jour du même devoir, relecture des notes enregistrées |
| Cohortes <-> classes Pronote | Implémenté (table de correspondance, suggestions, CSV, remplissage auto nocturne) |
| Progression -> Pronote | Implémenté : bouton « Exporter la progression vers Pronote » sur la page de synthèse du bloc `block_completion_progress` (filtre groupe/groupement repris) ; % converti en note /10, coefficient 0,2 par défaut (envoi direct ; le fichier d'import ne porte pas de coefficient) |

Les requêtes de l'espace enseignant ont été relevées sur PRONOTE 2026.2.7 : voir `docs/capture.md`
(noms, données, constats). Points à connaître :

- les identifiants Pronote changent à chaque session ; le sidecar expose des identifiants stables
  construits à partir des libellés (`c:T06`, `s:MATIÈRE|T06`, `e:NOM Prénom`…). Renommer une classe
  ou un service dans Pronote impose donc de refaire l'association dans Moodle ;
- un compte professeur ne reçoit ni e-mail ni date de naissance des élèves : choisir un
  rapprochement « Nom et prénom » ;
- Pawnote 1.6.2 ne sait pas se connecter à PRONOTE 2026 ; `npm install` applique automatiquement
  `sidecar/tools/patch-pawnote.mjs` (nouveau calcul du défi d'authentification).

## Envoi direct des notes

Dans un cours : *Exporter les notes vers Pronote > Envoyer directement dans Pronote*.

1. **Options** : élément d'évaluation, groupe Moodle, service Pronote (matière + classe) et période,
   puis toutes les options du devoir Pronote : titre, date, date de publication, barème (celui de
   Moodle ou fixe), coefficient, ramener sur 20, facultatif, bonus, commentaire, arrondi, statut
   envoyé pour une note absente ou exclue.
2. **Aperçu** : rapprochement élève par élève, note convertie, élèves Pronote sans correspondance,
   homonymes signalés (jamais rapprochés automatiquement).
3. **Simulation** (`dryRun`, rien n'est écrit) puis **envoi**. Un second envoi du même élément vers le
   même service et la même période met à jour le devoir créé la première fois.

Réglages par défaut : *Administration du site > Plugins > Plugins locaux > Pronoteio > Envoi des notes
vers Pronote*. L'écriture réelle exige `enablegradewrite` côté Moodle **et**
`PRONOTEIO_ENABLE_GRADE_WRITE=true` côté sidecar ; la simulation reste toujours possible.

## Cohortes et classes Pronote

*Administration du site > Plugins > Plugins locaux > Pronoteio* :

1. **Cohortes** : choisir le compte enseignant lié servant à lire les classes (aucun droit particulier,
   seules les classes visibles par cet enseignant sont proposées).
2. **Cohortes et classes Pronote** : une ligne par classe/groupe, avec la cohorte suggérée d'après le
   nom ou l'identifiant, la case « remplir automatiquement » et le rapport de la dernière
   synchronisation (élèves introuvables, homonymes). Import/export CSV :
   `cohort_idnumber;cohort_name;pronote_class;autofill`.

La tâche `cohort_sync_task` (chaque nuit à 2 h) ajoute les élèves rapprochés aux cohortes en
remplissage automatique et ne retire que les membres qu'elle a elle-même ajoutés. Dans la page
Pronote d'un cours, la cohorte liée à chaque classe est affichée avec un lien pour ajouter
l'inscription par cohorte.

Compatibilité : Moodle 5.1 (`requires`) et 5.2 (`supported = [501, 502]`), pour rester déployable sur
Moodle_prod (5.1.3).

## Installation (développement)

### Sidecar

```bash
cd sidecar
cp .env.example .env      # renseigner PRONOTEIO_SECRET (même valeur que dans Moodle)
npm install
npm run dev               # ou : docker build -t pronoteio-sidecar . && docker run --env-file .env -p 127.0.0.1:3900:3900 pronoteio-sidecar
```

### Plugin

1. Copier le contenu de `plugin/` dans `<moodle>/public/local/pronoteio/`.
2. Visiter *Administration du site > Notifications* pour installer.
3. *Administration du site > Plugins > Plugins locaux > Pronoteio* : URL du sidecar et secret partagé.
4. Chaque enseignant lie son compte Pronote depuis le menu principal (**Pronote**).
5. Dans un cours : *Plus > Pronote* pour associer une classe/un groupe et exporter les notes.

Le mot de passe Pronote n'est jamais stocké : seul le jeton renouvelé par Pronote l'est, chiffré
avec `\core\encryption`.

## Avertissement

Pronote ne propose pas d'API publique. Le sidecar s'appuie sur le protocole interne (non officiel),
susceptible de changer à chaque version de Pronote. Les requêtes propres à l'espace enseignant ne
sont pas couvertes par Pawnote et sont isolées dans `sidecar/src/teacher/`.

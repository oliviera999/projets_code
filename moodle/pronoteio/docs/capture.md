# Relevé des requêtes de l'espace professeur Pronote

Pronote ne documente pas les fonctions de son espace professeur. Le service annexe s'appuie sur les
fonctions suivantes (voir `sidecar/src/teacher/functions.ts`). Les valeurs par défaut ont été
relevées sur **PRONOTE 2026.2.7** (septembre 2026) ; elles restent modifiables sans recompiler via
`PRONOTEIO_FUNCTIONS_FILE`.

| Clé | Fonction | Onglet | Données envoyées | Réponse utilisée |
|---|---|---|---|---|
| `periodsList` | `ListePeriodes` | 23 | aucune | `listePeriodes`, `periodeParDefaut` |
| `servicesList` | `ListeServices` | 23 | `Professeur` (genre 3), `Periode` | `services` (`matiere`, `classe`) |
| `gradesPage` | `PageNotes` | 23 | `service`, `periode` | `listeEleves`, `listeDevoirs`, `listeClasses`, `service` (réglages) |
| `studentsList` | `ListeEleves` | 105 | `ressource` `{N, G, L}` (G obligatoire : 1 classe, 2 groupe) | `listeEleves` |
| `gradesSave` | `SaisieNotes` | 23 | `periode`, `service`, `listeDevoirs` | relecture par `PageNotes` |
| `absencesList` | `PageSaisieAbsences` | 113 | `Professeur` (genre 3), `Ressource` `{N}` (cours de l'emploi du temps), `Date` (début du cours) | `ListeEleves[].ListeAbsences` |

Tant qu'une clé est vide, la route correspondante répond `501 not_configured:<clé>`.

## Constats importants (PRONOTE 2026)

- **Identifiants de session** : les identifiants Pronote (`105#…`) sont chiffrés pour chaque
  session ; un identifiant lu dans une session est refusé dans la suivante (« page expirée »). Le
  service annexe expose donc des **identifiants stables construits à partir des libellés**
  (`sidecar/src/teacher/ids.ts`) et les retraduit à chaque appel :
  `c:T06` (classe), `g:…` (groupe), `p:Trimestre 1` (période),
  `s:MATIÈRE|CLASSE` (service), `e:NOM Prénom` (élève), `d:jj/mm/aaaa|Titre` (devoir). Les
  homonymes reçoivent un suffixe `~2`, `~3`… ; les clés longues sont hachées.
- **Élèves** : un compte professeur ne reçoit que « NOM Prénom » et la classe, ni e-mail ni date de
  naissance. Le rapprochement Moodle se fait donc par le nom.
- **Classes de l'établissement** : `ParametresUtilisateur` (envoyé une seule fois, à la connexion)
  contient toutes les classes (genre 1) et groupes (genre 2), avec l'indicateur `enseigne`.
  `ListeEleves` fonctionne pour n'importe quelle classe : la synchronisation des cohortes n'exige
  aucun droit particulier. Le service annexe capture cette réponse à la connexion
  (`sidecar/src/pronote/userdata.ts`), ainsi que `General.BaremeMaxDevoirs` de `FonctionParametres`.
- **Écriture** (`objetrequetesaisienotes.js` du client) : le devoir est envoyé dans
  `listeDevoirs` ; un nouveau devoir a `N` négatif et `E: 1`, un devoir modifié son `N` et `E: 2`.
  Les notes sont sur `listeEleves[].note` (minuscule) : `"12,5"` ou `"|n"` pour une annotation
  (1 Abs, 2 Disp, 3 N.Not, 4 Inap, 5 N.Rdu, 6 Abs0, 7 N.Rdu0). Le titre du devoir est
  `commentaire` ; « facultatif » s'exprime par `commeUnBonus` / `commeUneNote`. Le membre
  `service` reprend les réglages de la période lus dans `PageNotes` (arrondis, pondérations).
- **Absences** : un compte professeur n'a pas accès aux onglets de récapitulatif (138
  `RecapAbsencesEleves`, 155 `AbsencesGrille`, 73 absences et retards) ; seul l'onglet 113 « Appel
  et suivi » est ouvert. Le service annexe lit donc la feuille d'appel (`PageSaisieAbsences`) de
  chaque cours de l'enseignant sur la période (emploi du temps via Pawnote), garde les absences
  (`G` 13 ; 14 = retard, ignoré) des élèves de la classe ou du groupe (`ListeEleves`), puis
  dédoublonne : une absence d'une journée apparaît sur chaque cours de la journée. Chaque absence
  porte `eleve`, `DateDebut`, `DateFin`, `justifie` et `listeMotifs`. Conséquences : seules les
  absences qui recouvrent un cours de cet enseignant remontent, et les cours de groupe ne citent
  que les classes (`listeClasses`), d'où le filtrage par la liste des élèves. Identifiant stable :
  `a:<élève>|<début>|<fin>`.
- **Connexion** : PRONOTE 2026 a changé la réponse au défi d'authentification et la page de
  connexion ; Pawnote 1.6.2 est corrigé par `sidecar/tools/patch-pawnote.mjs` (lancé après
  `npm install`) et `sidecar/src/pronote/compat.ts`. La double authentification par code PIN est
  gérée : le PIN n'est demandé qu'une fois, l'appareil est ensuite enregistré comme appareil de
  confiance et les connexions suivantes se font par jeton.

## Règles de sécurité

- Les identifiants restent dans `sidecar/.env` (non versionné). Ne jamais les coller dans un
  ticket, un chat ou un commit.
- Les relevés contiennent des données d'élèves : ils sont écrits dans `sidecar/.capture/`
  (non versionné) et doivent être supprimés une fois le relevé terminé.
- Toute écriture se fait sur une **classe et un devoir de test**, avec une date de publication
  lointaine pour que ni les élèves ni les parents ne le voient.

## Refaire le relevé (nouvelle version de Pronote)

1. Récupérer le client : `professeur.html` puis le module `professeur_defer.js` (URL donnée par
   `deferLoadingScript.add('defer', …)` dans la page). Les requêtes y sont déclarées par
   `.inscrire('NomFonction')`, juste après leur classe (`lancerRequete` montre les données envoyées,
   `actionApresRequete` la réponse lue).
2. Valider en lecture avec l'outil de relevé :

   ```bash
   cd sidecar
   # .env : PROBE_URL, PROBE_USERNAME, PROBE_PASSWORD (+ PROBE_PIN à la première connexion)
   npx tsx --env-file=.env tools/probe.ts <NomFonction> <onglet> donnees.json
   ```

   Le jeton est conservé dans `.probe-token` avec l'identifiant d'appareil ; les lancements
   suivants ne redemandent ni mot de passe ni PIN. Les fonctions contenant `Saisie` exigent
   l'option `--write`. Attention : chaque lancement ouvre une nouvelle session, les identifiants
   `N` d'un lancement précédent sont donc invalides.
3. Reporter les écarts dans `sidecar/pronote-functions.json` (noms et onglets) ou dans
   `sidecar/src/teacher/` (données et lecture des réponses).
4. Tester depuis Moodle : page *Envoyer les notes vers Pronote* en mode **simulation**, puis un
   envoi réel sur le devoir de test.

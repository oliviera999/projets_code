# Inventaire des options Moodle <-> Pronote

Contexte : Moodle 5.2 (compatible 5.1), Pronote 2026 (collège/lycée), connexion par le compte d'un
enseignant avec identifiant/mot de passe Pronote direct (sans ENT).

## Constats

- Pronote n'expose **aucune API publique** pour les établissements du secondaire.
- Le Web Service SOAP/REST officiel n'existe que pour **PRONOTE Campus** (supérieur) ou pour des
  partenaires ENT sous convention avec Index Education.
- Le client web Pronote dialogue via un **protocole interne** : JSON chiffré (AES, clé négociée en
  RSA), compressé, numéros de requête ordonnés, sessions d'environ 30 minutes, jeton de reconnexion
  renouvelé à chaque connexion.
- Bibliothèques non officielles : **Pawnote** (TypeScript, active, v1.6.x) et **pronotepy**
  (Python, maintenance uniquement). Toutes deux ciblent surtout les comptes élève/parent.
- Aucune solution Moodle-Pronote maintenue n'a été trouvée.

## Options

| # | Option | Intérêt | Limites | Complexité |
|---|---|---|---|---|
| 1 | Fichiers (export CSV notes, import listes) | Officiel, stable | Manuel, via client Pronote ; coefficient non importable | Faible |
| 2 | iCal de l'EDT Pronote | Officiel, automatisable | Lecture seule, EDT uniquement | Faible |
| 3 | Protocole interne réimplémenté en PHP (C) | Plugin autonome, contrôle total | Crypto/protocole à porter, casse à chaque version | Très élevée |
| 4 | Sidecar Node + Pawnote (B) | Protocole délégué à une lib maintenue, plugin simple | Service à héberger, endpoints enseignant à écrire | Moyenne |
| 5 | Web Service officiel | Officiel, robuste | Réservé à Pronote Campus / partenaires | Accès bloquant |
| 6 | SSO seul + lien profond | Simple | Aucun échange de données | Faible |

## Comparaison B / C

Les deux options offrent le **même plafond fonctionnel** : tout ce que l'enseignant peut faire dans
son espace web Pronote (lecture EDT, cahier de textes, listes, absences, notes ; écriture de notes,
de contenus, de travail à faire).

**B - Sidecar Node + Pawnote**
- Plus : chiffrement/session délégués et suivis par la communauté ; plugin PHP simple et testable ;
  prototype rapide ; contributions possibles en amont.
- Moins : service supplémentaire à héberger et sécuriser ; dépendance au rythme de Pawnote (GPL-3,
  compatible Moodle) ; support enseignant à étendre.

**C - PHP natif**
- Plus : aucune infrastructure supplémentaire ; publiable tel quel sur moodle.org.
- Moins : 3 à 5 fois plus de code ; maintenance lourde et solitaire à chaque évolution de Pronote.

**Décision : B**, derrière une interface `connector` permettant d'ajouter C plus tard, avec le
connecteur « fichiers » comme repli officiel.

## Limites communes

- Fonctions réservées au **client Pronote** Windows (ex. import de notes par presse-papiers) :
  inaccessibles par protocole, d'où le connecteur fichiers.
- Zone grise vis-à-vis des conditions d'utilisation d'Index Education : écriture de notes
  désactivée par défaut, activation explicite par l'administrateur.
- Rapprochement des élèves Pronote/Moodle : par nom/prénom (+ date de naissance si disponible) ou
  par un identifiant commun importé (idnumber).

## Risques et mitigations

| Risque | Mitigation |
|---|---|
| Changement de protocole Pronote | Mise à jour de Pawnote ; tests d'intégration sur un serveur de démo |
| Endpoints enseignant absents de Pawnote | Module isolé `sidecar/src/teacher/` |
| Fuite d'identifiants | Mot de passe jamais stocké ; jeton chiffré ; sidecar en local + HMAC |
| Double authentification Pronote | Gestion du PIN / appareil au premier lien de compte (TODO) |
| Charge sur le serveur Pronote | Cache de session 25 min, tâche planifiée espacée, fenêtre de synchro limitée |

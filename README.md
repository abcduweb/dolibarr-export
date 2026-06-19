# AccountingExport — Module Dolibarr

Module custom Dolibarr d'export comptable : journaux de ventes/achats, grand livre, balance N/N-1, récapitulatif TVA, règlements, et export **FEC conforme DGFiP** (article A47 A-1 du LPF), avec un vérificateur de conformité intégré.

Conçu pour fonctionner aussi bien en environnement complet (Composer, PhpSpreadsheet) qu'en hébergement mutualisé sans accès Composer (export CSV de repli automatique).

## Sommaire

- [Fonctionnalités](#fonctionnalités)
- [Prérequis](#prérequis)
- [Installation](#installation)
- [Configuration](#configuration)
- [Utilisation](#utilisation)
- [Export FEC et vérification de conformité](#export-fec-et-vérification-de-conformité)
- [Architecture](#architecture)
- [Compatibilité](#compatibilité)
- [Limitations connues](#limitations-connues)
- [Historique des versions](#historique-des-versions)

## Fonctionnalités

| Export | Source | Formats |
|---|---|---|
| Journal des ventes | Factures clients (`llx_facture`) | Aperçu, Excel/CSV |
| Journal des achats | Factures fournisseurs (`llx_facture_fourn`) | Aperçu, Excel/CSV |
| Grand livre | Écritures comptables (`llx_accounting_bookkeeping`) | Aperçu, Excel/CSV |
| Balance N / N-1 | Écritures comptables | Excel/CSV |
| Récapitulatif TVA | Factures clients/fournisseurs | Excel/CSV |
| Règlements | Paiements clients + fournisseurs | Aperçu, Excel/CSV |
| **FEC** | Reconstruit depuis factures + règlements | Texte plat (norme DGFiP) |

Autres caractéristiques :

- **Export Excel natif (.xlsx)** via PhpSpreadsheet si disponible, avec repli automatique en **CSV** (UTF-8, BOM, séparateur `;`) sinon — compatible hébergement mutualisé sans Composer.
- **Numéros de compte formatés** selon le plan comptable (complétés à droite, ex. 6 chiffres → `411` devient `411000`), longueur configurable.
- **Montants au format monétaire** (€) dans les exports Excel, alignement façon "Format Comptabilité".
- **Mapping PCG configurable** : comptes clients/fournisseurs/ventes/achats/TVA (20 %, 10 %, 5,5 %, 2,1 %)/banque.
- **Codes journaux configurables** (VTE, ACH, BAN, OD par défaut).
- **Exercices comptables réels** : les raccourcis de période lisent les exercices configurés dans Dolibarr (`Comptabilité > Configuration > Exercices comptables`), y compris un premier exercice à cheval sur deux années civiles.
- **Vérificateur de conformité FEC intégré** (`fec_check.php`) : analyse structurelle ligne par ligne (séparateur, colonnes, dates, équilibre débit/crédit par écriture et global, nomenclature du nom de fichier), avec localisation précise des anomalies.
- **FEC conforme** : exclusion automatique des factures brouillon (non validées), réconciliation TVA fiabilisée sur les totaux d'en-tête de facture, séparateur tabulation/pipe uniquement (jamais point-virgule).
- Compatibilité CSRF Dolibarr 14 → 22 (contournement de la suppression de `checkToken()` en v22).

## Prérequis

- Dolibarr **17 à 22** (testé en production sur 22.0.3).
- PHP 7.4 ou supérieur.
- Module **Comptabilité** Dolibarr activé (requis pour Grand livre et Balance ; Ventes/Achats/FEC fonctionnent aussi sans, en reconstruisant depuis les factures).
- *Optionnel* : [PhpSpreadsheet](https://github.com/PHPOffice/PhpSpreadsheet) (`composer require phpoffice/phpspreadsheet`) pour générer du vrai `.xlsx`. Sans Composer disponible (cas fréquent en hébergement mutualisé type o2switch), le module bascule automatiquement sur un export CSV complet.

## Installation

1. Télécharger/cloner ce dépôt dans `htdocs/custom/accountingexport/` de votre installation Dolibarr.
2. Aller dans `Configuration > Modules`, rechercher **AccountingExport**, et l'activer.
3. Aller dans `Comptabilité > AccountingExport > Configuration` pour ajuster le mapping PCG si besoin (des valeurs par défaut au format PCG français standard sont préconfigurées).
4. (Optionnel) Installer PhpSpreadsheet via Composer à la racine de Dolibarr pour activer l'export `.xlsx` natif :
   ```bash
   composer require phpoffice/phpspreadsheet
   ```
5. Renseigner le champ **SIREN** de votre société (`Accueil > Configuration > Société/Organisation`) — nécessaire pour la nomenclature du fichier FEC (`SIRENFECAAAAMMJJ.txt`).

Après toute mise à jour des fichiers (déploiement FTP ou `git pull`), penser à vider le cache OPcache/LiteSpeed côté hébergeur si applicable.

## Configuration

Page `Comptabilité > AccountingExport > Configuration` (admin uniquement) :

- **Mapping PCG** : comptes clients (411xxx), fournisseurs (401xxx), ventes (7xxxxx), achats (6xxxxx), TVA collectée/déductible par taux, banque (512xxx).
- **Format numéro de compte** : nombre de chiffres pour compléter les comptes à droite dans les exports Excel/CSV (défaut : 6). N'affecte pas le FEC.
- **Codes journaux** : VTE / ACH / BAN / OD par défaut, personnalisables.
- **Séparateur FEC** : tabulation (recommandé) ou pipe.
- Indicateurs de diagnostic : statut du module Comptabilité, disponibilité de PhpSpreadsheet, SIREN configuré.

## Utilisation

Page principale `Comptabilité > Export comptabilité` :

1. Choisir la période (raccourcis dynamiques basés sur vos exercices comptables réels, ou saisie manuelle libre).
2. Choisir le type d'export et le statut des factures à inclure.
3. **Prévisualiser** : aperçu des 10 premières lignes (disponible pour Ventes, Achats, Grand livre, Règlements).
4. **Télécharger Excel** : génère le fichier complet (.xlsx ou .csv selon disponibilité de PhpSpreadsheet).
5. **Exporter FEC** : génère le fichier FEC pour la période sélectionnée.
6. **Vérifier conformité FEC** : analyse un fichier FEC généré (ou tout autre) et liste les anomalies détectées, ligne par ligne.

## Export FEC et vérification de conformité

Le FEC généré respecte les 18 colonnes et l'ordre imposés par l'article A47 A-1 du LPF (`JournalCode`, `JournalLib`, `EcritureNum`, `EcritureDate`, `CompteNum`, `CompteLib`, `CompAuxNum`, `CompAuxLib`, `PieceRef`, `PieceDate`, `EcritureLib`, `Debit`, `Credit`, `EcritureLet`, `DateLet`, `ValidDate`, `Montantdevise`, `Idevise`), avec séparateur tabulation ou pipe, dates `AAAAMMJJ`, montants à virgule décimale, et BOM UTF-8.

Le vérificateur intégré (`fec_check.php`) effectue un contrôle **structurel local** : séparateur, en-têtes, format des dates, champs obligatoires, équilibre débit/crédit par écriture et global, nomenclature du nom de fichier.

> ⚠️ Ce contrôle local ne remplace pas le logiciel officiel **Test Compta Demat** de la DGFiP (gratuit, espace professionnel sur [impots.gouv.fr](https://www.impots.gouv.fr)), qui reste la référence en cas de contrôle fiscal. À utiliser en complément.

## Architecture

```
custom/accountingexport/
├── accountingexport_page.php       Page principale (formulaire + prévisualisation)
├── export_excel.php                Génération Excel (XLSX ou CSV de repli)
├── export_fec.php                  Génération du fichier FEC
├── fec_check.php                   Vérificateur de conformité FEC
├── diag.php                        Diagnostic environnement (PHP, PhpSpreadsheet)
├── diag_sql.php                    Diagnostic données (factures par année/statut)
├── admin/
│   └── setup.php                   Page de configuration admin
├── core/modules/
│   └── modAccountingExport.class.php   Déclaration du module (droits, menus)
├── lib/
│   ├── accountingexport.lib.php        Requêtes SQL et fonctions de formatage
│   ├── accountingexport_fec.lib.php    Construction des lignes FEC
│   └── accountingexport_tva.lib.php    Agrégation TVA par taux
└── langs/fr_FR/
    └── accountingexport.lang
```

> `diag.php` et `diag_sql.php` ne sont pas protégés par l'authentification Dolibarr — à retirer du serveur en production après usage, ou à restreindre par votre configuration serveur (`.htaccess`).

## Compatibilité

- Testé en production sur **Dolibarr 22.0.3**, hébergement mutualisé **o2switch** (LiteSpeed), sans Composer.
- Compatible PHP 7.4 → 8.3.
- Le module détecte et contourne automatiquement la suppression de `checkToken()` en Dolibarr 22 pour la vérification CSRF.

## Limitations connues

- Le récapitulatif TVA n'a pas d'aperçu ligne-par-ligne (utiliser directement "Télécharger Excel").
- Sans le module Comptabilité Dolibarr activé, Grand livre et Balance sont indisponibles (Ventes/Achats/FEC restent fonctionnels en reconstruisant depuis les factures).
- Le détail de TVA par taux dans le FEC se replie automatiquement sur un total global si la ventilation par taux échoue ou ne réconcilie pas avec l'en-tête de facture — comptablement correct, mais moins détaillé dans ce cas précis.

## Historique des versions

| Version | Changements principaux |
|---|---|
| 1.3.1 | Format monétaire (€) sur les colonnes de montant en export Excel |
| 1.3.0 | Formatage des numéros de compte selon le plan comptable (nb de chiffres configurable) |
| 1.2.2 | Correction de la récupération du SIREN (lecture de `$mysoc` au lieu d'un tiers au hasard) |
| 1.2.1 | Réconciliation robuste du détail TVA dans le FEC sur les totaux d'en-tête de facture |
| 1.2.0 | Raccourcis de période basés sur les exercices comptables réels configurés dans Dolibarr |
| 1.1.0 | Vérificateur de conformité FEC intégré ; exclusion des factures brouillon du FEC |
| 1.0.7 | Correction du nom de table `paiementfourn_facturefourn` (Achats, Règlements, FEC) |
| 1.0.6 | Correction de la colonne `date_validated` (Ventes, Achats) |
| 1.0.5 | Correction des colonnes SQL du Grand livre (`code_journal`, `label_operation`) |
| 1.0.4 | Boutons d'export liés en temps réel à l'état du formulaire (`formaction`) |
| 1.0.3 | Gestion de la prévisualisation pour le type Balance |
| 1.0.2 | Implémentation Grand livre/Balance dans le fallback CSV ; compatibilité `checkToken()` v22 |
| 1.0.1 | Version initiale |

## Licence

Usage interne — [ABCduWeb](https://abcduweb.fr).

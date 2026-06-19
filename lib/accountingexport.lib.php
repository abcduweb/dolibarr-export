<?php
/* custom/accountingexport/lib/accountingexport.lib.php */

if (!defined('INC_FROM_DOLIBARR')) { exit('Accès refusé'); }

/* ─────────────────────────────────────────────────────────────────────────────
   DÉTECTION PHPSPREADSHEET
   Cherche autoload.php dans tous les emplacements connus de Dolibarr
   ───────────────────────────────────────────────────────────────────────────── */

/**
 * Tente de charger PhpSpreadsheet depuis les emplacements possibles de Dolibarr.
 *
 * @return bool  true si PhpSpreadsheet est disponible, false sinon
 */
function accountingexport_load_spreadsheet()
{
    if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        return true;
    }

    $candidates = array(
        DOL_DOCUMENT_ROOT.'/vendor/autoload.php',
        DOL_DOCUMENT_ROOT.'/includes/vendor/autoload.php',
        DOL_DOCUMENT_ROOT.'/../vendor/autoload.php',
        dirname(DOL_DOCUMENT_ROOT).'/vendor/autoload.php',
    );

    foreach ($candidates as $path) {
        if (file_exists($path)) {
            @include_once $path;
            if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
                return true;
            }
        }
    }
    return false;
}

/* ─────────────────────────────────────────────────────────────────────────────
   MAPPING PCG
   ───────────────────────────────────────────────────────────────────────────── */

/**
 * Retourne le mapping des comptes PCG depuis la configuration Dolibarr.
 *
 * @param  Conf  $conf  Objet configuration
 * @return array        Tableau clé => numéro de compte
 */
function accountingexport_get_pcg_mapping($conf)
{
    $d = array(
        'client'      => '411000',
        'fournisseur' => '401000',
        'ventes'      => '706000',
        'achats'      => '607000',
        'tva20'       => '445710',
        'tva10'       => '445711',
        'tva55'       => '445712',
        'tva21'       => '445713',
        'tva_ded20'   => '445660',
        'tva_ded10'   => '445661',
        'tva_ded55'   => '445662',
        'banque'      => '512000',
    );

    $map_const = array(
        'client'      => 'ACCOUNTINGEXPORT_ACCOUNT_CLIENT',
        'fournisseur' => 'ACCOUNTINGEXPORT_ACCOUNT_FOURNISSEUR',
        'ventes'      => 'ACCOUNTINGEXPORT_ACCOUNT_VENTES',
        'achats'      => 'ACCOUNTINGEXPORT_ACCOUNT_ACHATS',
        'tva20'       => 'ACCOUNTINGEXPORT_ACCOUNT_TVA20',
        'tva10'       => 'ACCOUNTINGEXPORT_ACCOUNT_TVA10',
        'tva55'       => 'ACCOUNTINGEXPORT_ACCOUNT_TVA55',
        'tva21'       => 'ACCOUNTINGEXPORT_ACCOUNT_TVA21',
        'tva_ded20'   => 'ACCOUNTINGEXPORT_ACCOUNT_TVA_DED20',
        'tva_ded10'   => 'ACCOUNTINGEXPORT_ACCOUNT_TVA_DED10',
        'tva_ded55'   => 'ACCOUNTINGEXPORT_ACCOUNT_TVA_DED55',
        'banque'      => 'ACCOUNTINGEXPORT_ACCOUNT_BANQUE',
    );

    foreach ($map_const as $key => $const) {
        if (!empty($conf->global->$const)) {
            $d[$key] = $conf->global->$const;
        }
    }
    return $d;
}

/**
 * Clé PCG pour la TVA collectée selon le taux.
 *
 * @param  float  $taux  Taux TVA
 * @return string
 */
function accountingexport_get_tva_key($taux)
{
    $t = (float) $taux;
    if ($t == 20)  return 'tva20';
    if ($t == 10)  return 'tva10';
    if ($t == 5.5) return 'tva55';
    if ($t == 2.1) return 'tva21';
    return 'tva20';
}

/**
 * Clé PCG pour la TVA déductible selon le taux.
 *
 * @param  float  $taux  Taux TVA
 * @return string
 */
function accountingexport_get_tva_ded_key($taux)
{
    $t = (float) $taux;
    if ($t == 20)  return 'tva_ded20';
    if ($t == 10)  return 'tva_ded10';
    if ($t == 5.5) return 'tva_ded55';
    return 'tva_ded20';
}

/* ─────────────────────────────────────────────────────────────────────────────
   REQUÊTES SQL
   ───────────────────────────────────────────────────────────────────────────── */

/**
 * Récupère les factures clients sur la période.
 *
 * @param  DoliDB  $db          Base de données
 * @param  string  $date_debut  YYYY-MM-DD
 * @param  string  $date_fin    YYYY-MM-DD
 * @param  int     $statut      -1 = tous
 * @param  int     $entity      0 = entité courante
 * @param  int     $limit       0 = illimité
 * @param  int     $offset      Décalage pagination
 * @return array
 * @throws Exception
 */
function accountingexport_get_factures_clients($db, $date_debut, $date_fin, $statut = -1, $entity = 0, $limit = 0, $offset = 0)
{
    $ef = $entity > 0 ? 'f.entity = '.((int)$entity) : 'f.entity IN ('.getEntity('invoice').')';
    $sf = $statut >= 0 ? ' AND f.fk_statut = '.((int)$statut) : '';
    $lc = $limit > 0 ? ' LIMIT '.((int)$limit).' OFFSET '.((int)$offset) : '';

    $sql = "SELECT f.rowid, f.ref AS num_facture, f.datef AS date_facture,
                   f.fk_statut AS statut, f.type,
                   f.total_ht, f.total_tva, f.total_ttc,
                   f.multicurrency_total_ttc, f.multicurrency_code AS devise,
                   s.nom AS client_nom, s.code_client,
                   p.ref AS mode_reglement, p.datep AS date_paiement,
                   bk.subledger_account AS compte_tiers_compta,
                   bk.doc_ref AS ref_ecriture,
                   bk.date_validated AS transfert_compta
            FROM ".MAIN_DB_PREFIX."facture f
            LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = f.fk_soc
            LEFT JOIN ".MAIN_DB_PREFIX."paiement_facture pfj ON pfj.fk_facture = f.rowid
            LEFT JOIN ".MAIN_DB_PREFIX."paiement p ON p.rowid = pfj.fk_paiement
            LEFT JOIN ".MAIN_DB_PREFIX."accounting_bookkeeping bk
                   ON bk.fk_doc = f.rowid AND bk.doc_type = 'customer_invoice'
            WHERE ".$ef."
              AND f.datef BETWEEN '".$db->escape($date_debut)."' AND '".$db->escape($date_fin)."'
              ".$sf."
            GROUP BY f.rowid, s.nom, s.code_client, p.ref, p.datep,
                     bk.subledger_account, bk.doc_ref, bk.date_validated
            ORDER BY f.datef ASC, f.ref ASC".$lc;

    $res = $db->query($sql);
    if (!$res) throw new Exception('SQL factures clients : '.$db->lasterror());
    $rows = array();
    while ($o = $db->fetch_object($res)) { $rows[] = $o; }
    $db->free($res);
    return $rows;
}

/**
 * Lignes TVA d'une facture client, groupées par taux.
 *
 * @param  DoliDB  $db
 * @param  int     $fk_facture
 * @return array
 * @throws Exception
 */
function accountingexport_get_tva_facture($db, $fk_facture)
{
    $sql = "SELECT tva_tx, tva_npr,
                   SUM(total_ht) AS base_ht, SUM(total_tva) AS montant_tva
            FROM ".MAIN_DB_PREFIX."facturedet
            WHERE fk_facture = ".((int)$fk_facture)."
            GROUP BY tva_tx, tva_npr ORDER BY tva_tx DESC";

    $res = $db->query($sql);
    if (!$res) throw new Exception('SQL TVA facture : '.$db->lasterror());
    $rows = array();
    while ($o = $db->fetch_object($res)) { $rows[] = $o; }
    $db->free($res);
    return $rows;
}

/**
 * Récupère les factures fournisseurs sur la période.
 *
 * @param  DoliDB  $db
 * @param  string  $date_debut
 * @param  string  $date_fin
 * @param  int     $statut
 * @param  int     $entity
 * @param  int     $limit
 * @param  int     $offset
 * @return array
 * @throws Exception
 */
function accountingexport_get_factures_fournisseurs($db, $date_debut, $date_fin, $statut = -1, $entity = 0, $limit = 0, $offset = 0)
{
    $ef = $entity > 0 ? 'f.entity = '.((int)$entity) : 'f.entity IN ('.getEntity('supplier_invoice').')';
    $sf = $statut >= 0 ? ' AND f.fk_statut = '.((int)$statut) : '';
    $lc = $limit > 0 ? ' LIMIT '.((int)$limit).' OFFSET '.((int)$offset) : '';

    $sql = "SELECT f.rowid, f.ref AS num_facture, f.ref_supplier AS ref_fournisseur,
                   f.datef AS date_facture, f.fk_statut AS statut,
                   f.total_ht, f.total_tva, f.total_ttc,
                   f.multicurrency_code AS devise,
                   s.nom AS fournisseur_nom, s.code_fournisseur,
                   p.ref AS mode_reglement, p.datep AS date_paiement,
                   bk.subledger_account AS compte_tiers_compta,
                   bk.date_validated AS transfert_compta
            FROM ".MAIN_DB_PREFIX."facture_fourn f
            LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = f.fk_soc
            LEFT JOIN ".MAIN_DB_PREFIX."paiementfourn_facturefourn pfj ON pfj.fk_facturefourn = f.rowid
            LEFT JOIN ".MAIN_DB_PREFIX."paiementfourn p ON p.rowid = pfj.fk_paiementfourn
            LEFT JOIN ".MAIN_DB_PREFIX."accounting_bookkeeping bk
                   ON bk.fk_doc = f.rowid AND bk.doc_type = 'supplier_invoice'
            WHERE ".$ef."
              AND f.datef BETWEEN '".$db->escape($date_debut)."' AND '".$db->escape($date_fin)."'
              ".$sf."
            GROUP BY f.rowid, s.nom, s.code_fournisseur, p.ref, p.datep,
                     bk.subledger_account, bk.date_validated
            ORDER BY f.datef ASC, f.ref ASC".$lc;

    $res = $db->query($sql);
    if (!$res) throw new Exception('SQL factures fournisseurs : '.$db->lasterror());
    $rows = array();
    while ($o = $db->fetch_object($res)) { $rows[] = $o; }
    $db->free($res);
    return $rows;
}

/**
 * Lignes TVA d'une facture fournisseur.
 *
 * @param  DoliDB  $db
 * @param  int     $fk_facture_fourn
 * @return array
 * @throws Exception
 */
function accountingexport_get_tva_facture_fourn($db, $fk_facture_fourn)
{
    $sql = "SELECT tva_tx,
                   SUM(total_ht) AS base_ht, SUM(tva) AS montant_tva
            FROM ".MAIN_DB_PREFIX."facture_fourn_det
            WHERE fk_facture_fourn = ".((int)$fk_facture_fourn)."
            GROUP BY tva_tx ORDER BY tva_tx DESC";

    $res = $db->query($sql);
    if (!$res) throw new Exception('SQL TVA fournisseur : '.$db->lasterror());
    $rows = array();
    while ($o = $db->fetch_object($res)) { $rows[] = $o; }
    $db->free($res);
    return $rows;
}

/**
 * Récupère les écritures du grand livre (module Accounting requis).
 *
 * @param  DoliDB  $db
 * @param  string  $date_debut
 * @param  string  $date_fin
 * @param  int     $entity
 * @return array
 * @throws Exception
 */
function accountingexport_get_grand_livre($db, $date_debut, $date_fin, $entity = 0)
{
    $ef = $entity > 0 ? 'bk.entity = '.((int)$entity) : 'bk.entity IN ('.getEntity('accountingjournalentry').')';

    $sql = "SELECT bk.doc_date AS date_ecriture, bk.code_journal AS journal_code,
                   bk.piece_num AS num_ecriture,
                   bk.numero_compte AS compte, bk.label_compte AS intitule_compte,
                   bk.subledger_account AS compte_auxiliaire,
                   bk.subledger_label AS intitule_auxiliaire,
                   bk.label_operation AS libelle,
                   bk.debit, bk.credit, bk.doc_ref
            FROM ".MAIN_DB_PREFIX."accounting_bookkeeping bk
            WHERE ".$ef."
              AND bk.doc_date BETWEEN '".$db->escape($date_debut)."' AND '".$db->escape($date_fin)."'
            ORDER BY bk.numero_compte ASC, bk.doc_date ASC";

    $res = $db->query($sql);
    if (!$res) throw new Exception('SQL Grand Livre : '.$db->lasterror());

    $rows  = array();
    $solde = array();
    while ($o = $db->fetch_object($res)) {
        $c = $o->compte;
        if (!isset($solde[$c])) $solde[$c] = 0;
        $solde[$c] += ((float)$o->debit - (float)$o->credit);
        $o->solde_cumule = $solde[$c];
        $rows[] = $o;
    }
    $db->free($res);
    return $rows;
}

/**
 * Balance des comptes N et N-1.
 *
 * @param  DoliDB  $db
 * @param  string  $date_debut
 * @param  string  $date_fin
 * @param  int     $entity
 * @return array
 * @throws Exception
 */
function accountingexport_get_balance($db, $date_debut, $date_fin, $entity = 0)
{
    $ef = $entity > 0 ? 'bk.entity = '.((int)$entity) : 'bk.entity IN ('.getEntity('accountingjournalentry').')';

    $sql = "SELECT bk.numero_compte AS compte, bk.label_compte AS intitule,
                   SUM(bk.debit) AS debit_n, SUM(bk.credit) AS credit_n,
                   SUM(bk.debit)-SUM(bk.credit) AS solde_n
            FROM ".MAIN_DB_PREFIX."accounting_bookkeeping bk
            WHERE ".$ef."
              AND bk.doc_date BETWEEN '".$db->escape($date_debut)."' AND '".$db->escape($date_fin)."'
            GROUP BY bk.numero_compte, bk.label_compte
            ORDER BY bk.numero_compte ASC";

    $res = $db->query($sql);
    if (!$res) throw new Exception('SQL Balance : '.$db->lasterror());

    $bal = array();
    while ($o = $db->fetch_object($res)) {
        $bal[$o->compte] = array(
            'compte' => $o->compte, 'intitule' => $o->intitule,
            'debit_n' => (float)$o->debit_n, 'credit_n' => (float)$o->credit_n,
            'solde_n' => (float)$o->solde_n,
            'debit_n1' => 0, 'credit_n1' => 0, 'solde_n1' => 0,
        );
    }
    $db->free($res);

    // N-1
    $dd1 = date('Y-m-d', strtotime($date_debut.' -1 year'));
    $df1 = date('Y-m-d', strtotime($date_fin.' -1 year'));
    $sql1 = "SELECT bk.numero_compte AS compte,
                    SUM(bk.debit) AS debit_n1, SUM(bk.credit) AS credit_n1,
                    SUM(bk.debit)-SUM(bk.credit) AS solde_n1
             FROM ".MAIN_DB_PREFIX."accounting_bookkeeping bk
             WHERE ".$ef."
               AND bk.doc_date BETWEEN '".$db->escape($dd1)."' AND '".$db->escape($df1)."'
             GROUP BY bk.numero_compte";
    $res1 = $db->query($sql1);
    if ($res1) {
        while ($o = $db->fetch_object($res1)) {
            if (isset($bal[$o->compte])) {
                $bal[$o->compte]['debit_n1']  = (float)$o->debit_n1;
                $bal[$o->compte]['credit_n1'] = (float)$o->credit_n1;
                $bal[$o->compte]['solde_n1']  = (float)$o->solde_n1;
            }
        }
        $db->free($res1);
    }
    return array_values($bal);
}

/**
 * Règlements clients et fournisseurs sur la période.
 *
 * @param  DoliDB  $db
 * @param  string  $date_debut
 * @param  string  $date_fin
 * @param  int     $entity
 * @return array
 * @throws Exception
 */
function accountingexport_get_reglements($db, $date_debut, $date_fin, $entity = 0)
{
    $efc = $entity > 0 ? 'p.entity = '.((int)$entity) : 'p.entity IN ('.getEntity('payment').')';
    $eff = $entity > 0 ? 'p.entity = '.((int)$entity) : 'p.entity IN ('.getEntity('supplier_payment').')';

    $sql = "SELECT 'client' AS type_tiers, p.datep AS date_reglement,
                   p.ref AS mode_reglement, p.num_paiement AS reference,
                   p.amount, s.nom AS tiers, f.ref AS num_facture,
                   ba.ref AS compte_banque
            FROM ".MAIN_DB_PREFIX."paiement p
            JOIN ".MAIN_DB_PREFIX."paiement_facture pf ON pf.fk_paiement = p.rowid
            JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = pf.fk_facture
            JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = f.fk_soc
            LEFT JOIN ".MAIN_DB_PREFIX."bank b ON b.rowid = p.fk_bank
            LEFT JOIN ".MAIN_DB_PREFIX."bank_account ba ON ba.rowid = b.fk_account
            WHERE ".$efc."
              AND p.datep BETWEEN '".$db->escape($date_debut)."' AND '".$db->escape($date_fin)."'
            UNION ALL
            SELECT 'fournisseur' AS type_tiers, p.datep AS date_reglement,
                   p.ref AS mode_reglement, p.num_paiement AS reference,
                   p.amount, s.nom AS tiers, f.ref AS num_facture,
                   ba.ref AS compte_banque
            FROM ".MAIN_DB_PREFIX."paiementfourn p
            JOIN ".MAIN_DB_PREFIX."paiementfourn_facturefourn pff ON pff.fk_paiementfourn = p.rowid
            JOIN ".MAIN_DB_PREFIX."facture_fourn f ON f.rowid = pff.fk_facturefourn
            JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = f.fk_soc
            LEFT JOIN ".MAIN_DB_PREFIX."bank b ON b.rowid = p.fk_bank
            LEFT JOIN ".MAIN_DB_PREFIX."bank_account ba ON ba.rowid = b.fk_account
            WHERE ".$eff."
              AND p.datep BETWEEN '".$db->escape($date_debut)."' AND '".$db->escape($date_fin)."'
            ORDER BY date_reglement ASC";

    $res = $db->query($sql);
    if (!$res) throw new Exception('SQL Règlements : '.$db->lasterror());
    $rows = array();
    while ($o = $db->fetch_object($res)) { $rows[] = $o; }
    $db->free($res);
    return $rows;
}

/* ─────────────────────────────────────────────────────────────────────────────
   UTILITAIRES
   ───────────────────────────────────────────────────────────────────────────── */

/**
 * Formate un montant à 2 décimales.
 *
 * @param  mixed  $v
 * @return float
 */
function accountingexport_format_montant($v)
{
    return round((float)$v, 2);
}

/**
 * Formate une date en JJ/MM/AAAA.
 *
 * @param  mixed  $date  Timestamp ou chaîne SQL
 * @return string
 */
function accountingexport_format_date($date)
{
    if (empty($date) || $date === '0000-00-00') return '';
    $ts = is_numeric($date) ? (int)$date : strtotime($date);
    return $ts ? date('d/m/Y', $ts) : '';
}

/**
 * Libellé statut facture client.
 *
 * @param  int  $statut
 * @param  int  $type    2 = avoir
 * @return string
 */
function accountingexport_libelle_statut_client($statut, $type = 0)
{
    if ($type == 2) return 'Avoir';
    switch ((int)$statut) {
        case 0: return 'Brouillon';
        case 1: return 'Validée';
        case 2: return 'Payée';
        case 3: return 'Abandonnée';
        default: return 'Inconnu';
    }
}

/**
 * Libellé statut facture fournisseur.
 *
 * @param  int  $statut
 * @return string
 */
function accountingexport_libelle_statut_fourn($statut)
{
    switch ((int)$statut) {
        case 0: return 'Brouillon';
        case 1: return 'Validée';
        case 2: return 'Payée';
        default: return 'Inconnu';
    }
}

/**
 * Retourne le SIRET depuis la table societe.
 *
 * @param  DoliDB  $db
 * @param  Conf    $conf
 * @return string
 */
function accountingexport_get_siret($db, $conf)
{
    // "Ma societe" est deja chargee nativement par Dolibarr dans $mysoc
    // (idprof1 = SIREN pour la France). On l'utilise en priorite : interroger
    // llx_societe sans filtrer sur le bon enregistrement (ex: WHERE entity=X
    // LIMIT 1) peut remonter n'importe quel tiers client/fournisseur au lieu
    // de sa propre societe, et retourner un SIREN vide ou errone.
    global $mysoc;
    if (!empty($mysoc) && !empty($mysoc->idprof1)) {
        return preg_replace('/\s/', '', $mysoc->idprof1);
    }

    // Repli si $mysoc indisponible : on cible explicitement l'enregistrement
    // configure comme "ma societe" (MAIN_INFO_SOCIETE_ID), jamais un tiers au hasard.
    $idsoc = !empty($conf->global->MAIN_INFO_SOCIETE_ID) ? (int)$conf->global->MAIN_INFO_SOCIETE_ID : 0;
    if ($idsoc <= 0) { return ''; }

    $sql = "SELECT siren FROM ".MAIN_DB_PREFIX."societe WHERE rowid = ".$idsoc;
    $res = $db->query($sql);
    if ($res && ($o = $db->fetch_object($res))) {
        $db->free($res);
        return preg_replace('/\s/', '', $o->siren);
    }
    return '';
}

/**
 * Vérifie si le module Accounting a des écritures sur la période.
 *
 * @param  DoliDB  $db
 * @param  string  $date_debut
 * @param  string  $date_fin
 * @return bool
 */
function accountingexport_has_bookkeeping($db, $date_debut, $date_fin)
{
    if (!isModEnabled('accounting')) return false;
    $sql = "SELECT COUNT(*) AS nb FROM ".MAIN_DB_PREFIX."accounting_bookkeeping
            WHERE doc_date BETWEEN '".$db->escape($date_debut)."' AND '".$db->escape($date_fin)."'";
    $res = $db->query($sql);
    if ($res && ($o = $db->fetch_object($res))) {
        $db->free($res);
        return (int)$o->nb > 0;
    }
    return false;
}

/**
 * Journalise un export dans llx_actioncomm.
 *
 * @param  DoliDB   $db
 * @param  User     $user
 * @param  string   $type       'excel' ou 'fec'
 * @param  string   $date_debut
 * @param  string   $date_fin
 * @param  int      $nb_lignes
 * @return void
 */
function accountingexport_log_export($db, $user, $type, $date_debut, $date_fin, $nb_lignes)
{
    $sql = "INSERT INTO ".MAIN_DB_PREFIX."actioncomm
                (datep, datep2, note, fk_user_author, entity)
            VALUES (
                '".$db->idate(dol_now())."',
                '".$db->idate(dol_now())."',
                'Export ".strtoupper($db->escape($type))." ".$db->escape($date_debut)." - ".$db->escape($date_fin)." (".(int)$nb_lignes." lignes)',
                ".((int)$user->id).",
                ".((int)$user->entity)."
            )";
    @$db->query($sql);
}

/**
 * Construit le tableau TVA par taux à partir des lignes d'une facture.
 *
 * @param  array  $lignes_tva  Résultat de accountingexport_get_tva_facture()
 * @param  array  $taux_liste  Liste des taux attendus
 * @return array               [taux => montant_tva]
 */
function accountingexport_tva_map_par_taux($lignes_tva, $taux_liste)
{
    $map = array_fill_keys($taux_liste, 0);
    foreach ($lignes_tva as $l) {
        $t = (float)$l->tva_tx;
        if (array_key_exists($t, $map)) {
            $map[$t] += (float)$l->montant_tva;
        }
    }
    return $map;
}

/**
 * Label colonne TVA pour l'en-tête Excel.
 *
 * @param  float   $taux
 * @param  string  $type  'collectee' ou 'deductible'
 * @return string
 */
function accountingexport_tva_col_label($taux, $type = 'collectee')
{
    $pfx  = $type === 'deductible' ? 'TVA ded. ' : 'TVA ';
    $t    = (float)$taux;
    $disp = ($t == floor($t)) ? (int)$t : number_format($t, 1, '.', '');
    return $pfx.$disp.'%';
}

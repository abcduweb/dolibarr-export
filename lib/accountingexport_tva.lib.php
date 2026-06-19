<?php
/* custom/accountingexport/lib/accountingexport_tva.lib.php */

if (!defined('INC_FROM_DOLIBARR')) { exit('Accès refusé'); }

/**
 * Taux TVA présents sur la période - factures clients.
 *
 * @param  DoliDB  $db
 * @param  string  $date_debut
 * @param  string  $date_fin
 * @return float[]
 * @throws Exception
 */
function accountingexport_tva_get_taux_utilises_clients($db, $date_debut, $date_fin)
{
    $sql = "SELECT DISTINCT fd.tva_tx
            FROM ".MAIN_DB_PREFIX."facturedet fd
            JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = fd.fk_facture
            WHERE f.datef BETWEEN '".$db->escape($date_debut)."' AND '".$db->escape($date_fin)."'
              AND f.fk_statut > 0
            ORDER BY fd.tva_tx DESC";

    $res = $db->query($sql);
    if (!$res) throw new Exception('SQL taux TVA clients : '.$db->lasterror());
    $taux = array();
    while ($o = $db->fetch_object($res)) { $taux[] = (float)$o->tva_tx; }
    $db->free($res);
    return $taux ?: array(20.0);
}

/**
 * Taux TVA présents sur la période - factures fournisseurs.
 *
 * @param  DoliDB  $db
 * @param  string  $date_debut
 * @param  string  $date_fin
 * @return float[]
 * @throws Exception
 */
function accountingexport_tva_get_taux_utilises_fournisseurs($db, $date_debut, $date_fin)
{
    $sql = "SELECT DISTINCT ffd.tva_tx
            FROM ".MAIN_DB_PREFIX."facture_fourn_det ffd
            JOIN ".MAIN_DB_PREFIX."facture_fourn f ON f.rowid = ffd.fk_facture_fourn
            WHERE f.datef BETWEEN '".$db->escape($date_debut)."' AND '".$db->escape($date_fin)."'
              AND f.fk_statut > 0
            ORDER BY ffd.tva_tx DESC";

    $res = $db->query($sql);
    if (!$res) throw new Exception('SQL taux TVA fournisseurs : '.$db->lasterror());
    $taux = array();
    while ($o = $db->fetch_object($res)) { $taux[] = (float)$o->tva_tx; }
    $db->free($res);
    return $taux ?: array(20.0);
}

/**
 * Récapitulatif TVA collectée par taux.
 *
 * @param  DoliDB  $db
 * @param  string  $date_debut
 * @param  string  $date_fin
 * @return array
 * @throws Exception
 */
function accountingexport_tva_recap_collectee($db, $date_debut, $date_fin)
{
    $sql = "SELECT fd.tva_tx, fd.tva_npr,
                   COUNT(DISTINCT fd.fk_facture) AS nb_factures,
                   SUM(fd.total_ht) AS base_ht, SUM(fd.total_tva) AS montant_tva
            FROM ".MAIN_DB_PREFIX."facturedet fd
            JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = fd.fk_facture
            WHERE f.datef BETWEEN '".$db->escape($date_debut)."' AND '".$db->escape($date_fin)."'
              AND f.fk_statut >= 1 AND f.type NOT IN (2)
            GROUP BY fd.tva_tx, fd.tva_npr ORDER BY fd.tva_tx DESC";

    $res = $db->query($sql);
    if (!$res) throw new Exception('SQL Récap TVA collectée : '.$db->lasterror());
    $rows = array();
    while ($o = $db->fetch_object($res)) {
        $rows[] = array(
            'taux'        => (float)$o->tva_tx,
            'regime'      => (int)$o->tva_npr === 1 ? 'Autoliquidation (AL)' : ((float)$o->tva_tx == 0 ? 'Exonéré / 0%' : 'Régime normal'),
            'base_ht'     => round((float)$o->base_ht, 2),
            'montant_tva' => round((float)$o->montant_tva, 2),
            'nb_factures' => (int)$o->nb_factures,
        );
    }
    $db->free($res);
    return $rows;
}

/**
 * Récapitulatif TVA déductible par taux.
 *
 * @param  DoliDB  $db
 * @param  string  $date_debut
 * @param  string  $date_fin
 * @return array
 * @throws Exception
 */
function accountingexport_tva_recap_deductible($db, $date_debut, $date_fin)
{
    $sql = "SELECT ffd.tva_tx,
                   COUNT(DISTINCT ffd.fk_facture_fourn) AS nb_factures,
                   SUM(ffd.total_ht) AS base_ht, SUM(ffd.tva) AS montant_tva
            FROM ".MAIN_DB_PREFIX."facture_fourn_det ffd
            JOIN ".MAIN_DB_PREFIX."facture_fourn f ON f.rowid = ffd.fk_facture_fourn
            WHERE f.datef BETWEEN '".$db->escape($date_debut)."' AND '".$db->escape($date_fin)."'
              AND f.fk_statut >= 1
            GROUP BY ffd.tva_tx ORDER BY ffd.tva_tx DESC";

    $res = $db->query($sql);
    if (!$res) throw new Exception('SQL Récap TVA déductible : '.$db->lasterror());
    $rows = array();
    while ($o = $db->fetch_object($res)) {
        $rows[] = array(
            'taux'        => (float)$o->tva_tx,
            'regime'      => (float)$o->tva_tx == 0 ? 'Exonéré / 0%' : 'Régime normal',
            'base_ht'     => round((float)$o->base_ht, 2),
            'montant_tva' => round((float)$o->montant_tva, 2),
            'nb_factures' => (int)$o->nb_factures,
        );
    }
    $db->free($res);
    return $rows;
}

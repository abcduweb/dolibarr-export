<?php
/* custom/accountingexport/lib/accountingexport_fec.lib.php */

if (!defined('INC_FROM_DOLIBARR')) { exit('Accès refusé'); }

require_once DOL_DOCUMENT_ROOT.'/custom/accountingexport/lib/accountingexport.lib.php';

/**
 * Formate une date pour le FEC : AAAAMMJJ.
 *
 * @param  mixed  $date
 * @return string
 */
function fec_format_date($date)
{
    if (empty($date) || $date === '0000-00-00') return '';
    $ts = is_numeric($date) ? (int)$date : strtotime($date);
    return $ts ? date('Ymd', $ts) : '';
}

/**
 * Formate un montant FEC : décimale avec virgule, 2 décimales.
 *
 * @param  mixed  $v
 * @return string  Ex: "1200,00"
 */
function fec_format_montant($v)
{
    return number_format(round((float)$v, 2), 2, ',', '');
}

/**
 * Génère un numéro d'écriture séquentiel.
 *
 * @param  string  $journal_code
 * @param  string  $annee
 * @param  int     $seq
 * @return string  Ex: "VTE-2024-00001"
 */
function fec_gen_num_ecriture($journal_code, $annee, $seq)
{
    return strtoupper($journal_code).'-'.$annee.'-'.str_pad($seq, 5, '0', STR_PAD_LEFT);
}

/**
 * Construit une ligne FEC (18 colonnes normalisées).
 *
 * @param  string  $journal_code
 * @param  string  $journal_lib
 * @param  string  $ecriture_num
 * @param  string  $ecriture_date    AAAAMMJJ
 * @param  string  $compte_num
 * @param  string  $compte_lib
 * @param  string  $comp_aux_num
 * @param  string  $comp_aux_lib
 * @param  string  $piece_ref
 * @param  string  $piece_date       AAAAMMJJ
 * @param  string  $ecriture_lib
 * @param  float   $debit
 * @param  float   $credit
 * @param  string  $ecriture_let
 * @param  string  $date_let
 * @param  string  $valid_date       AAAAMMJJ
 * @param  string  $montant_devise
 * @param  string  $idevise
 * @return array
 */
function fec_build_ligne(
    $journal_code, $journal_lib, $ecriture_num, $ecriture_date,
    $compte_num, $compte_lib, $comp_aux_num, $comp_aux_lib,
    $piece_ref, $piece_date, $ecriture_lib,
    $debit, $credit,
    $ecriture_let = '', $date_let = '', $valid_date = '',
    $montant_devise = '', $idevise = ''
) {
    return array(
        'JournalCode'   => strtoupper(trim($journal_code)),
        'JournalLib'    => trim($journal_lib),
        'EcritureNum'   => trim($ecriture_num),
        'EcritureDate'  => trim($ecriture_date),
        'CompteNum'     => preg_replace('/\s/', '', $compte_num),
        'CompteLib'     => trim($compte_lib),
        'CompAuxNum'    => trim($comp_aux_num),
        'CompAuxLib'    => trim($comp_aux_lib),
        'PieceRef'      => trim($piece_ref),
        'PieceDate'     => trim($piece_date),
        'EcritureLib'   => trim($ecriture_lib),
        'Debit'         => fec_format_montant(abs($debit)),
        'Credit'        => fec_format_montant(abs($credit)),
        'EcritureLet'   => trim($ecriture_let),
        'DateLet'       => trim($date_let),
        'ValidDate'     => trim($valid_date ?: $ecriture_date),
        'Montantdevise' => trim($montant_devise),
        'Idevise'       => trim($idevise),
    );
}

/**
 * Génère les lignes FEC du journal des ventes.
 *
 * @param  DoliDB  $db
 * @param  string  $date_debut
 * @param  string  $date_fin
 * @param  array   $pcg
 * @param  Conf    $conf
 * @param  int     &$seq
 * @return array
 * @throws Exception
 */
function fec_get_lignes_ventes($db, $date_debut, $date_fin, $pcg, $conf, &$seq)
{
    $jcode = !empty($conf->global->ACCOUNTINGEXPORT_JOURNAL_VENTES) ? $conf->global->ACCOUNTINGEXPORT_JOURNAL_VENTES : 'VTE';
    $jlib  = 'Journal des ventes';
    $annee = substr($date_debut, 0, 4);

    // Le FEC doit refleter des ecritures definitives : on exclut les factures
    // brouillon (statut 0), pas encore validees, qui peuvent encore changer.
    $factures = accountingexport_get_factures_clients($db, $date_debut, $date_fin, -1, 0);
    $factures = array_values(array_filter($factures, function($f) { return (int)$f->statut !== 0; }));
    $lignes   = array();

    foreach ($factures as $f) {
        $num   = fec_gen_num_ecriture($jcode, $annee, $seq++);
        $dfec  = fec_format_date($f->date_facture);
        $pref  = $f->num_facture;
        $ttc   = round((float)$f->total_ttc, 2);
        $ht    = round((float)$f->total_ht, 2);
        $cpte  = !empty($f->compte_tiers_compta) ? $f->compte_tiers_compta : $pcg['client'];

        // Client (Débit TTC)
        $lignes[] = fec_build_ligne($jcode, $jlib, $num, $dfec,
            $cpte, 'Clients', $f->client_code, $f->client_nom,
            $pref, $dfec, 'Facture '.$pref.' - '.$f->client_nom,
            $ttc, 0, '', '', $dfec, '', '');

        // Lignes TVA et produit, ventilees par taux si possible
        $tva_total = round((float)$f->total_tva, 2);
        try { $tva_lines = accountingexport_get_tva_facture($db, $f->rowid); }
        catch (Exception $e) { $tva_lines = array(); }

        $lignes_tmp = array();
        $somme_ht_detail = 0.0;
        $somme_tva_detail = 0.0;
        foreach ($tva_lines as $tva) {
            $tx   = (float)$tva->tva_tx;
            $bht  = round((float)$tva->base_ht, 2);
            $mtva = round((float)$tva->montant_tva, 2);

            if (abs($bht) > 0.001) {
                $lignes_tmp[] = fec_build_ligne($jcode, $jlib, $num, $dfec,
                    $pcg['ventes'], 'Ventes', '', '',
                    $pref, $dfec, 'Ventes '.$tx.'% - '.$f->client_nom,
                    0, abs($bht));
                $somme_ht_detail += $bht;
            }
            if (abs($mtva) > 0.001) {
                $cle = accountingexport_get_tva_key($tx);
                $lignes_tmp[] = fec_build_ligne($jcode, $jlib, $num, $dfec,
                    $pcg[$cle], 'TVA collectée '.$tx.'%', '', '',
                    $pref, $dfec, 'TVA '.$tx.'% - '.$f->client_nom,
                    0, abs($mtva));
                $somme_tva_detail += $mtva;
            }
        }

        // Le detail par taux doit reconstituer exactement le HT et la TVA de
        // l'en-tete de facture (deja calcules et fiables cote Dolibarr). S'il
        // est absent ou incoherent (ecart > 2 centimes), on se replie sur une
        // ligne globale a partir des totaux d'en-tete : l'ecriture reste
        // toujours equilibree, quoi qu'il arrive avec la ventilation par taux.
        if (empty($tva_lines) || abs($ht - $somme_ht_detail) > 0.02 || abs($tva_total - $somme_tva_detail) > 0.02) {
            $lignes_tmp = array();
            if (abs($ht) > 0.001) {
                $lignes_tmp[] = fec_build_ligne($jcode, $jlib, $num, $dfec,
                    $pcg['ventes'], 'Ventes', '', '',
                    $pref, $dfec, 'Ventes - '.$f->client_nom,
                    0, abs($ht));
            }
            if (abs($tva_total) > 0.001) {
                $lignes_tmp[] = fec_build_ligne($jcode, $jlib, $num, $dfec,
                    $pcg['tva20'], 'TVA collectée', '', '',
                    $pref, $dfec, 'TVA - '.$f->client_nom,
                    0, abs($tva_total));
            }
        }

        $lignes = array_merge($lignes, $lignes_tmp);
    }
    return $lignes;
}

/**
 * Génère les lignes FEC du journal des achats.
 *
 * @param  DoliDB  $db
 * @param  string  $date_debut
 * @param  string  $date_fin
 * @param  array   $pcg
 * @param  Conf    $conf
 * @param  int     &$seq
 * @return array
 * @throws Exception
 */
function fec_get_lignes_achats($db, $date_debut, $date_fin, $pcg, $conf, &$seq)
{
    $jcode = !empty($conf->global->ACCOUNTINGEXPORT_JOURNAL_ACHATS) ? $conf->global->ACCOUNTINGEXPORT_JOURNAL_ACHATS : 'ACH';
    $jlib  = 'Journal des achats';
    $annee = substr($date_debut, 0, 4);

    // Idem que pour les ventes : on exclut les factures fournisseur brouillon.
    $factures = accountingexport_get_factures_fournisseurs($db, $date_debut, $date_fin, -1, 0);
    $factures = array_values(array_filter($factures, function($f) { return (int)$f->statut !== 0; }));
    $lignes   = array();

    foreach ($factures as $f) {
        $num   = fec_gen_num_ecriture($jcode, $annee, $seq++);
        $dfec  = fec_format_date($f->date_facture);
        $pref  = !empty($f->ref_fournisseur) ? $f->ref_fournisseur : $f->num_facture;
        $ttc   = round((float)$f->total_ttc, 2);
        $ht    = round((float)$f->total_ht, 2);
        $cpte  = !empty($f->compte_tiers_compta) ? $f->compte_tiers_compta : $pcg['fournisseur'];

        // Charge HT (Débit)
        $lignes[] = fec_build_ligne($jcode, $jlib, $num, $dfec,
            $pcg['achats'], 'Achats', '', '',
            $pref, $dfec, 'Achat '.$pref.' - '.$f->fournisseur_nom,
            abs($ht), 0);

        // TVA déductible, ventilee par taux si possible
        $tva_total = round((float)$f->total_tva, 2);
        try { $tva_lines = accountingexport_get_tva_facture_fourn($db, $f->rowid); }
        catch (Exception $e) { $tva_lines = array(); }

        $lignes_tva_tmp = array();
        $somme_tva_detail = 0.0;
        foreach ($tva_lines as $tva) {
            $tx   = (float)$tva->tva_tx;
            $mtva = round((float)$tva->montant_tva, 2);
            if (abs($mtva) > 0.001) {
                $cle = accountingexport_get_tva_ded_key($tx);
                $lignes_tva_tmp[] = fec_build_ligne($jcode, $jlib, $num, $dfec,
                    $pcg[$cle], 'TVA déductible '.$tx.'%', '', '',
                    $pref, $dfec, 'TVA '.$tx.'% - '.$f->fournisseur_nom,
                    abs($mtva), 0);
                $somme_tva_detail += $mtva;
            }
        }

        // Meme garde-fou que pour les ventes : si le detail par taux ne
        // reconstitue pas le total_tva de l'en-tete de facture, on se replie
        // sur une seule ligne globale a partir du total fiable de l'en-tete.
        if (empty($tva_lines) || abs($tva_total - $somme_tva_detail) > 0.02) {
            $lignes_tva_tmp = array();
            if (abs($tva_total) > 0.001) {
                $lignes_tva_tmp[] = fec_build_ligne($jcode, $jlib, $num, $dfec,
                    $pcg['tva_ded20'], 'TVA déductible', '', '',
                    $pref, $dfec, 'TVA - '.$f->fournisseur_nom,
                    abs($tva_total), 0);
            }
        }
        $lignes = array_merge($lignes, $lignes_tva_tmp);

        // Fournisseur (Crédit TTC)
        $lignes[] = fec_build_ligne($jcode, $jlib, $num, $dfec,
            $cpte, 'Fournisseurs', $f->code_fournisseur, $f->fournisseur_nom,
            $pref, $dfec, 'Facture '.$pref.' - '.$f->fournisseur_nom,
            0, abs($ttc));
    }
    return $lignes;
}

/**
 * Génère les lignes FEC du journal banque (règlements).
 *
 * @param  DoliDB  $db
 * @param  string  $date_debut
 * @param  string  $date_fin
 * @param  array   $pcg
 * @param  Conf    $conf
 * @param  int     &$seq
 * @return array
 * @throws Exception
 */
function fec_get_lignes_reglements($db, $date_debut, $date_fin, $pcg, $conf, &$seq)
{
    $jcode = !empty($conf->global->ACCOUNTINGEXPORT_JOURNAL_BANQUE) ? $conf->global->ACCOUNTINGEXPORT_JOURNAL_BANQUE : 'BAN';
    $jlib  = 'Journal banque';
    $annee = substr($date_debut, 0, 4);

    $reglements = accountingexport_get_reglements($db, $date_debut, $date_fin);
    $lignes     = array();

    foreach ($reglements as $r) {
        $num    = fec_gen_num_ecriture($jcode, $annee, $seq++);
        $dfec   = fec_format_date($r->date_reglement);
        $pref   = !empty($r->reference) ? $r->reference : $r->num_facture;
        $mnt    = round((float)$r->amount, 2);

        if ($r->type_tiers === 'client') {
            $lignes[] = fec_build_ligne($jcode, $jlib, $num, $dfec,
                $pcg['banque'], 'Banque', '', '',
                $pref, $dfec, 'Règlement '.$r->tiers,
                $mnt, 0);
            $lignes[] = fec_build_ligne($jcode, $jlib, $num, $dfec,
                $pcg['client'], 'Clients', '', $r->tiers,
                $pref, $dfec, 'Règlement '.$r->tiers,
                0, $mnt);
        } else {
            $lignes[] = fec_build_ligne($jcode, $jlib, $num, $dfec,
                $pcg['fournisseur'], 'Fournisseurs', '', $r->tiers,
                $pref, $dfec, 'Paiement '.$r->tiers,
                $mnt, 0);
            $lignes[] = fec_build_ligne($jcode, $jlib, $num, $dfec,
                $pcg['banque'], 'Banque', '', '',
                $pref, $dfec, 'Paiement '.$r->tiers,
                0, $mnt);
        }
    }
    return $lignes;
}

/**
 * Sérialise les lignes FEC en texte UTF-8 BOM.
 *
 * @param  array   $lignes
 * @param  string  $separateur  'tab' ou 'pipe'
 * @return string
 */
function fec_serialize($lignes, $separateur = 'tab')
{
    $sep = ($separateur === 'pipe') ? '|' : "\t";
    $cols = array('JournalCode','JournalLib','EcritureNum','EcritureDate',
                  'CompteNum','CompteLib','CompAuxNum','CompAuxLib',
                  'PieceRef','PieceDate','EcritureLib',
                  'Debit','Credit','EcritureLet','DateLet','ValidDate',
                  'Montantdevise','Idevise');

    $out = "\xEF\xBB\xBF";
    $out .= implode($sep, $cols)."\r\n";
    foreach ($lignes as $l) {
        $vals = array();
        foreach ($cols as $c) {
            $v = isset($l[$c]) ? $l[$c] : '';
            $vals[] = str_replace($sep, ' ', $v);
        }
        $out .= implode($sep, $vals)."\r\n";
    }
    return $out;
}

/**
 * Valide l'équilibre Débit = Crédit par EcritureNum.
 *
 * @param  array  $lignes
 * @return array  Numéros d'écritures déséquilibrées
 */
function fec_validate_equilibre($lignes)
{
    $t = array();
    foreach ($lignes as $l) {
        $n = $l['EcritureNum'];
        if (!isset($t[$n])) $t[$n] = array('d' => 0, 'c' => 0);
        $t[$n]['d'] += (float)str_replace(',', '.', $l['Debit']);
        $t[$n]['c'] += (float)str_replace(',', '.', $l['Credit']);
    }
    $err = array();
    foreach ($t as $n => $v) {
        if (abs($v['d'] - $v['c']) > 0.01) $err[] = $n;
    }
    return $err;
}

/**
 * Génère le nom de fichier FEC normé DGFiP.
 *
 * @param  string  $siret
 * @param  string  $date_fin  YYYY-MM-DD
 * @return string
 */
function fec_get_filename($siret, $date_fin)
{
    $s = preg_replace('/[^0-9]/', '', $siret);
    $d = str_replace('-', '', substr($date_fin, 0, 10));
    return ($s ?: 'SIRET').'FEC'.$d.'.txt';
}

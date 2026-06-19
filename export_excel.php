<?php
/* custom/accountingexport/export_excel.php */

// ── Augmenter les limites PHP avant tout ─────────────────────────────────────
@ini_set('memory_limit',       '256M');
@ini_set('max_execution_time', '120');

// ── Chargement Dolibarr ───────────────────────────────────────────────────────
$res = 0;
if (!$res && file_exists("../main.inc.php"))          { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php"))       { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php"))    { $res = @include "../../../main.inc.php"; }
if (!$res && file_exists("../../../../main.inc.php")) { $res = @include "../../../../main.inc.php"; }
if (!$res) { die('Impossible de charger Dolibarr'); }

define('INC_FROM_DOLIBARR', 1);
require_once DOL_DOCUMENT_ROOT.'/custom/accountingexport/lib/accountingexport.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/accountingexport/lib/accountingexport_tva.lib.php';

function ae_has_right_x($u,$m,$p){if(!empty($u->admin))return true;if(method_exists($u,'hasRight')&&$u->hasRight($m,$p))return true;if(!empty($u->rights->$m->$p))return true;return false;}
if(!ae_has_right_x($user,'accountingexport','export')){accessforbidden();}
// Verification CSRF compatible Dolibarr 14 a 22
// checkToken() supprime en v22 - on verifie manuellement via $_SESSION
$_ae_tok = GETPOST('token', 'alpha');
if (empty($user->admin) && !empty($_SESSION['newtoken']) && $_ae_tok !== $_SESSION['newtoken']) {
    accessforbidden('Security token mismatch');
}
$langs->loadLangs(array('accountingexport@accountingexport'));

// ── Paramètres ────────────────────────────────────────────────────────────────
$type_export    = GETPOST('type_export',    'alpha') ?: 'all';
$date_debut     = GETPOST('date_debut',     'alpha');
$date_fin       = GETPOST('date_fin',       'alpha');
$statut         = (int) GETPOST('statut',   'int');
$inclure_avoirs = (int) GETPOST('inclure_avoirs', 'int');
$entity         = (int) GETPOST('entity',   'int');

if (empty($date_debut) || empty($date_fin)) {
    setEventMessages($langs->trans('PeriodeRequise'), null, 'errors');
    header('Location: accountingexport_page.php');
    exit;
}

$pcg = accountingexport_get_pcg_mapping($conf);

// ── Tenter de charger PhpSpreadsheet ─────────────────────────────────────────
$has_spreadsheet = accountingexport_load_spreadsheet();

// ── Choix du moteur de rendu ──────────────────────────────────────────────────
if ($has_spreadsheet) {
    ae_export_xlsx($db, $conf, $user, $langs, $pcg, $type_export, $date_debut, $date_fin, $statut, $entity);
} else {
    // Fallback : export CSV multi-sections (compatible tous hébergements)
    ae_export_csv($db, $conf, $user, $langs, $pcg, $type_export, $date_debut, $date_fin, $statut, $entity);
}
exit;

/* ═══════════════════════════════════════════════════════════════════════════════
   EXPORT XLSX (PhpSpreadsheet disponible)
   ═══════════════════════════════════════════════════════════════════════════════ */

function ae_export_xlsx($db, $conf, $user, $langs, $pcg, $type_export, $date_debut, $date_fin, $statut, $entity)
{
    // Utilisation des classes PhpSpreadsheet avec namespaces complets (compatibilité PHP 7.4+)
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('Dolibarr AccountingExport')
        ->setTitle('Export comptable '.$date_debut.' / '.$date_fin);

    $style_hdr = array(
        'font'      => array('bold' => true, 'color' => array('argb' => 'FFFFFFFF'), 'size' => 10),
        'fill'      => array('fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                             'startColor' => array('argb' => 'FF1F4E79')),
        'alignment' => array('horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                             'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                             'wrapText'   => true),
    );
    $style_even = array(
        'fill' => array('fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => array('argb' => 'FFF2F2F2')),
    );
    $style_total = array(
        'font' => array('bold' => true),
        'fill' => array('fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => array('argb' => 'FFD6E4F0')),
    );

    $sheets_done = 0;

    /* ── Onglet Ventes ─────────────────────────────────────────────────── */
    if (in_array($type_export, array('ventes', 'all'))) {
        $sheet = $sheets_done === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
        $sheet->setTitle('Journal ventes');
        $sheets_done++;

        try { $taux = accountingexport_tva_get_taux_utilises_clients($db, $date_debut, $date_fin); }
        catch (Exception $e) { $taux = array(20.0); }

        $hdrs = array_merge(
            array('Date','N° Écriture','N° Facture','Client','Code client','Compte client','Compte produit','Montant HT'),
            array_map(function($t){ return accountingexport_tva_col_label($t,'collectee'); }, $taux),
            array('Montant TTC','Mode règlement','Date règlement','Statut','Transféré','Devise')
        );
        ae_write_headers($sheet, $hdrs, $style_hdr);
        $sheet->freezePane('A2');

        try { $rows = accountingexport_get_factures_clients($db, $date_debut, $date_fin, $statut>0?$statut-1:-1, $entity); }
        catch (Exception $e) { $rows = array(); }

        $row = 2; $tot_ht = 0; $tot_ttc = 0;
        $tot_tva = array_fill_keys($taux, 0);

        foreach ($rows as $f) {
            if ($row%2===1) $sheet->getStyle('A'.$row.':'.ae_col(count($hdrs)).$row)->applyFromArray($style_even);

            try { $tva_l = accountingexport_get_tva_facture($db, $f->rowid); }
            catch (Exception $e) { $tva_l = array(); }
            $tva_m = accountingexport_tva_map_par_taux($tva_l, $taux);

            ae_date($sheet,   'A'.$row, $f->date_facture);
            $sheet->setCellValue('B'.$row, $f->ref_ecriture ?: '');
            $sheet->setCellValue('C'.$row, $f->num_facture);
            $sheet->setCellValue('D'.$row, $f->client_nom);
            $sheet->setCellValue('E'.$row, $f->client_code);
            $sheet->setCellValue('F'.$row, !empty($f->compte_tiers_compta) ? $f->compte_tiers_compta : $pcg['client']);
            $sheet->setCellValue('G'.$row, $pcg['ventes']);
            ae_num($sheet,    'H'.$row, $f->total_ht);

            $ci = 9;
            foreach ($taux as $t) {
                ae_num($sheet, ae_col($ci).$row, $tva_m[$t]);
                $tot_tva[$t] += (float)$tva_m[$t];
                $ci++;
            }
            ae_num($sheet,  ae_col($ci++).$row, $f->total_ttc);
            $sheet->setCellValue(ae_col($ci++).$row, $f->mode_reglement ?: '');
            ae_date($sheet, ae_col($ci++).$row, $f->date_paiement);
            $sheet->setCellValue(ae_col($ci++).$row, accountingexport_libelle_statut_client($f->statut, isset($f->type)?$f->type:0));
            $sheet->setCellValue(ae_col($ci++).$row, !empty($f->transfert_compta)?'Oui':'Non');
            $sheet->setCellValue(ae_col($ci++).$row, $f->devise ?: 'EUR');

            $tot_ht  += (float)$f->total_ht;
            $tot_ttc += (float)$f->total_ttc;
            $row++;
        }

        // Totaux
        $sheet->getStyle('A'.$row.':'.ae_col(count($hdrs)).$row)->applyFromArray($style_total);
        $sheet->setCellValue('A'.$row, 'TOTAL');
        ae_num($sheet, 'H'.$row, $tot_ht);
        $ci = 9;
        foreach ($taux as $t) { ae_num($sheet, ae_col($ci++).$row, $tot_tva[$t]); }
        ae_num($sheet, ae_col($ci).$row, $tot_ttc);

        ae_autosize($sheet, count($hdrs));
    }

    /* ── Onglet Achats ─────────────────────────────────────────────────── */
    if (in_array($type_export, array('achats', 'all'))) {
        $sheet = $spreadsheet->createSheet(); $sheet->setTitle('Journal achats'); $sheets_done++;

        try { $taux = accountingexport_tva_get_taux_utilises_fournisseurs($db, $date_debut, $date_fin); }
        catch (Exception $e) { $taux = array(20.0); }

        $hdrs = array_merge(
            array('Date','N° Écriture','N° Facture','Réf. fournisseur','Fournisseur','Compte fourn.','Compte charge','Montant HT'),
            array_map(function($t){ return accountingexport_tva_col_label($t,'deductible'); }, $taux),
            array('Montant TTC','Mode règlement','Date règlement','Statut')
        );
        ae_write_headers($sheet, $hdrs, $style_hdr);
        $sheet->freezePane('A2');

        try { $rows = accountingexport_get_factures_fournisseurs($db, $date_debut, $date_fin, $statut>0?$statut-1:-1, $entity); }
        catch (Exception $e) { $rows = array(); }

        $row = 2; $tot_ht = 0; $tot_ttc = 0;
        $tot_tva = array_fill_keys($taux, 0);

        foreach ($rows as $f) {
            if ($row%2===1) $sheet->getStyle('A'.$row.':'.ae_col(count($hdrs)).$row)->applyFromArray($style_even);

            try { $tva_l = accountingexport_get_tva_facture_fourn($db, $f->rowid); }
            catch (Exception $e) { $tva_l = array(); }
            $tva_m = accountingexport_tva_map_par_taux($tva_l, $taux);

            ae_date($sheet,   'A'.$row, $f->date_facture);
            $sheet->setCellValue('B'.$row, '');
            $sheet->setCellValue('C'.$row, $f->num_facture);
            $sheet->setCellValue('D'.$row, $f->ref_fournisseur);
            $sheet->setCellValue('E'.$row, $f->fournisseur_nom);
            $sheet->setCellValue('F'.$row, !empty($f->compte_tiers_compta) ? $f->compte_tiers_compta : $pcg['fournisseur']);
            $sheet->setCellValue('G'.$row, $pcg['achats']);
            ae_num($sheet,    'H'.$row, $f->total_ht);

            $ci = 9;
            foreach ($taux as $t) {
                ae_num($sheet, ae_col($ci).$row, $tva_m[$t]);
                $tot_tva[$t] += (float)$tva_m[$t];
                $ci++;
            }
            ae_num($sheet,  ae_col($ci++).$row, $f->total_ttc);
            $sheet->setCellValue(ae_col($ci++).$row, $f->mode_reglement ?: '');
            ae_date($sheet, ae_col($ci++).$row, $f->date_paiement);
            $sheet->setCellValue(ae_col($ci++).$row, accountingexport_libelle_statut_fourn($f->statut));

            $tot_ht  += (float)$f->total_ht;
            $tot_ttc += (float)$f->total_ttc;
            $row++;
        }

        $sheet->getStyle('A'.$row.':'.ae_col(count($hdrs)).$row)->applyFromArray($style_total);
        $sheet->setCellValue('A'.$row, 'TOTAL');
        ae_num($sheet, 'H'.$row, $tot_ht);
        ae_autosize($sheet, count($hdrs));
    }

    /* ── Onglet Grand Livre ────────────────────────────────────────────── */
    if (in_array($type_export, array('grandlivre','all')) && !isModEnabled('accounting')) {
        $sheet = $spreadsheet->createSheet(); $sheet->setTitle('Grand livre'); $sheets_done++;
        $sheet->setCellValue('A1', 'Module Comptabilite non active dans Dolibarr - aucune ecriture disponible.');
    }
    if (in_array($type_export, array('grandlivre','all')) && isModEnabled('accounting')) {
        $sheet = $spreadsheet->createSheet(); $sheet->setTitle('Grand livre'); $sheets_done++;
        $hdrs = array('Date','N° Écriture','Journal','N° Compte','Intitulé','Compte aux.','Intitulé aux.','Libellé','Débit','Crédit','Solde cumulé');
        ae_write_headers($sheet, $hdrs, $style_hdr);
        $sheet->freezePane('A2');

        try { $rows = accountingexport_get_grand_livre($db, $date_debut, $date_fin, $entity); }
        catch (Exception $e) { $rows = array(); }

        if (empty($rows)) {
            $sheet->setCellValue('A2', 'Aucune ecriture - verifiez que les factures de la periode ont ete transferees en comptabilite (menu Comptabilite > Transfert en comptabilite).');
        }

        $row = 2;
        foreach ($rows as $l) {
            if ($row%2===1) $sheet->getStyle('A'.$row.':K'.$row)->applyFromArray($style_even);
            ae_date($sheet, 'A'.$row, $l->date_ecriture);
            $sheet->setCellValue('B'.$row, $l->num_ecriture);
            $sheet->setCellValue('C'.$row, $l->journal_code);
            $sheet->setCellValue('D'.$row, $l->compte);
            $sheet->setCellValue('E'.$row, $l->intitule_compte);
            $sheet->setCellValue('F'.$row, $l->compte_auxiliaire);
            $sheet->setCellValue('G'.$row, $l->intitule_auxiliaire);
            $sheet->setCellValue('H'.$row, $l->libelle);
            ae_num($sheet, 'I'.$row, $l->debit);
            ae_num($sheet, 'J'.$row, $l->credit);
            ae_num($sheet, 'K'.$row, $l->solde_cumule);
            $row++;
        }
        ae_autosize($sheet, 11);
    }

    /* ── Onglet Balance ────────────────────────────────────────────────── */
    if (in_array($type_export, array('balance','all')) && isModEnabled('accounting')) {
        $sheet = $spreadsheet->createSheet(); $sheet->setTitle('Balance'); $sheets_done++;
        $hdrs = array('N° Compte','Intitulé','Débit N','Crédit N','Solde N','Débit N-1','Crédit N-1','Solde N-1');
        ae_write_headers($sheet, $hdrs, $style_hdr);
        $sheet->freezePane('A2');

        try { $rows = accountingexport_get_balance($db, $date_debut, $date_fin, $entity); }
        catch (Exception $e) { $rows = array(); }

        $row = 2;
        foreach ($rows as $b) {
            if ($row%2===1) $sheet->getStyle('A'.$row.':H'.$row)->applyFromArray($style_even);
            $sheet->setCellValue('A'.$row, $b['compte']);
            $sheet->setCellValue('B'.$row, $b['intitule']);
            ae_num($sheet, 'C'.$row, $b['debit_n']);
            ae_num($sheet, 'D'.$row, $b['credit_n']);
            ae_num($sheet, 'E'.$row, $b['solde_n']);
            ae_num($sheet, 'F'.$row, $b['debit_n1']);
            ae_num($sheet, 'G'.$row, $b['credit_n1']);
            ae_num($sheet, 'H'.$row, $b['solde_n1']);
            $row++;
        }
        ae_autosize($sheet, 8);
    }

    /* ── Onglet Récap TVA ──────────────────────────────────────────────── */
    if (in_array($type_export, array('tva','all'))) {
        $sheet = $spreadsheet->createSheet(); $sheet->setTitle('Recap TVA'); $sheets_done++;
        ae_write_headers($sheet, array('Type','Taux TVA','Régime','Base HT','Montant TVA','Nb factures'), $style_hdr);
        $sheet->freezePane('A2');

        $row = 2; $tot_bc = 0; $tot_tc = 0;
        try { $rc = accountingexport_tva_recap_collectee($db, $date_debut, $date_fin); } catch (Exception $e) { $rc = array(); }
        foreach ($rc as $r) {
            if ($row%2===1) $sheet->getStyle('A'.$row.':F'.$row)->applyFromArray($style_even);
            $sheet->setCellValue('A'.$row, 'Collectée');
            $sheet->setCellValue('B'.$row, $r['taux'].'%');
            $sheet->setCellValue('C'.$row, $r['regime']);
            ae_num($sheet, 'D'.$row, $r['base_ht']);
            ae_num($sheet, 'E'.$row, $r['montant_tva']);
            $sheet->setCellValue('F'.$row, $r['nb_factures']);
            $tot_bc += $r['base_ht']; $tot_tc += $r['montant_tva'];
            $row++;
        }

        $row++; $tot_bd = 0; $tot_td = 0;
        try { $rd = accountingexport_tva_recap_deductible($db, $date_debut, $date_fin); } catch (Exception $e) { $rd = array(); }
        foreach ($rd as $r) {
            if ($row%2===1) $sheet->getStyle('A'.$row.':F'.$row)->applyFromArray($style_even);
            $sheet->setCellValue('A'.$row, 'Déductible');
            $sheet->setCellValue('B'.$row, $r['taux'].'%');
            $sheet->setCellValue('C'.$row, $r['regime']);
            ae_num($sheet, 'D'.$row, $r['base_ht']);
            ae_num($sheet, 'E'.$row, $r['montant_tva']);
            $sheet->setCellValue('F'.$row, $r['nb_factures']);
            $tot_bd += $r['base_ht']; $tot_td += $r['montant_tva'];
            $row++;
        }

        $row++;
        $sheet->getStyle('A'.$row.':F'.$row)->applyFromArray(array(
            'font'=>array('bold'=>true),
            'fill'=>array('fillType'=>\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,'startColor'=>array('argb'=>'FFFFD700'))
        ));
        $sheet->setCellValue('A'.$row, 'TVA DUE (collectée - déductible)');
        ae_num($sheet, 'E'.$row, $tot_tc - $tot_td);
        ae_autosize($sheet, 6);
    }

    /* ── Onglet Règlements ─────────────────────────────────────────────── */
    if (in_array($type_export, array('reglements','all'))) {
        $sheet = $spreadsheet->createSheet(); $sheet->setTitle('Reglements'); $sheets_done++;
        ae_write_headers($sheet, array('Date règlement','Type','Mode','Référence','Tiers','N° Facture','Montant','Banque/Caisse'), $style_hdr);
        $sheet->freezePane('A2');

        try { $rows = accountingexport_get_reglements($db, $date_debut, $date_fin, $entity); }
        catch (Exception $e) { $rows = array(); }

        $row = 2;
        foreach ($rows as $r) {
            if ($row%2===1) $sheet->getStyle('A'.$row.':H'.$row)->applyFromArray($style_even);
            ae_date($sheet, 'A'.$row, $r->date_reglement);
            $sheet->setCellValue('B'.$row, $r->type_tiers==='client'?'Client':'Fournisseur');
            $sheet->setCellValue('C'.$row, $r->mode_reglement);
            $sheet->setCellValue('D'.$row, $r->reference);
            $sheet->setCellValue('E'.$row, $r->tiers);
            $sheet->setCellValue('F'.$row, $r->num_facture);
            ae_num($sheet, 'G'.$row, $r->amount);
            $sheet->setCellValue('H'.$row, $r->compte_banque);
            $row++;
        }
        ae_autosize($sheet, 8);
    }

    // Supprimer la feuille vide initiale si on a créé des feuilles
    if ($sheets_done > 0 && $spreadsheet->getSheetCount() > $sheets_done) {
        try { $spreadsheet->removeSheetByIndex($spreadsheet->getSheetCount()-1); } catch (Exception $e) {}
    }

    $nb = 0;
    foreach ($spreadsheet->getAllSheets() as $s) { $nb += max(0, $s->getHighestRow()-1); }
    accountingexport_log_export($db, $user, 'excel', $date_debut, $date_fin, $nb);

    $dd = str_replace('-','', $date_debut);
    $df = str_replace('-','', $date_fin);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="export_comptable_'.$dd.'-'.$df.'.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
}

/* ═══════════════════════════════════════════════════════════════════════════════
   EXPORT CSV (fallback si PhpSpreadsheet absent)
   Format UTF-8 BOM, séparateur ; — importable dans Excel/LibreOffice
   ═══════════════════════════════════════════════════════════════════════════════ */

function ae_export_csv($db, $conf, $user, $langs, $pcg, $type_export, $date_debut, $date_fin, $statut, $entity)
{
    $dd = str_replace('-','', $date_debut);
    $df = str_replace('-','', $date_fin);
    $filename = 'export_comptable_'.$dd.'-'.$df.'.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');

    $out = fopen('php://output', 'w');
    // BOM UTF-8
    fputs($out, "\xEF\xBB\xBF");

    $st = $statut > 0 ? $statut-1 : -1;

    /* ── Ventes ── */
    if (in_array($type_export, array('ventes','all'))) {
        fputcsv($out, array('=== JOURNAL DES VENTES ==='), ';');
        try { $taux = accountingexport_tva_get_taux_utilises_clients($db, $date_debut, $date_fin); }
        catch (Exception $e) { $taux = array(20.0); }

        $hdrs = array_merge(
            array('Date','N° Facture','Client','Code client','Compte client','Compte produit','Montant HT'),
            array_map(function($t){ return accountingexport_tva_col_label($t,'collectee'); }, $taux),
            array('Montant TTC','Mode règlement','Date règlement','Statut','Transféré')
        );
        fputcsv($out, $hdrs, ';');

        try { $rows = accountingexport_get_factures_clients($db, $date_debut, $date_fin, $st, $entity); }
        catch (Exception $e) { $rows = array(); }

        foreach ($rows as $f) {
            try { $tva_l = accountingexport_get_tva_facture($db, $f->rowid); }
            catch (Exception $e) { $tva_l = array(); }
            $tva_m = accountingexport_tva_map_par_taux($tva_l, $taux);

            $line = array(
                accountingexport_format_date($f->date_facture),
                $f->num_facture, $f->client_nom, $f->client_code,
                !empty($f->compte_tiers_compta) ? $f->compte_tiers_compta : $pcg['client'],
                $pcg['ventes'],
                number_format((float)$f->total_ht, 2, ',', ''),
            );
            foreach ($taux as $t) {
                $line[] = number_format((float)$tva_m[$t], 2, ',', '');
            }
            $line[] = number_format((float)$f->total_ttc, 2, ',', '');
            $line[] = $f->mode_reglement ?: '';
            $line[] = accountingexport_format_date($f->date_paiement);
            $line[] = accountingexport_libelle_statut_client($f->statut, isset($f->type)?$f->type:0);
            $line[] = !empty($f->transfert_compta) ? 'Oui' : 'Non';
            fputcsv($out, $line, ';');
        }
        fputcsv($out, array(), ';');
    }

    /* ── Achats ── */
    if (in_array($type_export, array('achats','all'))) {
        fputcsv($out, array('=== JOURNAL DES ACHATS ==='), ';');
        try { $taux = accountingexport_tva_get_taux_utilises_fournisseurs($db, $date_debut, $date_fin); }
        catch (Exception $e) { $taux = array(20.0); }

        $hdrs = array_merge(
            array('Date','N° Facture','Réf. fournisseur','Fournisseur','Compte fourn.','Compte charge','Montant HT'),
            array_map(function($t){ return accountingexport_tva_col_label($t,'deductible'); }, $taux),
            array('Montant TTC','Statut')
        );
        fputcsv($out, $hdrs, ';');

        try { $rows = accountingexport_get_factures_fournisseurs($db, $date_debut, $date_fin, $st, $entity); }
        catch (Exception $e) { $rows = array(); }

        foreach ($rows as $f) {
            try { $tva_l = accountingexport_get_tva_facture_fourn($db, $f->rowid); }
            catch (Exception $e) { $tva_l = array(); }
            $tva_m = accountingexport_tva_map_par_taux($tva_l, $taux);

            $line = array(
                accountingexport_format_date($f->date_facture),
                $f->num_facture, $f->ref_fournisseur, $f->fournisseur_nom,
                !empty($f->compte_tiers_compta) ? $f->compte_tiers_compta : $pcg['fournisseur'],
                $pcg['achats'],
                number_format((float)$f->total_ht, 2, ',', ''),
            );
            foreach ($taux as $t) {
                $line[] = number_format((float)$tva_m[$t], 2, ',', '');
            }
            $line[] = number_format((float)$f->total_ttc, 2, ',', '');
            $line[] = accountingexport_libelle_statut_fourn($f->statut);
            fputcsv($out, $line, ';');
        }
        fputcsv($out, array(), ';');
    }

    /* ── Règlements ── */
    if (in_array($type_export, array('reglements','all'))) {
        fputcsv($out, array('=== RÈGLEMENTS ==='), ';');
        fputcsv($out, array('Date règlement','Type','Mode','Référence','Tiers','N° Facture','Montant','Banque'), ';');

        try { $rows = accountingexport_get_reglements($db, $date_debut, $date_fin, $entity); }
        catch (Exception $e) { $rows = array(); }

        foreach ($rows as $r) {
            fputcsv($out, array(
                accountingexport_format_date($r->date_reglement),
                $r->type_tiers==='client'?'Client':'Fournisseur',
                $r->mode_reglement, $r->reference, $r->tiers,
                $r->num_facture,
                number_format((float)$r->amount, 2, ',', ''),
                $r->compte_banque,
            ), ';');
        }
    }

    /* ── Grand livre ── */
    if (in_array($type_export, array('grandlivre','all'))) {
        if (!isModEnabled('accounting')) {
            fputcsv($out, array('=== GRAND LIVRE — module Comptabilite non active ==='), ';');
        } else {
            fputcsv($out, array('=== GRAND LIVRE ==='), ';');
            try { $rows = accountingexport_get_grand_livre($db, $date_debut, $date_fin, $entity); }
            catch (Exception $e) { $rows = array(); }

            if (empty($rows)) {
                fputcsv($out, array('Aucune ecriture - verifiez que les factures de la periode ont ete transferees en comptabilite (menu Comptabilite > Transfert en comptabilite)'), ';');
            } else {
                fputcsv($out, array('Date','N Ecriture','Journal','N Compte','Intitule','Compte aux.','Intitule aux.','Libelle','Debit','Credit','Solde cumule'), ';');
                foreach ($rows as $l) {
                    fputcsv($out, array(
                        accountingexport_format_date($l->date_ecriture),
                        $l->num_ecriture, $l->journal_code, $l->compte, $l->intitule_compte,
                        $l->compte_auxiliaire, $l->intitule_auxiliaire, $l->libelle,
                        number_format((float)$l->debit, 2, ',', ''),
                        number_format((float)$l->credit, 2, ',', ''),
                        number_format((float)$l->solde_cumule, 2, ',', ''),
                    ), ';');
                }
            }
            fputcsv($out, array(), ';');
        }
    }

    /* ── Balance ── */
    if (in_array($type_export, array('balance','all'))) {
        if (!isModEnabled('accounting')) {
            fputcsv($out, array('=== BALANCE — module Comptabilite non active ==='), ';');
        } else {
            fputcsv($out, array('=== BALANCE ==='), ';');
            try { $rows = accountingexport_get_balance($db, $date_debut, $date_fin, $entity); }
            catch (Exception $e) { $rows = array(); }

            if (empty($rows)) {
                fputcsv($out, array('Aucune ecriture sur la periode'), ';');
            } else {
                fputcsv($out, array('N Compte','Intitule','Debit N','Credit N','Solde N','Debit N-1','Credit N-1','Solde N-1'), ';');
                foreach ($rows as $b) {
                    fputcsv($out, array(
                        $b['compte'], $b['intitule'],
                        number_format($b['debit_n'], 2, ',', ''),
                        number_format($b['credit_n'], 2, ',', ''),
                        number_format($b['solde_n'], 2, ',', ''),
                        number_format($b['debit_n1'], 2, ',', ''),
                        number_format($b['credit_n1'], 2, ',', ''),
                        number_format($b['solde_n1'], 2, ',', ''),
                    ), ';');
                }
            }
            fputcsv($out, array(), ';');
        }
    }

    fclose($out);
    accountingexport_log_export($db, $user, 'csv', $date_debut, $date_fin, 0);
}

/* ═══════════════════════════════════════════════════════════════════════════════
   HELPERS XLSX
   ═══════════════════════════════════════════════════════════════════════════════ */

/**
 * Lettre de colonne depuis un index 1-based.
 *
 * @param  int  $idx  Index 1-based
 * @return string
 */
function ae_col($idx)
{
    return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx);
}

/**
 * Écrit les en-têtes d'une feuille et applique le style.
 *
 * @param  \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet  $sheet
 * @param  array                                          $hdrs
 * @param  array                                          $style
 * @return void
 */
function ae_write_headers($sheet, $hdrs, $style)
{
    foreach ($hdrs as $i => $h) {
        $sheet->setCellValue(ae_col($i+1).'1', $h);
    }
    $last = ae_col(count($hdrs));
    $sheet->getStyle('A1:'.$last.'1')->applyFromArray($style);
    $sheet->getRowDimension(1)->setRowHeight(22);
}

/**
 * Écrit une cellule date au format Excel.
 *
 * @param  \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet  $sheet
 * @param  string                                         $coord
 * @param  mixed                                          $date
 * @return void
 */
function ae_date($sheet, $coord, $date)
{
    if (empty($date) || $date === '0000-00-00') { $sheet->setCellValue($coord, ''); return; }
    $ts = is_numeric($date) ? (int)$date : strtotime($date);
    if ($ts) {
        $sheet->setCellValue($coord, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($ts));
        $sheet->getStyle($coord)->getNumberFormat()->setFormatCode('DD/MM/YYYY');
    }
}

/**
 * Écrit une cellule nombre à 2 décimales.
 *
 * @param  \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet  $sheet
 * @param  string                                         $coord
 * @param  mixed                                          $val
 * @return void
 */
function ae_num($sheet, $coord, $val)
{
    $sheet->setCellValue($coord, round((float)$val, 2));
    $sheet->getStyle($coord)->getNumberFormat()->setFormatCode('#,##0.00');
}

/**
 * Auto-dimensionne les colonnes d'une feuille.
 *
 * @param  \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet  $sheet
 * @param  int                                            $nb_cols
 * @return void
 */
function ae_autosize($sheet, $nb_cols)
{
    for ($i = 1; $i <= $nb_cols; $i++) {
        $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
    }
}

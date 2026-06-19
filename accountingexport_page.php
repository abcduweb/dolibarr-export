<?php
/* custom/accountingexport/accountingexport_page.php */

$res = 0;
if (!$res && file_exists("../main.inc.php"))          { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php"))       { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php"))    { $res = @include "../../../main.inc.php"; }
if (!$res && file_exists("../../../../main.inc.php")) { $res = @include "../../../../main.inc.php"; }
if (!$res) { die('Impossible de charger Dolibarr'); }

define('INC_FROM_DOLIBARR', 1);
require_once DOL_DOCUMENT_ROOT.'/custom/accountingexport/lib/accountingexport.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/accountingexport/lib/accountingexport_tva.lib.php';

// Compatibilite droits Dolibarr 17 a 22
// hasRight() peut retourner false pour un module custom en v22 - on accepte les admins
function ae_has_right($user, $module, $perm) {
    if (!empty($user->admin)) return true;
    if (method_exists($user, 'hasRight') && $user->hasRight($module, $perm)) return true;
    if (!empty($user->rights->$module->$perm)) return true;
    return false;
}

if (!ae_has_right($user, 'accountingexport', 'read')) { accessforbidden(); }

$langs->loadLangs(array('accountingexport@accountingexport', 'compta', 'bills'));

$action         = GETPOST('action', 'aZ09');
$type_export    = GETPOST('type_export', 'alpha') ?: 'ventes';
$date_debut_str = GETPOST('date_debut', 'alpha') ?: date('Y-01-01');
$date_fin_str   = GETPOST('date_fin',   'alpha') ?: date('Y-12-31');
$statut         = (int) GETPOST('statut', 'int');
$entity         = (int) GETPOST('entity', 'int');
$inclure_avoirs = GETPOST('inclure_avoirs', 'alpha') === '1';

$erreurs           = array();
$lignes_preview    = array();
$nb_total          = 0;
$warning_transfert = false;

if ($action === 'preview' && ae_has_right($user, 'accountingexport', 'export')) {
    // Verification CSRF compatible Dolibarr 14 a 22 (checkToken supprime en v22)
    $_ae_tok = GETPOST('token', 'alpha');
    if (empty($user->admin) && !empty($_SESSION['newtoken']) && $_ae_tok !== $_SESSION['newtoken']) {
        accessforbidden('Security token mismatch');
    }
    if (empty($date_debut_str) || empty($date_fin_str)) {
        $erreurs[] = $langs->trans('PeriodeRequise');
    } else {
        try {
            $st = $statut > 0 ? $statut - 1 : -1;
            switch ($type_export) {
                case 'ventes':
                    $lignes_preview = accountingexport_get_factures_clients($db, $date_debut_str, $date_fin_str, $st, $entity, 10, 0);
                    $nb_total = count(accountingexport_get_factures_clients($db, $date_debut_str, $date_fin_str, $st, $entity));
                    break;
                case 'achats':
                    $lignes_preview = accountingexport_get_factures_fournisseurs($db, $date_debut_str, $date_fin_str, $st, $entity, 10, 0);
                    $nb_total = count(accountingexport_get_factures_fournisseurs($db, $date_debut_str, $date_fin_str, $st, $entity));
                    break;
                case 'grandlivre':
                    if (isModEnabled('accounting')) {
                        $all = accountingexport_get_grand_livre($db, $date_debut_str, $date_fin_str, $entity);
                        $nb_total = count($all);
                        $lignes_preview = array_slice($all, 0, 10);
                    }
                    break;
                case 'reglements':
                    $all = accountingexport_get_reglements($db, $date_debut_str, $date_fin_str, $entity);
                    $nb_total = count($all);
                    $lignes_preview = array_slice($all, 0, 10);
                    break;
            }
            if (isModEnabled('accounting') && !accountingexport_has_bookkeeping($db, $date_debut_str, $date_fin_str)) {
                $warning_transfert = true;
            }
        } catch (Exception $e) {
            $erreurs[] = $e->getMessage();
        }
    }
}

llxHeader('', $langs->trans('ExportComptable'), '');
print load_fiche_titre($langs->trans('ExportComptable'), '', 'accountingexport@accountingexport');

if (!empty($erreurs)) {
    foreach ($erreurs as $e) { setEventMessages($e, null, 'errors'); }
}
if ($warning_transfert) {
    print '<div class="warning">'.$langs->trans('EcrituresNonTransferees').'</div>';
}

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="preview">';
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="4"><b>'.$langs->trans('FiltresAvances').'</b></td></tr>';

print '<tr class="oddeven">';
print '<td class="fieldrequired">'.$langs->trans('DateDebut').'</td>';
print '<td><input type="date" name="date_debut" class="flat" value="'.dol_htmlentities($date_debut_str).'"></td>';
print '<td class="fieldrequired">'.$langs->trans('DateFin').'</td>';
print '<td><input type="date" name="date_fin" class="flat" value="'.dol_htmlentities($date_fin_str).'"></td>';
print '</tr>';

$raccourcis = array(
    array('Mois en cours',  date('Y-m-01'),                          date('Y-m-t')),
    array('Trimestre',      date('Y-m-01', mktime(0,0,0,ceil(date('n')/3)*3-2,1,date('Y'))), date('Y-m-t', mktime(0,0,0,ceil(date('n')/3)*3,1,date('Y')))),
    array('Exercice 2026',  '2026-01-01',                            '2026-12-31'),
    array('Exercice 2025',  '2025-01-01',                            '2025-12-31'),
    array('Mois precedent', date('Y-m-01', strtotime('-1 month')),   date('Y-m-t', strtotime('-1 month'))),
);
print '<tr class="oddeven"><td></td><td colspan="3">';
foreach ($raccourcis as $r) {
    $url = '?date_debut='.$r[1].'&date_fin='.$r[2].'&type_export='.urlencode($type_export);
    print '<a href="'.dol_htmlentities($url).'" class="butActionSmall" style="margin-right:4px">'.dol_htmlentities($r[0]).'</a>';
}
print '</td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('TypeExport').'</td><td>';
$types = array(
    'ventes'     => 'Journal ventes',
    'achats'     => 'Journal achats',
    'grandlivre' => 'Grand livre',
    'balance'    => 'Balance',
    'tva'        => 'Recap TVA',
    'reglements' => 'Reglements',
    'all'        => 'Tous les journaux',
);
print '<select name="type_export" class="flat">';
foreach ($types as $v => $l) {
    print '<option value="'.dol_htmlentities($v).'"'.($type_export===$v?' selected':'').'>'.dol_htmlentities($l).'</option>';
}
print '</select></td>';
print '<td>'.$langs->trans('ColStatut').'</td><td>';
$statuts = array(0=>'Tous', 1=>'Brouillon', 2=>'Validee', 3=>'Payee');
print '<select name="statut" class="flat">';
foreach ($statuts as $v => $l) {
    print '<option value="'.$v.'"'.($statut===$v?' selected':'').'>'.dol_htmlentities($l).'</option>';
}
print '</select></td></tr>';

print '<tr class="oddeven"><td>Inclure les avoirs</td>';
print '<td><input type="checkbox" name="inclure_avoirs" value="1"'.($inclure_avoirs?' checked':'').'> Oui</td>';
print '<td></td><td></td></tr>';
print '</table></div>';

print '<div class="center" style="margin-top:14px">';
if (ae_has_right($user, 'accountingexport', 'export')) {
    $qs = '&type_export='.urlencode($type_export)
        .'&date_debut='.urlencode($date_debut_str)
        .'&date_fin='.urlencode($date_fin_str)
        .'&statut='.(int)$statut
        .'&inclure_avoirs='.($inclure_avoirs?1:0);
    print '<input type="submit" class="butAction" value="'.dol_htmlentities($langs->trans('BtnPrevisualiser')).'">&nbsp;&nbsp;';
    print '<a class="butAction" href="export_excel.php?token='.newToken().$qs.'">'.$langs->trans('BtnTelechargerExcel').'</a>&nbsp;&nbsp;';
    print '<a class="butAction" href="export_fec.php?token='.newToken().'&date_debut='.urlencode($date_debut_str).'&date_fin='.urlencode($date_fin_str).'">'.$langs->trans('BtnExporterFEC').'</a>';
}
print '</div>';
print '</form>';

if (!empty($lignes_preview)) {
    print '<br><p style="color:#888;font-size:13px">'.count($lignes_preview).' lignes sur '.$nb_total.' total.</p>';
    switch ($type_export) {
        case 'ventes':     ae_preview_ventes($lignes_preview, $langs);     break;
        case 'achats':     ae_preview_achats($lignes_preview, $langs);     break;
        case 'grandlivre': ae_preview_grandlivre($lignes_preview, $langs); break;
        case 'reglements': ae_preview_reglements($lignes_preview, $langs); break;
        default: print '<p class="opacitymedium">Previsualisation non disponible — utilisez Telecharger Excel.</p>';
    }
} elseif ($action === 'preview' && empty($erreurs)) {
    print '<br><div class="warning">Aucune facture trouvee sur cette periode.</div>';
}

llxFooter();
$db->close();

function ae_preview_ventes($rows, $langs) {
    print '<div class="div-table-responsive"><table class="noborder centpercent">';
    print '<tr class="liste_titre"><td>Date</td><td>N Facture</td><td>Client</td><td class="right">HT</td><td class="right">TVA</td><td class="right">TTC</td><td>Statut</td><td>Transfere</td></tr>';
    foreach ($rows as $f) {
        print '<tr class="oddeven">';
        print '<td>'.dol_htmlentities(accountingexport_format_date($f->date_facture)).'</td>';
        print '<td>'.dol_htmlentities($f->num_facture).'</td>';
        print '<td>'.dol_htmlentities($f->client_nom).'</td>';
        print '<td class="right">'.price(accountingexport_format_montant($f->total_ht)).'</td>';
        print '<td class="right">'.price(accountingexport_format_montant($f->total_tva)).'</td>';
        print '<td class="right">'.price(accountingexport_format_montant($f->total_ttc)).'</td>';
        print '<td>'.dol_htmlentities(accountingexport_libelle_statut_client($f->statut, isset($f->type)?$f->type:0)).'</td>';
        $ok = !empty($f->transfert_compta);
        print '<td><span class="badge badge-status'.($ok?'4':'1').'">'.($ok?'Oui':'Non').'</span></td>';
        print '</tr>';
    }
    print '</table></div>';
}

function ae_preview_achats($rows, $langs) {
    print '<div class="div-table-responsive"><table class="noborder centpercent">';
    print '<tr class="liste_titre"><td>Date</td><td>N Facture</td><td>Fournisseur</td><td>Ref</td><td class="right">HT</td><td class="right">TVA</td><td class="right">TTC</td><td>Statut</td></tr>';
    foreach ($rows as $f) {
        print '<tr class="oddeven">';
        print '<td>'.dol_htmlentities(accountingexport_format_date($f->date_facture)).'</td>';
        print '<td>'.dol_htmlentities($f->num_facture).'</td>';
        print '<td>'.dol_htmlentities($f->fournisseur_nom).'</td>';
        print '<td>'.dol_htmlentities($f->ref_fournisseur).'</td>';
        print '<td class="right">'.price(accountingexport_format_montant($f->total_ht)).'</td>';
        print '<td class="right">'.price(accountingexport_format_montant($f->total_tva)).'</td>';
        print '<td class="right">'.price(accountingexport_format_montant($f->total_ttc)).'</td>';
        print '<td>'.dol_htmlentities(accountingexport_libelle_statut_fourn($f->statut)).'</td>';
        print '</tr>';
    }
    print '</table></div>';
}

function ae_preview_grandlivre($rows, $langs) {
    print '<div class="div-table-responsive"><table class="noborder centpercent">';
    print '<tr class="liste_titre"><td>Date</td><td>Journal</td><td>Compte</td><td>Intitule</td><td>Libelle</td><td class="right">Debit</td><td class="right">Credit</td><td class="right">Solde</td></tr>';
    foreach ($rows as $l) {
        print '<tr class="oddeven">';
        print '<td>'.dol_htmlentities(accountingexport_format_date($l->date_ecriture)).'</td>';
        print '<td>'.dol_htmlentities($l->journal_code).'</td>';
        print '<td>'.dol_htmlentities($l->compte).'</td>';
        print '<td>'.dol_htmlentities($l->intitule_compte).'</td>';
        print '<td>'.dol_htmlentities($l->libelle).'</td>';
        print '<td class="right">'.price(accountingexport_format_montant($l->debit)).'</td>';
        print '<td class="right">'.price(accountingexport_format_montant($l->credit)).'</td>';
        $s = accountingexport_format_montant($l->solde_cumule);
        print '<td class="right" style="color:'.($s>=0?'green':'red').'">'.price($s).'</td>';
        print '</tr>';
    }
    print '</table></div>';
}

function ae_preview_reglements($rows, $langs) {
    print '<div class="div-table-responsive"><table class="noborder centpercent">';
    print '<tr class="liste_titre"><td>Date</td><td>Type</td><td>Mode</td><td>Tiers</td><td>Facture</td><td class="right">Montant</td><td>Banque</td></tr>';
    foreach ($rows as $r) {
        print '<tr class="oddeven">';
        print '<td>'.dol_htmlentities(accountingexport_format_date($r->date_reglement)).'</td>';
        print '<td>'.dol_htmlentities($r->type_tiers==='client'?'Client':'Fourn.').'</td>';
        print '<td>'.dol_htmlentities($r->mode_reglement).'</td>';
        print '<td>'.dol_htmlentities($r->tiers).'</td>';
        print '<td>'.dol_htmlentities($r->num_facture).'</td>';
        print '<td class="right">'.price(accountingexport_format_montant($r->amount)).'</td>';
        print '<td>'.dol_htmlentities($r->compte_banque).'</td>';
        print '</tr>';
    }
    print '</table></div>';
}

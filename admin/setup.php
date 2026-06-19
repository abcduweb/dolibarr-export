<?php
/* custom/accountingexport/admin/setup.php */

$res = 0;
if (!$res && file_exists("../../main.inc.php"))       { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php"))    { $res = @include "../../../main.inc.php"; }
if (!$res && file_exists("../../../../main.inc.php")) { $res = @include "../../../../main.inc.php"; }
if (!$res) { die('Impossible de charger Dolibarr'); }

define('INC_FROM_DOLIBARR', 1);
if (!$user->admin) { accessforbidden(); }

$langs->loadLangs(array('accountingexport@accountingexport', 'admin'));
$action = GETPOST('action', 'aZ09');

if ($action === 'setconfig') {
    checkToken();
    $params = array(
        'ACCOUNTINGEXPORT_ACCOUNT_CLIENT','ACCOUNTINGEXPORT_ACCOUNT_FOURNISSEUR',
        'ACCOUNTINGEXPORT_ACCOUNT_VENTES','ACCOUNTINGEXPORT_ACCOUNT_ACHATS',
        'ACCOUNTINGEXPORT_ACCOUNT_TVA20','ACCOUNTINGEXPORT_ACCOUNT_TVA10',
        'ACCOUNTINGEXPORT_ACCOUNT_TVA55','ACCOUNTINGEXPORT_ACCOUNT_TVA21',
        'ACCOUNTINGEXPORT_ACCOUNT_TVA_DED20','ACCOUNTINGEXPORT_ACCOUNT_TVA_DED10',
        'ACCOUNTINGEXPORT_ACCOUNT_TVA_DED55','ACCOUNTINGEXPORT_ACCOUNT_BANQUE',
        'ACCOUNTINGEXPORT_JOURNAL_VENTES','ACCOUNTINGEXPORT_JOURNAL_ACHATS',
        'ACCOUNTINGEXPORT_JOURNAL_BANQUE','ACCOUNTINGEXPORT_JOURNAL_OD',
        'ACCOUNTINGEXPORT_FEC_SEPARATOR',
    );
    $ok = true;
    foreach ($params as $p) {
        $v = GETPOST($p, 'alphanohtml');
        if ($v !== false && dolibarr_set_const($db, $p, $v, 'chaine', 1, '', $conf->entity) < 0) { $ok = false; }
    }
    setEventMessages($ok ? $langs->trans('ConfigSaved') : 'Erreur de sauvegarde', null, $ok?'mesgs':'errors');
}

llxHeader('', $langs->trans('AccountingExportConfig'), '');
$back = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('AccountingExportConfig'), $back, 'accountingexport@accountingexport');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="setconfig">';

// ── PCG ───────────────────────────────────────────────────────────────────────
print load_fiche_titre($langs->trans('ConfigPCG'), '', '');
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td style="width:38%">Paramètre</td><td>Valeur</td><td>Description</td></tr>';

$pcg_rows = array(
    'ACCOUNTINGEXPORT_ACCOUNT_CLIENT'     => array('Compte clients (411xxx)',      '411000', 'Compte de regroupement clients'),
    'ACCOUNTINGEXPORT_ACCOUNT_FOURNISSEUR'=> array('Compte fournisseurs (401xxx)', '401000', 'Compte de regroupement fournisseurs'),
    'ACCOUNTINGEXPORT_ACCOUNT_VENTES'     => array('Compte ventes (7xxxxx)',       '706000', 'Compte produits ventes'),
    'ACCOUNTINGEXPORT_ACCOUNT_ACHATS'     => array('Compte achats (6xxxxx)',       '607000', 'Compte charges achats'),
    'ACCOUNTINGEXPORT_ACCOUNT_TVA20'      => array('TVA collectée 20%',            '445710', '→ 445710'),
    'ACCOUNTINGEXPORT_ACCOUNT_TVA10'      => array('TVA collectée 10%',            '445711', '→ 445711'),
    'ACCOUNTINGEXPORT_ACCOUNT_TVA55'      => array('TVA collectée 5,5%',           '445712', '→ 445712'),
    'ACCOUNTINGEXPORT_ACCOUNT_TVA21'      => array('TVA collectée 2,1%',           '445713', '→ 445713'),
    'ACCOUNTINGEXPORT_ACCOUNT_TVA_DED20'  => array('TVA déductible 20%',           '445660', '→ 445660'),
    'ACCOUNTINGEXPORT_ACCOUNT_TVA_DED10'  => array('TVA déductible 10%',           '445661', '→ 445661'),
    'ACCOUNTINGEXPORT_ACCOUNT_TVA_DED55'  => array('TVA déductible 5,5%',          '445662', '→ 445662'),
    'ACCOUNTINGEXPORT_ACCOUNT_BANQUE'     => array('Compte banque (512xxx)',        '512000', 'Compte banque principal'),
);
foreach ($pcg_rows as $k => $v) {
    $cur = isset($conf->global->$k) ? $conf->global->$k : $v[1];
    print '<tr class="oddeven"><td>'.dol_htmlentities($v[0]).'</td>';
    print '<td><input type="text" name="'.dol_htmlentities($k).'" class="flat minwidth150" value="'.dol_htmlentities($cur).'" maxlength="20"></td>';
    print '<td class="opacitymedium small">'.dol_htmlentities($v[2]).'</td></tr>';
}
print '</table></div><br>';

// ── Journaux ──────────────────────────────────────────────────────────────────
print load_fiche_titre($langs->trans('ConfigJournaux'), '', '');
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td style="width:38%">Journal</td><td>Code</td><td>Description</td></tr>';
$j_rows = array(
    'ACCOUNTINGEXPORT_JOURNAL_VENTES' => array('Journal ventes',  'VTE', 'Factures clients'),
    'ACCOUNTINGEXPORT_JOURNAL_ACHATS' => array('Journal achats',  'ACH', 'Factures fournisseurs'),
    'ACCOUNTINGEXPORT_JOURNAL_BANQUE' => array('Journal banque',  'BAN', 'Règlements banque/caisse'),
    'ACCOUNTINGEXPORT_JOURNAL_OD'     => array('Journal OD',      'OD',  'Opérations diverses'),
);
foreach ($j_rows as $k => $v) {
    $cur = isset($conf->global->$k) ? $conf->global->$k : $v[1];
    print '<tr class="oddeven"><td>'.dol_htmlentities($v[0]).'</td>';
    print '<td><input type="text" name="'.dol_htmlentities($k).'" class="flat minwidth100" value="'.dol_htmlentities($cur).'" maxlength="10"></td>';
    print '<td class="opacitymedium small">'.dol_htmlentities($v[2]).'</td></tr>';
}
print '</table></div><br>';

// ── FEC ───────────────────────────────────────────────────────────────────────
print load_fiche_titre($langs->trans('ConfigFEC'), '', '');
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td style="width:38%">Paramètre</td><td>Valeur</td><td>Description</td></tr>';

$sep_cur = isset($conf->global->ACCOUNTINGEXPORT_FEC_SEPARATOR) ? $conf->global->ACCOUNTINGEXPORT_FEC_SEPARATOR : 'tab';
print '<tr class="odd"><td>'.$langs->trans('FECSeparateur').'</td>';
print '<td><select name="ACCOUNTINGEXPORT_FEC_SEPARATOR" class="flat">';
print '<option value="tab"'.($sep_cur==='tab'?' selected':'').'>Tabulation (\t) — recommandé</option>';
print '<option value="pipe"'.($sep_cur==='pipe'?' selected':'').'>Pipe (|)</option>';
print '</select></td>';
print '<td class="opacitymedium small">Séparateur de champs dans le fichier FEC</td></tr>';

// SIRET (lecture seule)
$siret = ''; $rs = $db->query("SELECT siren FROM ".MAIN_DB_PREFIX."societe WHERE entity=".((int)$conf->entity)." LIMIT 1");
if ($rs && ($o = $db->fetch_object($rs))) { $siret = $o->siren; $db->free($rs); }
print '<tr class="even"><td>SIRET de la société</td>';
print '<td>';
if ($siret) { print '<strong>'.dol_htmlentities($siret).'</strong> <span class="badge badge-status4">Configuré</span>'; }
else { print '<span class="badge badge-status1">Non configuré</span> — <a href="'.DOL_URL_ROOT.'/societe/edit.php">Compléter la fiche société</a>'; }
print '</td>';
print '<td class="opacitymedium small">Utilisé pour le nom du fichier FEC : {SIRET}FEC{AAAAMMJJ}.txt</td></tr>';
print '</table></div><br>';

// Info module Accounting
print '<div class="info">';
if (isModEnabled('accounting')) {
    print 'Module Comptabilité Dolibarr : <strong>actif</strong> — les données de <code>llx_accounting_bookkeeping</code> sont utilisées en priorité.';
} else {
    print 'Module Comptabilité Dolibarr : <strong>inactif</strong> — les exports sont reconstruits depuis <code>llx_facture</code> / <code>llx_facture_fourn</code>.';
}

// Info PhpSpreadsheet
define('INC_FROM_DOLIBARR', 1);
require_once DOL_DOCUMENT_ROOT.'/custom/accountingexport/lib/accountingexport.lib.php';
$has_ss = accountingexport_load_spreadsheet();
print '<br>PhpSpreadsheet : ';
if ($has_ss) {
    print '<strong style="color:green">Disponible</strong> — Export XLSX activé.';
} else {
    print '<strong style="color:orange">Non détecté</strong> — L\'export utilisera le format <strong>CSV</strong> (compatible Excel). Pour activer XLSX : <code>composer require phpoffice/phpspreadsheet</code> dans le dossier Dolibarr.';
}
print '</div><br>';

print '<div class="center"><input type="submit" class="butAction" value="'.$langs->trans('Save').'"></div>';
print '</form>';

llxFooter();
$db->close();

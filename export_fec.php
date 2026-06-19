<?php
/* custom/accountingexport/export_fec.php */

@ini_set('memory_limit',       '256M');
@ini_set('max_execution_time', '120');

$res = 0;
if (!$res && file_exists("../main.inc.php"))          { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php"))       { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php"))    { $res = @include "../../../main.inc.php"; }
if (!$res && file_exists("../../../../main.inc.php")) { $res = @include "../../../../main.inc.php"; }
if (!$res) { die('Impossible de charger Dolibarr'); }

define('INC_FROM_DOLIBARR', 1);
require_once DOL_DOCUMENT_ROOT.'/custom/accountingexport/lib/accountingexport.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/accountingexport/lib/accountingexport_fec.lib.php';

function ae_has_right_x($u,$m,$p){if(!empty($u->admin))return true;if(method_exists($u,'hasRight')&&$u->hasRight($m,$p))return true;if(!empty($u->rights->$m->$p))return true;return false;}
if(!ae_has_right_x($user,'accountingexport','export')){accessforbidden();}
// Verification CSRF compatible Dolibarr 14 a 22
$_ae_tok = GETPOST('token', 'alpha');
if (empty($user->admin) && !empty($_SESSION['newtoken']) && $_ae_tok !== $_SESSION['newtoken']) {
    accessforbidden('Security token mismatch');
}
$langs->loadLangs(array('accountingexport@accountingexport'));

$date_debut = GETPOST('date_debut', 'alpha');
$date_fin   = GETPOST('date_fin',   'alpha');

if (empty($date_debut) || empty($date_fin)) {
    setEventMessages($langs->trans('PeriodeRequise'), null, 'errors');
    header('Location: accountingexport_page.php');
    exit;
}

$siret = accountingexport_get_siret($db, $conf);
if (empty($siret)) {
    // On continue mais on log le warning
    error_log('AccountingExport : SIRET manquant dans la fiche société');
    $siret = 'SIRET_INCONNU';
}

$sep = !empty($conf->global->ACCOUNTINGEXPORT_FEC_SEPARATOR) ? $conf->global->ACCOUNTINGEXPORT_FEC_SEPARATOR : 'tab';
$pcg = accountingexport_get_pcg_mapping($conf);

try {
    $lignes = array();
    $seq = 1;

    try {
        $l = fec_get_lignes_ventes($db, $date_debut, $date_fin, $pcg, $conf, $seq);
        $lignes = array_merge($lignes, $l);
    } catch (Exception $e) { error_log('FEC ventes : '.$e->getMessage()); }

    $seq = 1;
    try {
        $l = fec_get_lignes_achats($db, $date_debut, $date_fin, $pcg, $conf, $seq);
        $lignes = array_merge($lignes, $l);
    } catch (Exception $e) { error_log('FEC achats : '.$e->getMessage()); }

    $seq = 1;
    try {
        $l = fec_get_lignes_reglements($db, $date_debut, $date_fin, $pcg, $conf, $seq);
        $lignes = array_merge($lignes, $l);
    } catch (Exception $e) { error_log('FEC banque : '.$e->getMessage()); }

    if (empty($lignes)) {
        setEventMessages($langs->trans('AucuneEcriture'), null, 'warnings');
        header('Location: accountingexport_page.php');
        exit;
    }

    // Tri chronologique
    usort($lignes, function($a, $b) {
        $d = strcmp($a['EcritureDate'], $b['EcritureDate']);
        return $d !== 0 ? $d : strcmp($a['EcritureNum'], $b['EcritureNum']);
    });

    // Validation équilibre (log uniquement, pas bloquant)
    $err = fec_validate_equilibre($lignes);
    if (!empty($err)) {
        error_log('FEC — '.count($err).' écriture(s) non équilibrée(s) : '.implode(', ', array_slice($err, 0, 5)));
    }

    $contenu  = fec_serialize($lignes, $sep);
    $filename = fec_get_filename($siret, $date_fin);

    accountingexport_log_export($db, $user, 'fec', $date_debut, $date_fin, count($lignes));

    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.rawurlencode($filename).'"');
    header('Content-Length: '.strlen($contenu));
    header('Cache-Control: max-age=0');

    echo $contenu;
    exit;

} catch (Exception $e) {
    setEventMessages('Erreur FEC : '.$e->getMessage(), null, 'errors');
    header('Location: accountingexport_page.php');
    exit;
}

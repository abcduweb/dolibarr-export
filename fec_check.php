<?php
/* custom/accountingexport/fec_check.php
   Verificateur structurel de FEC (norme DGFiP - article A47 A-1 du LPF).
   Analyse un fichier FEC (genere par ce module ou tout autre logiciel) et
   pointe precisement les anomalies, ligne par ligne.

   Ceci est un controle STRUCTUREL local (colonnes, separateur, dates,
   equilibre debit/credit). Il ne remplace pas le logiciel officiel de la
   DGFiP "Test Compta Demat" (gratuit, telechargeable sur le site
   impots.gouv.fr - espace professionnel), qui reste la reference ultime
   en cas de controle fiscal. Utiliser les deux en complement.
*/

$res = 0;
if (!$res && file_exists("../main.inc.php"))          { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php"))       { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php"))    { $res = @include "../../../main.inc.php"; }
if (!$res && file_exists("../../../../main.inc.php")) { $res = @include "../../../../main.inc.php"; }
if (!$res) { die('Impossible de charger Dolibarr'); }

define('INC_FROM_DOLIBARR', 1);

function ae_has_right_fc($u,$m,$p){if(!empty($u->admin))return true;if(method_exists($u,'hasRight')&&$u->hasRight($m,$p))return true;if(!empty($u->rights->$m->$p))return true;return false;}
if (!ae_has_right_fc($user, 'accountingexport', 'export')) { accessforbidden(); }

$langs->loadLangs(array('accountingexport@accountingexport'));

$action = GETPOST('action', 'aZ09');
$rapport = null;

// Colonnes exactes imposees par l'article A47 A-1 du LPF, dans cet ordre.
$AE_FEC_COLS = array(
    'JournalCode','JournalLib','EcritureNum','EcritureDate',
    'CompteNum','CompteLib','CompAuxNum','CompAuxLib',
    'PieceRef','PieceDate','EcritureLib',
    'Debit','Credit','EcritureLet','DateLet','ValidDate',
    'Montantdevise','Idevise',
);

/**
 * Analyse le contenu brut d'un fichier FEC et retourne un rapport structure.
 *
 * @param  string  $contenu    Contenu brut du fichier (tel que lu sur disque)
 * @param  string  $filename   Nom de fichier (pour verifier la nomenclature)
 * @param  array   $cols_attendues
 * @return array   ['erreurs'=>[], 'avertissements'=>[], 'infos'=>[], 'stats'=>[]]
 */
function ae_fec_check($contenu, $filename, $cols_attendues)
{
    $erreurs = array();
    $avertissements = array();
    $infos = array();

    // BOM UTF-8
    $bom = substr($contenu, 0, 3) === "\xEF\xBB\xBF";
    if (!$bom) {
        $avertissements[] = "Le fichier ne commence pas par un BOM UTF-8 — recommande pour que Excel/LibreOffice interpretent correctement les accents.";
    } else {
        $contenu = substr($contenu, 3);
    }

    // Nom de fichier : SIRENFECAAAAMMJJ.txt
    if ($filename) {
        if (!preg_match('/^[0-9]{9}FEC[0-9]{8}\.txt$/i', basename($filename))) {
            $erreurs[] = "Nom de fichier \"".basename($filename)."\" non conforme. Attendu : SIRENFECAAAAMMJJ.txt (9 chiffres SIREN + FEC + date de cloture AAAAMMJJ + .txt).";
        } else {
            $infos[] = "Nom de fichier conforme a la nomenclature SIRENFECAAAAMMJJ.txt.";
        }
    }

    // Decoupage en lignes (tolere CRLF et LF)
    $lignes_brutes = preg_split('/\r\n|\n|\r/', $contenu);
    // Retire les lignes vides en fin de fichier
    while (!empty($lignes_brutes) && trim(end($lignes_brutes)) === '') { array_pop($lignes_brutes); }

    if (empty($lignes_brutes)) {
        $erreurs[] = "Fichier vide.";
        return array('erreurs'=>$erreurs, 'avertissements'=>$avertissements, 'infos'=>$infos, 'stats'=>array());
    }

    // Detection du separateur sur la ligne d'en-tete (tab ou pipe uniquement - conforme)
    $entete = $lignes_brutes[0];
    $nb_tab  = substr_count($entete, "\t");
    $nb_pipe = substr_count($entete, "|");
    $nb_scol = substr_count($entete, ";");

    if ($nb_tab >= 17) {
        $sep = "\t"; $sep_label = 'tabulation';
    } elseif ($nb_pipe >= 17) {
        $sep = "|"; $sep_label = 'pipe (|)';
    } elseif ($nb_scol >= 17) {
        $erreurs[] = "Separateur detecte : point-virgule (;). Ce n'est PAS un separateur valide pour un FEC — le logiciel de la DGFiP ne le reconnait pas. Seuls la tabulation ou le pipe (|) sont acceptes.";
        $sep = ";"; $sep_label = 'point-virgule (NON CONFORME)';
    } else {
        $erreurs[] = "Impossible de detecter un separateur valide (tabulation ou pipe) sur la ligne d'en-tete. Verifiez le fichier.";
        return array('erreurs'=>$erreurs, 'avertissements'=>$avertissements, 'infos'=>$infos, 'stats'=>array());
    }
    $infos[] = "Separateur detecte : ".$sep_label.".";

    // Verification de l'en-tete : colonnes exactes, dans l'ordre exact
    $entete_cols = explode($sep, $entete);
    if (count($entete_cols) !== count($cols_attendues)) {
        $erreurs[] = "Ligne d'en-tete : ".count($entete_cols)." colonne(s) trouvee(s), ".count($cols_attendues)." attendue(s).";
    }
    foreach ($cols_attendues as $i => $c) {
        $trouve = isset($entete_cols[$i]) ? trim($entete_cols[$i]) : null;
        if ($trouve !== $c) {
            $erreurs[] = "En-tete colonne ".($i+1).' : attendu "'.$c.'", trouve "'.($trouve === null ? '(absent)' : $trouve).'".';
        }
    }

    // Analyse ligne par ligne des ecritures
    $nb_lignes_data = 0;
    $tot_debit = 0.0;
    $tot_credit = 0.0;
    $par_ecriture = array(); // EcritureNum => ['d'=>x,'c'=>y,'premiere_ligne'=>n]
    $piece_num_par_journal_jour = array();

    for ($i = 1; $i < count($lignes_brutes); $i++) {
        $num_ligne = $i + 1; // numero de ligne humain (1 = en-tete)
        $ligne = $lignes_brutes[$i];
        if (trim($ligne) === '') { continue; }
        $nb_lignes_data++;

        $champs = explode($sep, $ligne);
        if (count($champs) !== count($cols_attendues)) {
            $erreurs[] = "Ligne ".$num_ligne." : ".count($champs)." champ(s) trouve(s), ".count($cols_attendues)." attendu(s).";
            continue;
        }
        $row = array_combine($cols_attendues, $champs);

        // Dates au format AAAAMMJJ
        foreach (array('EcritureDate','PieceDate','ValidDate') as $dc) {
            $v = trim($row[$dc]);
            if ($dc === 'ValidDate' && $v === '') { continue; } // tolere vide si non valide
            if (!preg_match('/^\d{8}$/', $v)) {
                $erreurs[] = "Ligne ".$num_ligne." : champ ".$dc." = \"".$v."\" — format attendu AAAAMMJJ (8 chiffres).";
                continue;
            }
            $aa = (int)substr($v,0,4); $mm = (int)substr($v,4,2); $jj = (int)substr($v,6,2);
            if (!checkdate($mm, $jj, $aa)) {
                $erreurs[] = "Ligne ".$num_ligne." : champ ".$dc." = \"".$v."\" — date invalide.";
            }
        }

        // Champs obligatoires non vides
        foreach (array('JournalCode','EcritureNum','CompteNum','PieceRef','EcritureLib') as $rc) {
            if (trim($row[$rc]) === '') {
                $erreurs[] = "Ligne ".$num_ligne." : champ obligatoire ".$rc." est vide.";
            }
        }

        // Montants : virgule decimale, pas de point ni de symbole monetaire
        foreach (array('Debit','Credit') as $mc) {
            $v = trim($row[$mc]);
            if ($v === '') { $v = '0,00'; }
            if (!preg_match('/^-?\d+(,\d+)?$/', $v)) {
                $erreurs[] = "Ligne ".$num_ligne." : champ ".$mc." = \"".$v."\" — format numerique invalide (virgule attendue comme separateur decimal, pas de point ni d'espace).";
                continue;
            }
            $f = (float) str_replace(',', '.', $v);
            if ($mc === 'Debit')  $tot_debit  += $f;
            if ($mc === 'Credit') $tot_credit += $f;
        }
        if (trim($row['Debit']) !== '' && trim($row['Credit']) !== ''
            && (float)str_replace(',','.',$row['Debit']) > 0 && (float)str_replace(',','.',$row['Credit']) > 0) {
            $erreurs[] = "Ligne ".$num_ligne." : Debit ET Credit sont tous les deux non nuls sur la meme ligne — une ligne d'ecriture ne doit mouvementer un compte que dans un seul sens.";
        }

        // Equilibre par EcritureNum (cle = JournalCode + EcritureNum, plus sur)
        $cle = trim($row['JournalCode']).'|'.trim($row['EcritureNum']);
        if (!isset($par_ecriture[$cle])) {
            $par_ecriture[$cle] = array('d'=>0,'c'=>0,'premiere_ligne'=>$num_ligne);
        }
        $par_ecriture[$cle]['d'] += (float) str_replace(',', '.', $row['Debit'] !== '' ? $row['Debit'] : '0');
        $par_ecriture[$cle]['c'] += (float) str_replace(',', '.', $row['Credit'] !== '' ? $row['Credit'] : '0');
    }

    // Equilibre par ecriture
    $nb_desequilibrees = 0;
    foreach ($par_ecriture as $cle => $v) {
        if (abs($v['d'] - $v['c']) > 0.01) {
            $nb_desequilibrees++;
            if ($nb_desequilibrees <= 30) { // on plafonne l'affichage pour rester lisible
                list($jc, $en) = explode('|', $cle, 2);
                $erreurs[] = "Ecriture ".$jc."/".$en." (premiere ligne ".$v['premiere_ligne'].") desequilibree : Debit=".number_format($v['d'],2,',',' ')." / Credit=".number_format($v['c'],2,',',' ').".";
            }
        }
    }
    if ($nb_desequilibrees > 30) {
        $erreurs[] = "... et ".($nb_desequilibrees - 30)." autre(s) ecriture(s) desequilibree(s) (liste tronquee).";
    }

    // Equilibre global
    if (abs($tot_debit - $tot_credit) > 0.01) {
        $erreurs[] = "Total general desequilibre : Debit=".number_format($tot_debit,2,',',' ')." / Credit=".number_format($tot_credit,2,',',' ')." (ecart de ".number_format(abs($tot_debit-$tot_credit),2,',',' ').").";
    } else {
        $infos[] = "Total general equilibre : Debit = Credit = ".number_format($tot_debit,2,',',' ').".";
    }

    $stats = array(
        'nb_lignes_donnees' => $nb_lignes_data,
        'nb_ecritures'      => count($par_ecriture),
        'total_debit'       => $tot_debit,
        'total_credit'      => $tot_credit,
        'nb_desequilibrees' => $nb_desequilibrees,
    );

    return array('erreurs'=>$erreurs, 'avertissements'=>$avertissements, 'infos'=>$infos, 'stats'=>$stats);
}

if ($action === 'check' && ae_has_right_fc($user, 'accountingexport', 'export')) {
    $_ae_tok = GETPOST('token', 'alpha');
    if (empty($user->admin) && !empty($_SESSION['newtoken']) && $_ae_tok !== $_SESSION['newtoken']) {
        accessforbidden('Security token mismatch');
    }

    $contenu = null;
    $filename = null;

    if (!empty($_FILES['fec_file']['tmp_name']) && is_uploaded_file($_FILES['fec_file']['tmp_name'])) {
        $contenu  = file_get_contents($_FILES['fec_file']['tmp_name']);
        $filename = $_FILES['fec_file']['name'];
    } else {
        $coller = GETPOST('fec_contenu', 'restricthtml');
        if (!empty($coller)) { $contenu = $coller; }
    }

    if ($contenu === null || trim($contenu) === '') {
        setEventMessages('Aucun fichier ni contenu fourni.', null, 'errors');
    } else {
        $rapport = ae_fec_check($contenu, $filename, $AE_FEC_COLS);
    }
}

llxHeader('', 'Verification FEC', '');
print load_fiche_titre('Verification de conformite FEC', '', 'accountingexport@accountingexport');

print '<div class="info">Controle <b>structurel local</b> (colonnes, separateur, dates, equilibre debit/credit). ';
print 'Ne remplace pas le logiciel officiel <b>Test Compta Demat</b> de la DGFiP (gratuit, espace professionnel sur impots.gouv.fr), ';
print 'qui reste la reference en cas de controle fiscal. A utiliser en complement.</div><br>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="check">';
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2"><b>Fichier a verifier</b></td></tr>';
print '<tr class="oddeven"><td style="width:25%">Uploader un fichier .txt</td><td><input type="file" name="fec_file" accept=".txt"></td></tr>';
print '<tr class="oddeven"><td>Ou coller le contenu</td><td><textarea name="fec_contenu" rows="6" class="quatrevingtpercent" placeholder="Coller ici le contenu du FEC si vous n\'avez pas le fichier sous la main"></textarea></td></tr>';
print '</table></div>';
print '<div class="center" style="margin-top:14px"><input type="submit" class="butAction" value="Analyser"></div>';
print '</form>';

if ($rapport !== null) {
    print '<br><div class="div-table-responsive"><table class="noborder centpercent">';
    print '<tr class="liste_titre"><td colspan="2"><b>Resultat</b></td></tr>';

    if (!empty($rapport['stats'])) {
        $s = $rapport['stats'];
        print '<tr class="oddeven"><td>Lignes de donnees</td><td>'.$s['nb_lignes_donnees'].'</td></tr>';
        print '<tr class="oddeven"><td>Ecritures distinctes</td><td>'.$s['nb_ecritures'].'</td></tr>';
        print '<tr class="oddeven"><td>Total Debit</td><td>'.number_format($s['total_debit'],2,',',' ').' €</td></tr>';
        print '<tr class="oddeven"><td>Total Credit</td><td>'.number_format($s['total_credit'],2,',',' ').' €</td></tr>';
        print '<tr class="oddeven"><td>Ecritures desequilibrees</td><td>'.$s['nb_desequilibrees'].'</td></tr>';
    }
    print '</table></div>';

    if (empty($rapport['erreurs'])) {
        print '<br><div class="ok" style="color:green;font-weight:bold">Aucune erreur structurelle detectee.</div>';
    } else {
        print '<br><div class="warning"><b>'.count($rapport['erreurs']).' erreur(s) detectee(s) :</b><ul>';
        foreach ($rapport['erreurs'] as $e) { print '<li>'.dol_htmlentities($e).'</li>'; }
        print '</ul></div>';
    }

    if (!empty($rapport['avertissements'])) {
        print '<br><div class="warning"><b>Avertissements :</b><ul>';
        foreach ($rapport['avertissements'] as $a) { print '<li>'.dol_htmlentities($a).'</li>'; }
        print '</ul></div>';
    }

    if (!empty($rapport['infos'])) {
        print '<br><div class="opacitymedium"><ul>';
        foreach ($rapport['infos'] as $inf) { print '<li>'.dol_htmlentities($inf).'</li>'; }
        print '</ul></div>';
    }
}

llxFooter();
$db->close();

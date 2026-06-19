<?php
/* diag_sql.php — À déposer dans /custom/accountingexport/, ouvrir, puis supprimer */

$res = 0;
if (!$res && file_exists("../main.inc.php"))       { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php"))    { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die('main.inc.php introuvable'); }

echo '<style>body{font-family:monospace;padding:20px} table{border-collapse:collapse;width:100%} td,th{border:1px solid #ccc;padding:4px 8px;font-size:12px} th{background:#1F4E79;color:#fff} .ok{color:green} .err{color:red} h3{margin-top:20px}</style>';
echo '<h2>Diagnostic SQL — AccountingExport</h2>';

// 1. Répartition des factures par année
echo '<h3>1. Factures clients par année (llx_facture)</h3>';
$sql = "SELECT YEAR(datef) AS annee, fk_statut, COUNT(*) AS nb, SUM(total_ht) AS total_ht
        FROM ".MAIN_DB_PREFIX."facture
        WHERE entity IN (".getEntity('invoice').")
        GROUP BY YEAR(datef), fk_statut
        ORDER BY annee DESC, fk_statut";
$res2 = $db->query($sql);
if ($res2) {
    echo '<table><tr><th>Année</th><th>Statut (0=brouillon,1=validée,2=payée)</th><th>Nb factures</th><th>Total HT</th></tr>';
    while ($o = $db->fetch_object($res2)) {
        echo '<tr><td>'.$o->annee.'</td><td>'.$o->fk_statut.'</td><td>'.$o->nb.'</td><td>'.number_format($o->total_ht,2,',',' ').' €</td></tr>';
    }
    echo '</table>';
} else { echo '<p class="err">Erreur : '.$db->lasterror().'</p>'; }

// 2. Test de la requête exacte du module (2026-01-01 → 2026-03-31)
echo '<h3>2. Test requête module — période 2026-01-01 → 2026-03-31</h3>';
$sql2 = "SELECT COUNT(*) AS nb, SUM(total_ht) AS ht, SUM(total_ttc) AS ttc
         FROM ".MAIN_DB_PREFIX."facture
         WHERE entity IN (".getEntity('invoice').")
         AND datef BETWEEN '2026-01-01' AND '2026-03-31'";
$res2 = $db->query($sql2);
if ($res2 && ($o = $db->fetch_object($res2))) {
    echo '<p class="'.($o->nb>0?'ok':'err').'">'.$o->nb.' factures — HT : '.number_format($o->ht,2,',',' ').' € — TTC : '.number_format($o->ttc,2,',',' ').' €</p>';
} else { echo '<p class="err">Erreur : '.$db->lasterror().'</p>'; }

// 3. Test période 2025
echo '<h3>3. Test requête module — période 2025-01-01 → 2025-12-31</h3>';
$sql3 = "SELECT COUNT(*) AS nb FROM ".MAIN_DB_PREFIX."facture
         WHERE entity IN (".getEntity('invoice').") AND datef BETWEEN '2025-01-01' AND '2025-12-31'";
$res3 = $db->query($sql3);
if ($res3 && ($o = $db->fetch_object($res3))) {
    echo '<p class="'.($o->nb>0?'ok':'err').'">'.$o->nb.' factures sur 2025</p>';
}

// 4. Vérifier le champ datef (format stocké)
echo '<h3>4. Format du champ datef (5 premières factures)</h3>';
$sql4 = "SELECT ref, datef, fk_statut, total_ht FROM ".MAIN_DB_PREFIX."facture
         WHERE entity IN (".getEntity('invoice').") ORDER BY rowid DESC LIMIT 5";
$res4 = $db->query($sql4);
if ($res4) {
    echo '<table><tr><th>Réf.</th><th>datef (brut)</th><th>Statut</th><th>HT</th></tr>';
    while ($o = $db->fetch_object($res4)) {
        echo '<tr><td>'.$o->ref.'</td><td>'.$o->datef.'</td><td>'.$o->fk_statut.'</td><td>'.number_format($o->total_ht,2).'</td></tr>';
    }
    echo '</table>';
}

// 5. getEntity check
echo '<h3>5. Entité courante</h3>';
echo '<p>$conf->entity = '.$conf->entity.'</p>';
echo '<p>getEntity("invoice") = '.getEntity('invoice').'</p>';

echo '<hr><p style="color:#aaa;font-size:11px">Supprimez ce fichier après diagnostic.</p>';

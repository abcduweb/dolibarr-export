<?php
/* custom/accountingexport/diag.php
   Déposer ce fichier, ouvrir dans le navigateur, puis le supprimer.
   Ne nécessite PAS d'être connecté — à utiliser en cas d'erreur 500.
*/

// Pas de Dolibarr requis — diagnostic bas niveau
echo '<h2 style="font-family:monospace">Diagnostic AccountingExport v1.0.1</h2>';
echo '<style>body{font-family:monospace;padding:20px} .ok{color:green} .err{color:red} .warn{color:orange}</style>';

// PHP
echo '<h3>PHP</h3>';
echo '<p>Version : <b>'.phpversion().'</b></p>';
echo '<p>memory_limit : '.ini_get('memory_limit').'</p>';
echo '<p>max_execution_time : '.ini_get('max_execution_time').'s</p>';

// Chemin Dolibarr
echo '<h3>Chargement Dolibarr (main.inc.php)</h3>';
$candidates = array('../main.inc.php','../../main.inc.php','../../../main.inc.php','../../../../main.inc.php');
$main_found = null;
foreach ($candidates as $p) {
    $exists = file_exists($p);
    echo '<p class="'.($exists?'ok':'err').'">'.$p.' → '.($exists?'TROUVÉ':'absent').'</p>';
    if ($exists && !$main_found) $main_found = $p;
}

if ($main_found) {
    @include $main_found;
    if (defined('DOL_DOCUMENT_ROOT')) {
        echo '<p class="ok">DOL_DOCUMENT_ROOT = '.DOL_DOCUMENT_ROOT.'</p>';
    } else {
        echo '<p class="err">DOL_DOCUMENT_ROOT non défini après inclusion</p>';
    }
} else {
    echo '<p class="err">main.inc.php introuvable — vérifiez l\'emplacement du module</p>';
}

// PhpSpreadsheet
echo '<h3>PhpSpreadsheet</h3>';
if (defined('DOL_DOCUMENT_ROOT')) {
    $paths = array(
        DOL_DOCUMENT_ROOT.'/vendor/autoload.php',
        DOL_DOCUMENT_ROOT.'/includes/vendor/autoload.php',
        DOL_DOCUMENT_ROOT.'/../vendor/autoload.php',
        dirname(DOL_DOCUMENT_ROOT).'/vendor/autoload.php',
    );
    $found = false;
    foreach ($paths as $p) {
        $e = file_exists($p);
        echo '<p class="'.($e?'ok':'err').'">'.$p.' → '.($e?'TROUVÉ':'absent').'</p>';
        if ($e && !$found) { @include_once $p; $found = true; }
    }
    if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        echo '<p class="ok"><b>PhpSpreadsheet disponible — XLSX activé</b></p>';
    } else {
        echo '<p class="warn"><b>PhpSpreadsheet non disponible — le module utilisera le format CSV</b></p>';
        echo '<p>Pour activer XLSX : <code>cd '.DOL_DOCUMENT_ROOT.' &amp;&amp; composer require phpoffice/phpspreadsheet</code></p>';
    }
} else {
    echo '<p class="err">Impossible de tester (DOL_DOCUMENT_ROOT non défini)</p>';
}

// Fichiers du module
echo '<h3>Fichiers du module</h3>';
if (defined('DOL_DOCUMENT_ROOT')) {
    $files = array(
        'core/modules/modAccountingExport.class.php',
        'lib/accountingexport.lib.php',
        'lib/accountingexport_tva.lib.php',
        'lib/accountingexport_fec.lib.php',
        'accountingexport_page.php',
        'export_excel.php',
        'export_fec.php',
        'admin/setup.php',
        'langs/fr_FR/accountingexport.lang',
    );
    foreach ($files as $f) {
        $p = DOL_DOCUMENT_ROOT.'/custom/accountingexport/'.$f;
        $e = file_exists($p);
        echo '<p class="'.($e?'ok':'err').'">'.$f.' → '.($e?'OK':'MANQUANT').'</p>';
    }
}

echo '<hr><p style="color:#aaa;font-size:11px">Supprimez ce fichier après diagnostic.</p>';

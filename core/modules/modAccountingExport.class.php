<?php
/* custom/accountingexport/core/modules/modAccountingExport.class.php */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modAccountingExport extends DolibarrModules
{
    public function __construct($db)
    {
        global $langs, $conf;
        $this->db = $db;

        $this->numero        = 500100;
        $this->family        = 'financial';
        $this->module_position = 500;
        $this->name          = preg_replace('/^mod/i', '', get_class($this));
        $this->description   = 'Export comptable Excel et FEC conforme DGFiP (PCG français, TVA multi-taux)';
        $this->version       = '1.0.7';
        $this->const_name    = 'MAIN_MODULE_'.strtoupper($this->name);
        $this->picto         = 'accountingexport@accountingexport';
        $this->depends       = array();
        $this->langfiles     = array('accountingexport@accountingexport');
        $this->rights_class  = 'accountingexport';

        $r = 0;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Voir les exports comptables';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'read';
        $r++;

        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Générer et télécharger les exports (Excel, FEC)';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'export';
        $r++;

        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Configurer le module';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'admin';

        $this->menu = array();
        $r = 0;

        $this->menu[$r] = array(
            'fk_menu'  => 'fk_mainmenu=accountancy',
            'type'     => 'left',
            'titre'    => 'Exports comptables',
            'mainmenu' => 'accountancy',
            'leftmenu' => 'accountingexport',
            'url'      => '/accountingexport/accountingexport_page.php',
            'langs'    => 'accountingexport@accountingexport',
            'position' => 100,
            'enabled'  => 'isModEnabled("accountingexport")',
            'perms'    => '$user->hasRight("accountingexport","read")',
            'target'   => '',
            'user'     => 0,
        );
        $r++;

        $this->menu[$r] = array(
            'fk_menu'  => 'fk_mainmenu=accountancy,fk_leftmenu=accountingexport',
            'type'     => 'left',
            'titre'    => 'Export Excel',
            'mainmenu' => 'accountancy',
            'leftmenu' => 'accountingexport_excel',
            'url'      => '/accountingexport/accountingexport_page.php?type=excel',
            'langs'    => 'accountingexport@accountingexport',
            'position' => 101,
            'enabled'  => 'isModEnabled("accountingexport")',
            'perms'    => '$user->hasRight("accountingexport","export")',
            'target'   => '',
            'user'     => 0,
        );
        $r++;

        $this->menu[$r] = array(
            'fk_menu'  => 'fk_mainmenu=accountancy,fk_leftmenu=accountingexport',
            'type'     => 'left',
            'titre'    => 'Export FEC',
            'mainmenu' => 'accountancy',
            'leftmenu' => 'accountingexport_fec',
            'url'      => '/accountingexport/accountingexport_page.php?type=fec',
            'langs'    => 'accountingexport@accountingexport',
            'position' => 102,
            'enabled'  => 'isModEnabled("accountingexport")',
            'perms'    => '$user->hasRight("accountingexport","export")',
            'target'   => '',
            'user'     => 0,
        );

        $this->const = array();
        $i = 0;
        $this->const[$i++] = array('ACCOUNTINGEXPORT_ACCOUNT_CLIENT',     'chaine', '411000', 'Compte clients',              1, 'current', 1);
        $this->const[$i++] = array('ACCOUNTINGEXPORT_ACCOUNT_FOURNISSEUR','chaine', '401000', 'Compte fournisseurs',          1, 'current', 1);
        $this->const[$i++] = array('ACCOUNTINGEXPORT_ACCOUNT_VENTES',     'chaine', '706000', 'Compte ventes',                1, 'current', 1);
        $this->const[$i++] = array('ACCOUNTINGEXPORT_ACCOUNT_ACHATS',     'chaine', '607000', 'Compte achats',                1, 'current', 1);
        $this->const[$i++] = array('ACCOUNTINGEXPORT_ACCOUNT_TVA20',      'chaine', '445710', 'TVA collectée 20%',            1, 'current', 1);
        $this->const[$i++] = array('ACCOUNTINGEXPORT_ACCOUNT_TVA10',      'chaine', '445711', 'TVA collectée 10%',            1, 'current', 1);
        $this->const[$i++] = array('ACCOUNTINGEXPORT_ACCOUNT_TVA55',      'chaine', '445712', 'TVA collectée 5.5%',           1, 'current', 1);
        $this->const[$i++] = array('ACCOUNTINGEXPORT_ACCOUNT_TVA21',      'chaine', '445713', 'TVA collectée 2.1%',           1, 'current', 1);
        $this->const[$i++] = array('ACCOUNTINGEXPORT_ACCOUNT_TVA_DED20',  'chaine', '445660', 'TVA déductible 20%',           1, 'current', 1);
        $this->const[$i++] = array('ACCOUNTINGEXPORT_ACCOUNT_TVA_DED10',  'chaine', '445661', 'TVA déductible 10%',           1, 'current', 1);
        $this->const[$i++] = array('ACCOUNTINGEXPORT_ACCOUNT_TVA_DED55',  'chaine', '445662', 'TVA déductible 5.5%',          1, 'current', 1);
        $this->const[$i++] = array('ACCOUNTINGEXPORT_ACCOUNT_BANQUE',     'chaine', '512000', 'Compte banque',                1, 'current', 1);
        $this->const[$i++] = array('ACCOUNTINGEXPORT_FEC_SEPARATOR',      'chaine', 'tab',    'Séparateur FEC',               1, 'current', 1);
        $this->const[$i++] = array('ACCOUNTINGEXPORT_JOURNAL_VENTES',     'chaine', 'VTE',    'Code journal ventes',          1, 'current', 1);
        $this->const[$i++] = array('ACCOUNTINGEXPORT_JOURNAL_ACHATS',     'chaine', 'ACH',    'Code journal achats',          1, 'current', 1);
        $this->const[$i++] = array('ACCOUNTINGEXPORT_JOURNAL_BANQUE',     'chaine', 'BAN',    'Code journal banque',          1, 'current', 1);
        $this->const[$i++] = array('ACCOUNTINGEXPORT_JOURNAL_OD',         'chaine', 'OD',     'Code journal OD',              1, 'current', 1);
    }
}

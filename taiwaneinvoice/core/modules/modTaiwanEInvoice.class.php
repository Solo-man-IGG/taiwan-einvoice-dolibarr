<?php
/**
 * [File Path: /taiwaneinvoice/core/modules/modTaiwanEInvoice.class.php]
 * 台灣電子發票模組 (Taiwan E-Invoice Module)
 *
 * @package    TaiwanEInvoice
 * @author     Solo-man (Vincent Tsai)
 * @copyright  Copyright (c) 2026 Solo-man. All rights reserved.
 * @license    GNU General Public License v3.0 (GPL-3.0)
 */

include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

class modTaiwanEInvoice extends DolibarrModules
{
    public function __construct($db)
    {
        $this->db = $db;

        $this->numero           = 500000;
        $this->rights_class     = 'taiwaneinvoice';
        $this->family           = "other";
        $this->module_position  = '90';
        $this->name             = 'TaiwanEInvoice';
        $this->description      = "台灣電子發票管理模組 (MIG 4.1 核心)";
        $this->version          = '1.0';
        $this->picto            = 'bill';
        
        $this->editor_name      = 'Solo-man (Vincent Tsai)';
        $this->editor_url       = 'https://www.igg.tw';

        $this->config_page_url  = array("setup.php@taiwaneinvoice");
        $this->langfiles        = array("taiwaneinvoice@taiwaneinvoice");
        
        $this->depends          = array("modFacture"); 

        // 這裡增加初始化預設值，避免 PHP 8.x count(null) 錯誤
        $this->tabs             = array(); 
        $this->dictionaries     = array();
        $this->boxes            = array();

        $this->module_parts     = array(
            'hooks'    => array(
                'invoicecard',
                'thirdpartycard',
                'doActions',
                'addMoreActionsButtons',
                'formConfirm'
            ),
            'triggers' => 1,
            'substitutions' => 0,
            'css' => array(),
            'js' => array()
        );
        
        $this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);

        $this->menu = array();
        $r = 0;

        $this->menu[$r++] = array(
            'fk_menu'   => '',
            'type'      => 'top',
            'titre'     => '台灣發票',
            'mainmenu'  => 'taiwaneinvoice',
            'url'       => '/taiwaneinvoice/track_list.php',
            'langs'     => 'taiwaneinvoice@taiwaneinvoice',
            'position'  => 1000,
            'enabled'   => '1',
            'perms'     => '1',
        );

        $this->menu[$r++] = array(
            'fk_menu'   => 'fk_mainmenu=taiwaneinvoice',
            'type'      => 'left',
            'titre'     => '電子發票字軌管理',
            'mainmenu'  => 'taiwaneinvoice',
            'leftmenu'  => 'taiwaneinvoice_track',
            'url'       => '/taiwaneinvoice/track_list.php',
            'langs'     => 'taiwaneinvoice@taiwaneinvoice',
            'position'  => 1100,
            'enabled'   => '1',
            'perms'     => '1',
        );

        $this->menu[$r++] = array(
            'fk_menu'   => 'fk_mainmenu=taiwaneinvoice',
            'type'      => 'left',
            'titre'     => '電子發票模組設定',
            'mainmenu'  => 'taiwaneinvoice',
            'leftmenu'  => 'taiwaneinvoice_setup',
            'url'       => '/taiwaneinvoice/setup.php',
            'langs'     => 'taiwaneinvoice@taiwaneinvoice',
            'position'  => 1200,
            'enabled'   => '1',
            'perms'     => '$user->admin',
        );
    }

    public function init($options = '')
    {
        global $conf;

        if (empty($conf->facture->enabled)) {
            require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
            activateModule("modFacture");
        }

        $this->_load_tables('/taiwaneinvoice/sql/');
        
        // 註：這兩行手動 SQL 建議開源前可保留，但若環境穩定可移除
        $sql_clean = "DELETE FROM " . MAIN_DB_PREFIX . "hook WHERE module = 'taiwaneinvoice'";
        $this->db->query($sql_clean);
        
        return $this->_init(array(), $options);
    }

    public function remove($options = '')
    {
        return $this->_remove(array(), $options);
    }
}
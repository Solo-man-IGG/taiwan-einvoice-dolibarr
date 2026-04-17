<?php
/**
 * 璦閣-臺灣電子發票模組 for Dolibarr V2x(符合財政部 MIG 4.1 規範)
 * 版本：V1.0.1
 * 開發公司：璦閣數位科技
 * 開發者：Solo-Man(Vincent Tsai)
 * 版權聲明：GPL-3
 *
 * 檔案功能：模組定義檔案，定義模組資訊、選單、權限
 */

include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

// 台灣電子發票常數定義
if (!defined('TAIWAN_EINVOICE_INV_TYPE_NONE')) {
    define('TAIWAN_EINVOICE_INV_TYPE_NONE', '0');      // 不開發票
    define('TAIWAN_EINVOICE_INV_TYPE_2COPIES', '2');    // 二聯式
    define('TAIWAN_EINVOICE_INV_TYPE_3COPIES', '3');    // 三聯式
    define('TAIWAN_EINVOICE_STATUS_VALID', 1);           // 已開立
    define('TAIWAN_EINVOICE_STATUS_VOID', 9);           // 已作廢
    define('TAIWAN_EINVOICE_CARRIER_MOBILE', '3J0002');  // 手機條碼
    define('TAIWAN_EINVOICE_CARRIER_CERT', 'CQ0001');    // 自然人憑證
    define('TAIWAN_EINVOICE_CARRIER_DONATE', 'LOVEDON'); // 捐贈碼
}

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
        $this->description      = "璦閣-臺灣電子發票模組 for Dolibarr V2x(符合財政部 MIG 4.1 規範)";
        $this->version          = '1.0.1';
        $this->picto            = 'bill';

        $this->editor_name      = '璦閣數位科技 (Solo-Man/Vincent Tsai)';
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
                'doActions',
                'addMoreActionsButtons',
                'formConfirm',
                'formObjectOptions'
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

        // 直接執行 SQL 檔案以建立資料表
        // 嘗試多個可能的路徑
        $possible_paths = array(
            dol_buildpath('/taiwaneinvoice/sql/install.sql', 0),
            DOL_DOCUMENT_ROOT . '/../custom/taiwaneinvoice/sql/install.sql',
            dirname(__FILE__) . '/../../sql/install.sql',
        );

        $sql_file = '';
        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                $sql_file = $path;
                break;
            }
        }

        dol_syslog("TaiwanEInvoice init: SQL file path = " . $sql_file, LOG_DEBUG);
        dol_syslog("TaiwanEInvoice init: MAIN_DB_PREFIX = " . MAIN_DB_PREFIX, LOG_DEBUG);
        dol_syslog("TaiwanEInvoice init: DOL_DOCUMENT_ROOT = " . DOL_DOCUMENT_ROOT, LOG_DEBUG);

        if (file_exists($sql_file)) {
            $sql_content = file_get_contents($sql_file);
            dol_syslog("TaiwanEInvoice init: SQL file size = " . strlen($sql_content) . " bytes", LOG_DEBUG);

            // 將 llx_ 替換為實際的資料表前綴
            $sql_content = str_replace('llx_', MAIN_DB_PREFIX, $sql_content);

            // 移除單行註解（以 -- 開頭的行）
            $sql_content = preg_replace('/--.*$/m', '', $sql_content);
            // 移除空行
            $sql_content = preg_replace('/^\s*$/m', '', $sql_content);

            $sql_statements = explode(';', $sql_content);

            $success_count = 0;
            $error_count = 0;

            foreach ($sql_statements as $sql) {
                $sql = trim($sql);
                if (!empty($sql)) {
                    dol_syslog("TaiwanEInvoice init: Executing SQL: " . substr($sql, 0, 100) . "...", LOG_DEBUG);
                    $res = $this->db->query($sql);
                    if ($res) {
                        $success_count++;
                        dol_syslog("TaiwanEInvoice init: SQL executed successfully", LOG_DEBUG);
                    } else {
                        $error_count++;
                        dol_syslog("TaiwanEInvoice init: SQL error - " . $this->db->lasterror(), LOG_ERR);
                    }
                }
            }
            dol_syslog("TaiwanEInvoice init: SQL execution completed - Success: " . $success_count . ", Errors: " . $error_count, LOG_INFO);
        } else {
            dol_syslog("TaiwanEInvoice init: SQL file not found. Tried paths: " . implode(', ', $possible_paths), LOG_ERR);
        }

        // 移除對 llx_hook 表的清理（該表不存在）
        // Hook 記錄會由 Dolibarr 的 _init 方法自動處理

        return $this->_init(array(), $options);
    }

    public function remove($options = '')
    {
        return $this->_remove(array(), $options);
    }
}
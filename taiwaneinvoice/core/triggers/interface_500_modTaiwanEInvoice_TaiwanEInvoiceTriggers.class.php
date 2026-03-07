<?php
/**
 * [File Path: /taiwaneinvoice/core/triggers/interface_500_modTaiwanEInvoice_TaiwanEInvoiceTriggers.class.php]
 * 台灣電子發票模組 - 觸發器核心 (V1.0 開源版)
 *
 * @package    TaiwanEInvoice
 * @author     Solo-man (Vincent Tsai)
 * @copyright  Copyright (c) 2026 Solo-man. All rights reserved.
 * @license    GNU General Public License v3.0 (GPL-3.0)
 */

require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';

class InterfaceTaiwanEInvoiceTriggers extends DolibarrTriggers
{
    public $db;

    public function __construct($db)
    {
        $this->db = $db;
        $this->name = "TaiwanEInvoiceTriggers";
        $this->family = "TaiwanEInvoice";
        $this->description = "台灣電子發票觸發器 - 負責發票驗證後的自動配號與作廢同步";
        $this->version = '1.0';
    }

    /**
     * 執行觸發器
     */
    public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
    {
        // 僅處理發票模組物件
        if ($action !== 'BILL_VALIDATE' && $action !== 'BILL_CANCEL') return 0;
        
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        if (!($object instanceof Facture)) return 0;

        // 1. 處理發票驗證 (配號邏輯)
        if ($action === 'BILL_VALIDATE') {
            return $this->handleIssuance($object, $conf);
        }

        // 2. 處理發票作廢 (狀態同步)
        if ($action === 'BILL_CANCEL') {
            return $this->handleVoid($object);
        }

        return 0;
    }

    /**
     * 自動配號與資料初始化
     */
    private function handleIssuance($object, $conf)
    {
        // A. 檢查是否已存在發票數據
        $sql = "SELECT rowid, einvoice_no FROM " . MAIN_DB_PREFIX . "taiwaneinvoice_data WHERE fk_object = " . (int)$object->id;
        $res = $this->db->query($sql);
        $exists = ($res && $this->db->num_rows($res) > 0);
        $data = $this->db->fetch_object($res);

        if ($exists && !empty($data->einvoice_no)) return 0; // 已配號，跳過

        // B. 自動取號邏輯 (從字軌表取出最新號碼)
        // 這裡會根據您在 track_list.php 設定的優先權 (sortorder) 取號
        $this->db->begin();

        $sql_track = "SELECT rowid, track_code, current_number, end_number FROM " . MAIN_DB_PREFIX . "taiwaneinvoice_track ";
        $sql_track .= " WHERE active = 1 AND current_number <= end_number ";
        $sql_track .= " ORDER BY sortorder DESC, rowid ASC LIMIT 1 FOR UPDATE";
        
        $res_track = $this->db->query($sql_track);
        if ($res_track && $this->db->num_rows($res_track) > 0) {
            $track = $this->db->fetch_object($res_track);
            $new_no = str_pad($track->current_number, 8, '0', STR_PAD_LEFT);
            $next_no = $track->current_number + 1;

            // 更新字軌表
            $this->db->query("UPDATE " . MAIN_DB_PREFIX . "taiwaneinvoice_track SET current_number = $next_no WHERE rowid = " . $track->rowid);

            // 寫入電子發票擴充表
            $random_no = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
            if ($exists) {
                $sql_save = "UPDATE " . MAIN_DB_PREFIX . "taiwaneinvoice_data SET ";
                $sql_save .= " einvoice_no = '$new_no', track_code = '{$track->track_code}', random_no = '$random_no', status = 1 ";
                $sql_save .= " WHERE fk_object = " . (int)$object->id;
            } else {
                $sql_save = "INSERT INTO " . MAIN_DB_PREFIX . "taiwaneinvoice_data (fk_object, einvoice_no, track_code, random_no, status, date_creation) ";
                $sql_save .= " VALUES (" . (int)$object->id . ", '$new_no', '{$track->track_code}', '$random_no', 1, '" . $this->db->idate(dol_now()) . "')";
            }

            if ($this->db->query($sql_save)) {
                $this->db->commit();
                return 1;
            }
        }

        $this->db->rollback();
        return 0;
    }

    /**
     * 同步作廢狀態
     */
    private function handleVoid($object)
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "taiwaneinvoice_data SET status = 9, date_void = '" . $this->db->idate(dol_now()) . "' ";
        $sql .= " WHERE fk_object = " . (int)$object->id;
        return $this->db->query($sql) ? 1 : -1;
    }
}
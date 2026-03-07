<?php
/**
 * [File Path: /taiwaneinvoice/manage_invoice.php]
 * 台灣電子發票模組 (Taiwan E-Invoice Module) - 檔案管理與 XML 匯出 (MIG 4.1)
 *
 * @package    TaiwanEInvoice
 * @author     Solo-man (Vincent Tsai)
 * @copyright  Copyright (c) 2026 Solo-man. All rights reserved.
 * @license    GNU General Public License v3.0 (GPL-3.0)
 */

// 1. 路徑自動適應與核心載入
$res = 0;
if (file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
} elseif (file_exists("../main.inc.php")) {
    $res = @include "../main.inc.php";
}

if (!$res) die("Error: Dolibarr main.inc.php not found.");

// 載入 XML 產製函式庫
require_once dol_buildpath('/taiwaneinvoice/lib/taiwaneinvoice_xml.lib.php', 0);

$id         = GETPOST('id', 'int');
$action     = GETPOST('action', 'alpha');
$date_start = GETPOST('date_start', 'alpha');
$date_end   = GETPOST('date_end', 'alpha');

// 2. 權限檢查 (讀取發票權限)
if (!$user->admin && !$user->rights->facture->lire) {
    accessforbidden();
}

/*
 * CASE 1: 單張 XML 下載 (C0401 / D0401)
 */
if ($id > 0 && $action != 'batch_export') {
    $data = get_taiwan_invoice_xml_content($db, $id);
    
    if ($data && !empty($data['content'])) {
        // 清除緩衝區以防 XML 格式損壞
        if (ob_get_length()) ob_end_clean();
        
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $data['filename'] . '"');
        echo $data['content'];
        exit;
    } else {
        setEventMessages("「攔截預檢」：無法產製 XML 內容。原因：單據尚未配號或字軌已過期。", null, 'errors');
        if (!empty($_SERVER['HTTP_REFERER'])) {
            header("Location: " . $_SERVER['HTTP_REFERER']);
        } else {
            header("Location: " . dol_buildpath('/compta/facture/card.php?id=' . $id, 1));
        }
        exit;
    }
} 

/*
 * CASE 2: 批次匯出 (依日期區間產製 ZIP)
 */
elseif ($date_start && $date_end) {
    // 轉換日期格式以符合資料庫
    $sql_start = $db->idate(dol_stringtotime($date_start . ' 00:00:00'));
    $sql_end   = $db->idate(dol_stringtotime($date_end . ' 23:59:59'));

    $sql = "SELECT f.rowid, d.einvoice_no, d.allowance_no ";
    $sql.= " FROM " . MAIN_DB_PREFIX . "facture as f";
    $sql.= " JOIN " . MAIN_DB_PREFIX . "taiwaneinvoice_data as d ON d.fk_object = f.rowid";
    $sql.= " WHERE f.datef >= '" . $sql_start . "' AND f.datef <= '" . $sql_end . "'";
    $sql.= " AND (d.einvoice_no IS NOT NULL OR d.allowance_no IS NOT NULL)";

    $resql = $db->query($sql);
    
    if ($resql && $db->num_rows($resql) > 0) {
        if (!class_exists('ZipArchive')) {
            setEventMessages("伺服器環境缺少 ZipArchive 組件，無法批次匯出。", null, 'errors');
            header("Location: " . dol_buildpath('/taiwaneinvoice/setup.php', 1));
            exit;
        }

        $zip = new ZipArchive();
        $zipname = "EInvoice_MIG41_Export_" . date('Ymd_His') . ".zip";
        
        // 確保臨時存檔目錄存在
        $temp_dir = DOL_DATA_ROOT . "/taiwaneinvoice/temp";
        if (!file_exists($temp_dir)) {
            dol_mkdir($temp_dir);
        }
        $zippath = $temp_dir . "/" . $zipname;

        if ($zip->open($zippath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            $count = 0;
            while ($row = $db->fetch_object($resql)) {
                $xml = get_taiwan_invoice_xml_content($db, $row->rowid);
                if ($xml && !empty($xml['content'])) {
                    $zip->addFromString($xml['filename'], $xml['content']);
                    $count++;
                }
            }
            $zip->close();

            if ($count > 0) {
                if (ob_get_length()) ob_end_clean();
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $zipname . '"');
                header('Content-Length: ' . filesize($zippath));
                header('Pragma: no-cache');
                header('Expires: 0');
                readfile($zippath);
                @unlink($zippath); // 傳送完畢後刪除暫存檔
                exit;
            }
        }
        setEventMessages("ZIP 壓縮檔建立失敗或無效檔案內容", null, 'errors');
    } else {
        setEventMessages("此期間 (" . $date_start . " ~ " . $date_end . ") 內沒有已配號的發票或折讓單。", null, 'warnings');
    }
    
    // 返回設定頁面
    header("Location: " . dol_buildpath('/taiwaneinvoice/setup.php', 1));
    exit;
}
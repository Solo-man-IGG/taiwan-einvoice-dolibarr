<?php
/**
 * 璦閣-臺灣電子發票模組 for Dolibarr V2x(符合財政部 MIG 4.1 規範)
 * 版本：V1.0.1
 * 開發公司：璦閣數位科技
 * 開發者：Solo-Man(Vincent Tsai)
 * 版權聲明：GPL-3
 *
 * 檔案功能：發票 XML 匯出頁面，支援單張 XML 下載與批次 ZIP 匯出
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
 * CASE 1: 單張 XML 下載 (C0401 / D0401 / C0501)
 */
if ($id > 0 && $action != 'batch_export') {
    require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
    $object = new Facture($db);
    if ($object->fetch($id) <= 0) accessforbidden("Invoice not found");

    // 檢查發票狀態
    $sql = "SELECT status FROM " . MAIN_DB_PREFIX . "taiwaneinvoice_data WHERE fk_object = " . (int)$id;
    $resql = $db->query($sql);
    $einv_status = ($resql && ($row = $db->fetch_object($resql))) ? $row->status : 1;

    // 判斷 XML 類型
    $xml_type = GETPOST('xml_type', 'alpha'); // C0401, D0401, C0501

    // 若為作廢狀態 (status = 9)，強制匯出 C0501
    if ($einv_status == 9) {
        $xml_type = 'C0501';
    }

    // 若未指定 XML 類型，根據發票類型判斷
    if (empty($xml_type)) {
        if ($object->type == 2) {
            $xml_type = 'D0401'; // 折讓單
        } else {
            $xml_type = 'C0401'; // 發票
        }
    }

    // 根據 XML 類型調用相應函數
    switch ($xml_type) {
        case 'C0501':
            $data = get_taiwan_cancel_invoice_xml_content($db, $id);
            $error_msg = "「攔截預檢」：無法產製作廢 XML 內容。原因：發票尚未配號。";
            break;
        case 'D0401':
            $data = get_taiwan_allowance_xml_content($db, $id);
            $error_msg = "「攔截預檢」：無法產製折讓單 XML 內容。原因：折讓單尚未配號。";
            break;
        case 'C0401':
        default:
            $data = get_taiwan_invoice_xml_content($db, $id);
            $error_msg = "「攔截預檢」：無法產製發票 XML 內容。原因：單據尚未配號或字軌已過期。";
            break;
    }

    if ($data && !empty($data['content'])) {
        // 清除緩衝區以防 XML 格式損壞
        if (ob_get_length()) ob_end_clean();

        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $data['filename'] . '"');
        echo $data['content'];
        exit;
    } else {
        setEventMessages($error_msg, null, 'errors');
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

    $sql = "SELECT f.rowid, f.type, d.einvoice_no, d.allowance_no, d.status ";
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
                $xml = null;

                // 根據發票類型和狀態決定 XML 類型
                if ($row->type == 2 || !empty($row->allowance_no)) {
                    // 折讓單
                    $xml = get_taiwan_allowance_xml_content($db, $row->rowid);
                } elseif ((int)$row->status === 9) {
                    // 作廢發票
                    $xml = get_taiwan_cancel_invoice_xml_content($db, $row->rowid);
                } else {
                    // 正常發票
                    $xml = get_taiwan_invoice_xml_content($db, $row->rowid);
                }

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
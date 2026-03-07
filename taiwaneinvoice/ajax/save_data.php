<?php
/**
 * [File Path: /taiwaneinvoice/ajax/save_data.php]
 * 台灣電子發票模組 (Taiwan E-Invoice Module) - AJAX 資料儲存與作廢同步處理
 *
 * @package    TaiwanEInvoice
 * @author     Solo-man (Vincent Tsai)
 * @copyright  Copyright (c) 2026 Solo-man. All rights reserved.
 * @license    GNU General Public License v3.0 (GPL-3.0)
 */

// 關閉不必要的檢查以加速 AJAX 回應
define('NOCSRFCHECK', 1);
define('NOTOKENRENEWAL', 1);
define('NOREQUIREMENU', 1); 
define('NOREQUIREHTML', 1);

$res = 0;
if (!$res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php"))    $res = @include "../../../main.inc.php";

if (!$res) {
    header('Content-Type: application/json');
    echo json_encode(array('success' => false, 'error' => 'Dolibarr Core Not Found'));
    exit;
}

header('Content-Type: application/json');

// 安全檢查：確保使用者已登入
if (empty($user->id)) {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (empty($user->id)) {
        echo json_encode(array('success' => false, 'error' => 'Session Expired'));
        exit;
    }
}

$target_type = GETPOST('target_type', 'alpha'); 
$target_id   = GETPOST('target_id', 'int');
$field       = GETPOST('field', 'alpha');
$value       = trim(GETPOST('value', 'alpha'));

if (!$target_id) {
    echo json_encode(array('success' => false, 'error' => 'Missing target_id'));
    exit;
}

/**
 * 處理發票作廢邏輯 (void_logic)
 * 同步更新台灣電子發票資料表與 Dolibarr 原生發票狀態
 */
if ($field == 'void_logic') {
    $db->begin();
    
    // 更新台灣發票擴充資料表：狀態改為 9 (作廢), 紀錄理由與時間
    $sql1 = "UPDATE " . MAIN_DB_PREFIX . "taiwaneinvoice_data SET status = 9, void_reason = '" . $db->escape($value) . "', date_void = '" . $db->idate(time()) . "' WHERE fk_object = " . (int)$target_id;
    
    // 同步更新 Dolibarr 原生發票表 (facture)：狀態改為 3 (Abandoned/Void)
    $sql2 = "UPDATE " . MAIN_DB_PREFIX . "facture SET fk_statut = 3 WHERE rowid = " . (int)$target_id;
    
    if ($db->query($sql1) && $db->query($sql2)) {
        $db->commit();
        echo json_encode(array('success' => true, 'message' => 'Invoice Voided'));
    } else {
        $db->rollback();
        echo json_encode(array('success' => false, 'error' => $db->lasterror()));
    }
    exit;
}

/**
 * 處理一般欄位自動儲存 (inv_type, carrier_type, carrier_id 等)
 */
if ($target_type == 'invoice') {
    $table = MAIN_DB_PREFIX . "taiwaneinvoice_data";
    $fk_column = "fk_object";
} else {
    $table = MAIN_DB_PREFIX . "taiwaneinvoice_customer_pref";
    $fk_column = "fk_soc";
}

// 檢查資料是否存在以決定執行 INSERT 或 UPDATE
$sql_check = "SELECT rowid FROM " . $table . " WHERE " . $fk_column . " = " . (int)$target_id;
$res_check = $db->query($sql_check);
$exists = ($res_check && $db->num_rows($res_check) > 0);

if ($exists) {
    $sql = "UPDATE " . $table . " SET " . $field . " = '" . $db->escape($value) . "' WHERE " . $fk_column . " = " . (int)$target_id;
} else {
    $sql = "INSERT INTO " . $table . " (" . $fk_column . ", " . $field . ", entity) VALUES (" . (int)$target_id . ", '" . $db->escape($value) . "', " . $conf->entity . ")";
}

if ($db->query($sql)) {
    echo json_encode(array('success' => true));
} else {
    echo json_encode(array('success' => false, 'error' => $db->lasterror()));
}
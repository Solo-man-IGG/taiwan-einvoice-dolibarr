<?php
/**
 * 璦閣-臺灣電子發票模組 for Dolibarr V2x(符合財政部 MIG 4.1 規範)
 * 版本：V1.0.1
 * 開發公司：璦閣數位科技
 * 開發者：Solo-Man(Vincent Tsai)
 * 版權聲明：GPL-3
 *
 * 檔案功能：AJAX 資料儲存處理，處理發票欄位儲存與作廢邏輯
 */

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

// CSRF token 驗證
$token = GETPOST('token', 'alpha');
$token_ok = (function_exists('checkToken') ? checkToken() : ($token === $_SESSION['newtoken']));
if (!$token_ok) {
    echo json_encode(array('success' => false, 'error' => 'Invalid Security Token'));
    exit;
}

$target_type = GETPOST('target_type', 'alpha'); 
$target_id   = GETPOST('target_id', 'int');
$field       = GETPOST('field', 'alpha');
$value       = trim(GETPOST('value', 'alpha'));

if (!$target_id) {
    echo json_encode(array('success' => false, 'error' => 'Missing target_id'));
    exit;
}

if ($field == 'void_logic') {
    $db->begin();
    $sql1 = "UPDATE " . MAIN_DB_PREFIX . "taiwaneinvoice_data SET status = 9, void_reason = '" . $db->escape($value) . "', date_void = '" . $db->idate(time()) . "' WHERE fk_object = " . (int)$target_id;
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

if ($target_type == 'invoice') {
    $table = MAIN_DB_PREFIX . "taiwaneinvoice_data";
    $fk_column = "fk_object";
} else {
    $table = MAIN_DB_PREFIX . "taiwaneinvoice_customer_pref";
    $fk_column = "fk_soc";
}

$sql_check = "SELECT rowid FROM " . $table . " WHERE " . $fk_column . " = " . (int)$target_id;
$res_check = $db->query($sql_check);
$exists = ($res_check && $db->num_rows($res_check) > 0);

if ($exists) {
    $sql = "UPDATE " . $table . " SET " . $field . " = '" . $db->escape($value) . "' WHERE " . $fk_column . " = " . (int)$target_id;
} else {
    $sql = "INSERT INTO " . $table . " (" . $fk_column . ", " . $field . ") VALUES (" . (int)$target_id . ", '" . $db->escape($value) . "')";
}

if ($db->query($sql)) {
    echo json_encode(array('success' => true));
} else {
    echo json_encode(array('success' => false, 'error' => $db->lasterror()));
}
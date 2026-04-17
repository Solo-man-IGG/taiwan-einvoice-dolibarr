<?php
/**
 * 璦閣-臺灣電子發票模組 for Dolibarr V2x(符合財政部 MIG 4.1 規範)
 * 版本：V1.0.1
 * 開發公司：璦閣數位科技
 * 開發者：Solo-Man(Vincent Tsai)
 * 版權聲明：GPL-3
 *
 * 檔案功能：字軌卡片頁面，新增電子發票字軌
 */

// 路徑隔離：相容於 /custom/ 或根目錄
if (file_exists("../../main.inc.php")) {
    require "../../main.inc.php";
} else {
    require "../main.inc.php";
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';

// 權限檢查：需要管理員或發票讀取權限
if (!$user->admin && !$user->rights->facture->lire) accessforbidden();

$langs->load("taiwaneinvoice@taiwaneinvoice");

$action       = GETPOST('action', 'aZ09');
$year         = GETPOST('year', 'int');
$period       = GETPOST('period', 'alpha');
$track_code   = strtoupper(trim(GETPOST('track_code', 'alpha')));
$start_number = GETPOST('start_number', 'int');
$quantity     = GETPOST('quantity', 'int');
$sortorder    = GETPOST('sortorder', 'int');

/*
 * Actions 處理邏輯：處理新增字軌 (Add)
 */
if ($action == 'add' && !empty($track_code)) {
    $error = 0;
    $token_ok = (function_exists('checkToken') ? checkToken() : (GETPOST('token', 'alpha') === $_SESSION['newtoken']));

    if (!$token_ok) { 
        setEventMessages("「攔截預檢」：安全權杖驗證失敗，請重新整理頁面再試。", null, 'errors'); 
        $error++; 
    }
    
    // 嚴格格式檢查
    if (strlen($track_code) != 2 || !preg_match('/^[A-Z]{2}$/', $track_code)) { 
        setEventMessages("「攔截預檢」：字軌格式錯誤，必須為 2 碼大寫英文字母 (例如: AB)", null, 'errors'); 
        $error++; 
    }
    
    if ($quantity <= 0) { 
        setEventMessages("「攔截預檢」：數量 (張數) 必須為正整數", null, 'errors'); 
        $error++; 
    }

    if (!$error) {
        // 計算結束號碼 (邏輯：起始 + 數量 - 1)
        $end_number = $start_number + $quantity - 1;

        $db->begin();
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "taiwaneinvoice_track (";
        $sql .= " year, period, track_code, start_number, end_number, current_number, active, sortorder, datec";
        $sql .= ") VALUES (";
        $sql .= (int) $year . ", ";
        $sql .= "'" . $db->escape($period) . "', ";
        $sql .= "'" . $db->escape($track_code) . "', ";
        $sql .= (int) $start_number . ", ";
        $sql .= (int) $end_number . ", ";
        $sql .= (int) $start_number . ", "; // 新增時，目前號碼 = 起始號碼
        $sql .= "1, "; // 預設啟用
        $sql .= (int) $sortorder . ", ";
        $sql .= "'" . $db->idate(dol_now()) . "')";

        if ($db->query($sql)) {
            $db->commit();
            setEventMessages("字軌新增成功：[" . $track_code . "] 範圍 " . str_pad($start_number, 8, '0', STR_PAD_LEFT) . " 至 " . str_pad($end_number, 8, '0', STR_PAD_LEFT), null, 'mesgs');
            header("Location: track_list.php");
            exit;
        } else {
            $db->rollback();
            setEventMessages("資料庫寫入失敗: " . $db->lasterror(), null, 'errors');
        }
    }
}

/*
 * View 渲染層
 */
llxHeader('', '新增電子發票字軌');

// 沙盒容器
print '<div id="taiwan-einv-logic-wrapper">';

print load_fiche_titre("新增電子發票字軌", '', 'title_setup');

// JS 即時預算邏輯
print '<script type="text/javascript">
    function updateEndNumber() {
        var startInput = document.getElementsByName("start_number")[0];
        var qtyInput = document.getElementsByName("quantity")[0];
        var preview = document.getElementById("end_number_preview");
        
        var start = parseInt(startInput.value) || 0;
        var qty = parseInt(qtyInput.value) || 0;
        var end = 0;
        
        if (qty > 0) {
            end = start + qty - 1;
            preview.innerHTML = " &rarr; 結束號碼預覽: <strong>" + String(end).padStart(8, "0") + "</strong>";
            preview.style.color = "#0055aa";
        } else {
            preview.innerHTML = " (請輸入有效張數)";
            preview.style.color = "#999";
        }
    }
</script>';

print '<form action="' . $_SERVER["PHP_SELF"] . '" method="POST">';
$newToken = function_exists('newToken') ? newToken() : $_SESSION['newtoken'];
print '<input type="hidden" name="token" value="' . $newToken . '">';
print '<input type="hidden" name="action" value="add">';

print '<div class="fichecenter">';
print '<table class="border centpercent">';
print '<tbody>';

// 年度 (預設當前民國年)
print '<tr><td class="titlefield fieldrequired">年度 (民國)</td><td>';
print '<input type="number" name="year" value="' . (date('Y') - 1911) . '" class="flat" style="width: 80px;"> 年';
print '</td></tr>';

// 期別 (自動預選目前月份所屬期別)
print '<tr><td class="fieldrequired">期別</td><td><select name="period" class="flat">';
$periods = array(
    '02' => '01-02 月', '04' => '03-04 月', '06' => '05-06 月',
    '08' => '07-08 月', '10' => '09-10 月', '12' => '11-12 月'
);
$current_m = (int)date('m');
foreach ($periods as $val => $label) {
    $selected = ($val == (ceil($current_m / 2) * 2)) ? 'selected' : '';
    print '<option value="' . $val . '" ' . $selected . '>' . $label . '</option>';
}
print '</select></td></tr>';

// 優先權
print '<tr><td>優先權 (Sort Order)</td><td>';
print '<input type="number" name="sortorder" value="0" class="flat" style="width: 80px;">';
print '<span class="opacitymedium"> (數字越大，自動配號時越優先使用)</span>';
print '</td></tr>';

// 字軌
print '<tr><td class="fieldrequired">字軌 (2碼)</td><td>';
print '<input type="text" name="track_code" maxlength="2" class="flat" style="width: 80px; text-transform: uppercase;" required placeholder="如: AB">';
print '</td></tr>';

// 開始號碼與數量
print '<tr><td class="fieldrequired">開始號碼</td><td>';
print '<input type="number" name="start_number" value="1" class="flat" required onchange="updateEndNumber()" onkeyup="updateEndNumber()">';
print '</td></tr>';

print '<tr><td class="fieldrequired">數量 (張數)</td><td>';
print '<input type="number" name="quantity" value="50" class="flat" required onchange="updateEndNumber()" onkeyup="updateEndNumber()">';
print '<span id="end_number_preview" style="margin-left: 10px; color: #999;"> (結束號碼預覽)</span>';
print '</td></tr>';

print '</tbody></table></div>';

print '<div class="center">';
print '<input type="submit" class="button button-save" value="確認儲存字軌">';
print ' &nbsp; <input type="button" class="button button-cancel" value="取消返回" onclick="location.href=\'track_list.php\'">';
print '</div>';

print '</form>';
print '</div>'; // End wrapper

llxFooter();
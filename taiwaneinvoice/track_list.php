<?php
/**
 * [File Path: /taiwaneinvoice/track_list.php]
 * 台灣電子發票模組 (Taiwan E-Invoice Module) - 字軌管理列表
 *
 * @package    TaiwanEInvoice
 * @author     Solo-man (Vincent Tsai)
 * @copyright  Copyright (c) 2026 Solo-man. All rights reserved.
 * @license    GNU General Public License v3.0 (GPL-3.0)
 */

// 修正路徑以符合您的目錄結構 (如果是放在 custom 資料夾下)
if (file_exists("../../main.inc.php")) {
    require "../../main.inc.php";
} else {
    require "../main.inc.php";
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

// 權限檢查：僅限管理員
if (!$user->admin) accessforbidden();

$langs->load("taiwaneinvoice@taiwaneinvoice");

$action = GETPOST('action', 'aZ09');
$id     = GETPOST('id', 'int');
$token  = GETPOST('token', 'alpha');

/*
 * Actions 處理邏輯
 */
if (function_exists('checkToken') ? checkToken() : ($token === $_SESSION['newtoken'])) {
    if ($action == 'delete' && $id > 0) {
        $sql_check = "SELECT current_number, start_number FROM " . MAIN_DB_PREFIX . "taiwaneinvoice_track WHERE rowid = " . (int)$id;
        $res_check = $db->query($sql_check);
        $obj_check = ($res_check) ? $db->fetch_object($res_check) : null;

        if ($obj_check && $obj_check->current_number == $obj_check->start_number) {
            $sql = "DELETE FROM " . MAIN_DB_PREFIX . "taiwaneinvoice_track WHERE rowid = " . (int)$id;
            if ($db->query($sql)) setEventMessages("字軌已成功刪除", null, 'mesgs');
        } else {
            setEventMessages("「攔截預檢」：該字軌已有發票記錄，禁止刪除！", null, 'errors');
        }
    }
    
    if ($action == 'return' && $id > 0) {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "taiwaneinvoice_track SET active = 0 WHERE rowid = " . (int)$id;
        if ($db->query($sql)) setEventMessages("字軌已標記為「已繳回/停用」", null, 'mesgs');
    }
}

/*
 * View 渲染層
 */
llxHeader('', '電子發票字軌管理');

print '<div id="taiwan-einv-logic-wrapper">';
print load_fiche_titre("電子發票字軌管理", '', 'title_setup');

$newToken = function_exists('newToken') ? newToken() : $_SESSION['newtoken'];

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>年度 (民國)</td>';
print '<td>期別</td>';
print '<td class="center">優先權</td>';
print '<td>字軌</td>';
print '<td>號碼範圍 (起 - 訖)</td>';
print '<td>目前進度</td>';
print '<td class="center">狀態</td>';
print '<td class="right">操作</td>';
print '</tr>';

$sql = "SELECT rowid, year, period, track_code, start_number, end_number, current_number, active, sortorder";
$sql.= " FROM " . MAIN_DB_PREFIX . "taiwaneinvoice_track";
$sql.= " ORDER BY year DESC, period DESC, sortorder DESC, rowid ASC";

$resql = $db->query($sql);
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $is_used = ($obj->current_number > $obj->start_number);
        print '<tr class="oddeven">';
        print '<td>' . $obj->year . ' 年</td>';
        print '<td>' . str_pad($obj->period, 2, '0', STR_PAD_LEFT) . ' 月</td>';
        print '<td class="center">' . $obj->sortorder . '</td>';
        print '<td><strong style="color: #25476a;">' . $obj->track_code . '</strong></td>';
        print '<td>' . str_pad($obj->start_number, 8, '0', STR_PAD_LEFT) . ' - ' . str_pad($obj->end_number, 8, '0', STR_PAD_LEFT) . '</td>';
        
        $total_qty = ($obj->end_number - $obj->start_number) + 1;
        $used_qty  = ($obj->current_number - $obj->start_number);
        print '<td>' . str_pad($obj->current_number, 8, '0', STR_PAD_LEFT) . ' <small class="opacitymedium">(' . $used_qty . '/' . $total_qty . ')</small></td>';
        
        print '<td class="center">';
        print ($obj->active) ? '<span class="badge badge-status4">使用中</span>' : '<span class="badge badge-status6">已停用/繳回</span>';
        print '</td>';
        
        print '<td class="right">';
        if (!$is_used) {
            print '<a href="' . $_SERVER["PHP_SELF"] . '?action=delete&id=' . $obj->rowid . '&token=' . $newToken . '" onclick="return confirm(\'確定要徹底刪除此字軌嗎？\');">' . img_picto('刪除', 'delete') . '</a>';
        } else {
            print img_picto('已有數據，鎖定刪除', 'lock');
            if ($obj->active) {
                print ' <a href="' . $_SERVER["PHP_SELF"] . '?action=return&id=' . $obj->rowid . '&token=' . $newToken . '" title="繳回剩餘號碼" onclick="return confirm(\'確定要將此字軌標記為繳回嗎？\');">' . img_picto('繳回', 'reassign') . '</a>';
            }
        }
        print '</td></tr>';
    }
    $db->free($resql);
}
print '</table>';
print '</div>';

print '<div class="tabsAction">';
print '<a class="butAction" href="track_card.php?action=create">＋ 新增配號字軌</a>';
print '</div>';

print '</div>';

llxFooter();
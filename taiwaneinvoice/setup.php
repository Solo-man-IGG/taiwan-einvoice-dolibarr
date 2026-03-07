<?php
/**
 * [File Path: /taiwaneinvoice/setup.php]
 * 台灣電子發票模組 - 設定頁面 (Setup Page)
 *
 * @package    TaiwanEInvoice
 * @author     Solo-man (Vincent Tsai)
 * @copyright  Copyright (c) 2026 Solo-man. All rights reserved.
 * @license    GNU General Public License v3.0 (GPL-3.0)
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res) die("Error: Include main.inc.php failed.");

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';

if (!$user->admin && !$user->rights->facture->lire) {
    accessforbidden();
}

$langs->load("taiwaneinvoice@taiwaneinvoice");
$langs->load("admin");

$action = GETPOST('action', 'aZ09');

if ($action == 'update') {
    $token_ok = (function_exists('checkToken') ? checkToken() : (GETPOST('token', 'alpha') === $_SESSION['newtoken']));
    
    if ($token_ok) {
        $db->begin();
        
        $res1 = dolibarr_set_const($db, "TAIWAN_EINVOICE_PRINT_FORMAT", GETPOST('TAIWAN_EINVOICE_PRINT_FORMAT', 'alpha'), 'chaine', 0, '', $conf->entity);
        $res2 = dolibarr_set_const($db, "TAIWAN_EINVOICE_ALWAYS_PRINT_DETAILS", GETPOST('TAIWAN_EINVOICE_ALWAYS_PRINT_DETAILS', 'int'), 'chaine', 0, '', $conf->entity);
        $res3 = dolibarr_set_const($db, "TAIWAN_EINVOICE_HIDE_BARCODE_ON_REPRINT", GETPOST('TAIWAN_EINVOICE_HIDE_BARCODE_ON_REPRINT', 'int'), 'chaine', 0, '', $conf->entity);
        
        if ($res1 >= 0 && $res2 >= 0 && $res3 >= 0) {
            $db->commit();
            setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
        } else {
            $db->rollback();
            setEventMessages($langs->trans("ErrorFailedToSaveConfig"), null, 'errors');
        }
    }
}

llxHeader('', "台灣電子發票模組設定");

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?mainmenu=home">' . $langs->trans("BackToModuleList") . '</a>';
print load_fiche_titre("台灣電子發票模組設定", $linkback, 'title_setup');

print '<div id="taiwan-einv-logic-wrapper">';

print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . (function_exists('newToken') ? newToken() : $_SESSION['newtoken']) . '">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>' . $langs->trans("設定項目") . '</td><td>' . $langs->trans("內容") . '</td></tr>';

$current_format = $conf->global->TAIWAN_EINVOICE_PRINT_FORMAT ?? '57';
print '<tr class="oddeven"><td>預設列印格式</td><td><select name="TAIWAN_EINVOICE_PRINT_FORMAT" class="flat">';
print '<option value="57" ' . ($current_format == '57' ? 'selected' : '') . '>熱感應紙 57mm (證明聯 + 明細)</option>';
print '<option value="A4" ' . ($current_format == 'A4' ? 'selected' : '') . '>A4 格式 (B2B 常用)</option>';
print '</select></td></tr>';

print '<tr class="oddeven"><td>一律列印銷貨明細</td><td>';
print $form->selectyesno("TAIWAN_EINVOICE_ALWAYS_PRINT_DETAILS", $conf->global->TAIWAN_EINVOICE_ALWAYS_PRINT_DETAILS ?? 0, 1);
print '<div class="opacitymedium"><small>(若未勾選，僅有統編之 B2B 發票會自動列印明細)</small></div>';
print '</td></tr>';

print '<tr class="oddeven"><td>補印證明聯時「隱藏」條碼與 QR Code</td><td>';
print $form->selectyesno("TAIWAN_EINVOICE_HIDE_BARCODE_ON_REPRINT", $conf->global->TAIWAN_EINVOICE_HIDE_BARCODE_ON_REPRINT ?? 1, 1);
print '<div class="opacitymedium"><small>(建議開啟，以避免補印件被重複掃描登錄)</small></div>';
print '</td></tr>';

print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="' . $langs->trans("Save") . '"></div>';
print '</form>';

print '<br>';

print load_fiche_titre("電子發票批次匯出 (MIG 4.1 規範)", '', 'title_setup');

print '<div class="div-table-responsive-no-min">';
print '<form action="' . dol_buildpath('/taiwaneinvoice/manage_invoice.php', 1) . '" method="POST">';
print '<input type="hidden" name="token" value="' . (function_exists('newToken') ? newToken() : $_SESSION['newtoken']) . '">';
print '<input type="hidden" name="action" value="batch_export">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>工具功能</td><td>篩選條件 (發票日期)</td><td class="right">動作</td></tr>';

print '<tr class="oddeven"><td>綜合打包下載 XML (ZIP)</td>';
print '<td>';
print '從 <input type="date" name="date_start" class="flat" value="' . date('Y-m-01') . '"> ';
print '至 <input type="date" name="date_end" class="flat" value="' . date('Y-m-d') . '">';
print '<div class="opacitymedium"><small>包含該區間內所有 C0401 (發票) 與 D0401 (折讓)</small></div>';
print '</td>';
print '<td class="right"><input type="submit" class="button" value="產製 MIG 4.1 ZIP"></td></tr>';

print '</table>';
print '</form>';
print '</div>';

print '</div>';

llxFooter();
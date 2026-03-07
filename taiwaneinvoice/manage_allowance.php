<?php
/**
 * [File Path: /taiwaneinvoice/manage_allowance.php]
 * 台灣電子折讓單 (Allowance D0401) 管理與 XML 匯出
 *
 * @package    TaiwanEInvoice
 * @author     Solo-man (Vincent Tsai)
 * @copyright  Copyright (c) 2026 Solo-man. All rights reserved.
 * @license    GNU General Public License v3.0 (GPL-3.0)
 */

// 1. 環境載入與路徑自適應
$res = 0;
if (file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
elseif (file_exists("../main.inc.php")) $res = @include "../main.inc.php";

if (!$res) die("Error: Dolibarr main.inc.php not found.");

require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once dol_buildpath('/taiwaneinvoice/lib/taiwaneinvoice_xml.lib.php', 0);

$id = GETPOST('id', 'int');
$action = GETPOST('action', 'alpha');

if ($id <= 0) accessforbidden("Invalid ID");

$object = new Facture($db);
if ($object->fetch($id) <= 0) accessforbidden("Invoice not found");

// 抓取折讓專用資料
$sql = "SELECT d.*, p.einvoice_no as parent_invoice_no, p.date_creation as parent_date_creation ";
$sql.= " FROM ".MAIN_DB_PREFIX."taiwaneinvoice_data as d ";
$sql.= " LEFT JOIN ".MAIN_DB_PREFIX."taiwaneinvoice_data as p ON d.fk_parent_invoice = p.fk_object ";
$sql.= " WHERE d.fk_object = " . (int)$id;
$resql = $db->query($sql);
$inv_data = $db->fetch_object($resql);

// 2. 執行 XML 下載動作
if ($action == 'download_xml') {
    if (!$inv_data || empty($inv_data->allowance_no)) {
        setEventMessages("「攔截預檢」：此單據尚未配發折讓單號，無法匯出 XML。", null, 'errors');
    } else {
        // 💡 呼叫 lib 產製 D0401 XML
        $xml_data = get_taiwan_allowance_xml_content($db, $id);
        
        if ($xml_data && !empty($xml_data['content'])) {
            if (ob_get_length()) ob_end_clean();
            header('Content-Type: application/xml; charset=utf-8');
            header('Content-Disposition: attachment; filename="'.$xml_data['filename'].'"');
            echo $xml_data['content'];
            exit;
        } else {
            setEventMessages("XML 內容產製失敗，請檢查原發票關連資料。", null, 'errors');
        }
    }
}

// 3. View 介面渲染
llxHeader('', "台灣電子折讓單管理");

print '<div id="taiwan-einv-logic-wrapper">';
print load_fiche_titre("電子折讓單 (D0401) 管理", '', 'title_setup');

print '<div class="fiche">';
print '<div class="tabBar">';
print '<table class="border centpercent">';
print '<tr><td class="titlefield">折讓單號</td><td>' . ($inv_data->allowance_no ?: '<span class="warning">未配號</span>') . '</td></tr>';
print '<tr><td>對應原發票</td><td>' . ($inv_data->parent_invoice_no ?: '無紀錄') . '</td></tr>';
print '<tr><td>狀態</td><td>' . $object->getLibStatut(1) . '</td></tr>';
print '</table>';

print '<div class="tabsAction">';
if ($inv_data && !empty($inv_data->allowance_no)) {
    print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=download_xml&id='.$id.'">匯出折讓 XML (D0401)</a>';
    print '<a class="butAction" href="'.dol_buildpath('/taiwaneinvoice/print_allowance.php', 1).'?id='.$id.'" target="_blank">列印折讓證明聯 (A4)</a>';
}
print '</div>';
print '</div>';
print '</div>';
print '</div>';

llxFooter();
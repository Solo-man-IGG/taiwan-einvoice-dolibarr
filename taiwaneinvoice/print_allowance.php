<?php
/**
 * [File Path: /taiwaneinvoice/print_allowance.php]
 * 台灣電子發票：銷貨退回、進貨退出或折讓證明單 (A4 財政部規範)
 */

$res = 0;
if (file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
elseif (file_exists("../main.inc.php")) $res = @include "../main.inc.php";

if (!$res) die("Error: Include main.inc.php failed.");

require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/includes/tecnickcom/tcpdf/tcpdf.php';

$id = GETPOST('id', 'int');
$object = new Facture($db);
if ($object->fetch($id) <= 0) exit;
$object->fetch_thirdparty();

// 抓取關鍵數據：包含原發票關聯
$sql = "SELECT d.*, p.einvoice_no as parent_invoice_no, p.date_creation as parent_date_creation ";
$sql.= " FROM ".MAIN_DB_PREFIX."taiwaneinvoice_data as d ";
$sql.= " LEFT JOIN ".MAIN_DB_PREFIX."taiwaneinvoice_data as p ON d.fk_parent_invoice = p.fk_object ";
$sql.= " WHERE d.fk_object = " . (int)$id;
$resql = $db->query($sql);
$inv_data = $db->fetch_object($resql);

if (ob_get_level()) ob_end_clean();

// PDF 設定 (A4 縱向)
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(15, 15, 15);
$font = 'msungstdlight'; // 確保 Dolibarr 有安裝此字體

$copies = array("第一、二聯：銷貨方留存記帳", "第三、四聯：買受人留存記帳");

foreach ($copies as $copy_title) {
    $pdf->AddPage();
    $pdf->SetFont($font, 'B', 16);
    $pdf->Cell(0, 10, "銷貨退回、進貨退出或折讓證明單", 0, 1, 'C');
    $pdf->SetFont($font, '', 10);
    $pdf->Cell(0, 6, $copy_title, 0, 1, 'R');
    
    $pdf->Ln(5);
    $pdf->Cell(90, 6, "開立日期：" . date('Y/m/d', $db->jdate($inv_data->date_creation)), 0, 0, 'L');
    $pdf->Cell(90, 6, "折讓單號：" . $inv_data->allowance_no, 0, 1, 'R');
    
    // 買賣雙方資訊欄
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(90, 7, " 買受人", 1, 0, 'L', 1);
    $pdf->Cell(90, 7, " 銷貨人", 1, 1, 'L', 1);
    $pdf->MultiCell(90, 20, "名稱：" . $object->thirdparty->name . "\n統編：" . $object->thirdparty->idprof1, 1, 'L', 0, 0);
    $pdf->MultiCell(90, 20, "名稱：" . $mysoc->name . "\n統編：" . $mysoc->idprof1, 1, 'L', 0, 1);
    
    $pdf->Ln(5);
    
    // 明細表格
    $pdf->SetFont($font, 'B', 9);
    $pdf->Cell(60, 8, "原發票日期 / 號碼", 1, 0, 'C', 1);
    $pdf->Cell(50, 8, "品名", 1, 0, 'C', 1);
    $pdf->Cell(15, 8, "數量", 1, 0, 'C', 1);
    $pdf->Cell(20, 8, "單價", 1, 0, 'C', 1);
    $pdf->Cell(35, 8, "金額 (不含稅)", 1, 1, 'C', 1);
    
    $pdf->SetFont($font, '', 9);
    foreach ($object->lines as $line) {
        $parent_info = date('Y/m/d', $db->jdate($inv_data->parent_date_creation)) . "\n" . $inv_data->parent_invoice_no;
        $pdf->MultiCell(60, 10, $parent_info, 1, 'C', 0, 0);
        $pdf->Cell(50, 10, mb_substr($line->libelle, 0, 20), 1, 0, 'L');
        $pdf->Cell(15, 10, abs($line->qty), 1, 0, 'C'); // 折讓單數量取絕對值
        $pdf->Cell(20, 10, number_format($line->subprice, 2), 1, 0, 'R');
        $pdf->Cell(35, 10, number_format(abs($line->total_ht), 0), 1, 1, 'R');
    }
    
    // 合計欄
    $pdf->SetFont($font, 'B', 10);
    $pdf->Cell(145, 8, "合計 (不含稅金額)", 1, 0, 'R');
    $pdf->Cell(35, 8, number_format(abs($object->total_ht), 0), 1, 1, 'R');
    $pdf->Cell(145, 8, "營業稅額", 1, 0, 'R');
    $pdf->Cell(35, 8, number_format(abs($object->total_tva), 0), 1, 1, 'R');
    $pdf->Cell(145, 8, "總計金額 (含稅)", 1, 0, 'R');
    $pdf->Cell(35, 8, number_format(abs($object->total_ttc), 0), 1, 1, 'R');
    
    $pdf->Ln(10);
    $pdf->SetFont($font, '', 10);
    $pdf->MultiCell(0, 10, "簽署人蓋章：____________________\n(本證明聯經買賣雙方同意得以網際網路或其他電子方式傳輸)", 0, 'L');
}

$pdf->Output('Allowance_' . $inv_data->allowance_no . '.pdf', 'I');
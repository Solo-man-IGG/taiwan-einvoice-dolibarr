<?php
/**
 * 璦閣-臺灣電子發票模組 for Dolibarr V2x(符合財政部 MIG 4.1 規範)
 * 版本：V1.0.1
 * 開發公司：璦閣數位科技
 * 開發者：Solo-Man(Vincent Tsai)
 * 版權聲明：GPL-3
 *
 * 檔案功能：發票證明聯列印
 */

// 1. 環境初始化與路徑偵測
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', 1);

$res = 0;
if (file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
} else {
    $res = @include "../main.inc.php";
}

if (!$res) die("Error: Dolibarr main.inc.php not found.");

require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

// 嘗試多個可能的 TCPDF 路徑
$tcpdf_paths = array(
    DOL_DOCUMENT_ROOT . '/includes/tecnickcom/tcpdf/tcpdf.php',
    DOL_DOCUMENT_ROOT . '/../includes/tecnickcom/tcpdf/tcpdf.php',
    dirname(DOL_DOCUMENT_ROOT) . '/includes/tecnickcom/tcpdf/tcpdf.php',
);

$tcpdf_loaded = false;
foreach ($tcpdf_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $tcpdf_loaded = true;
        break;
    }
}

if (!$tcpdf_loaded) {
    die("Error: TCPDF library not found. Tried paths: " . implode(', ', $tcpdf_paths));
}

// 清除可能的輸出緩衝，確保 PDF 下載正常
if (ob_get_level()) ob_end_clean();

$id   = GETPOST('id', 'int');
$type = GETPOST('type', 'alpha');
$is_copy = ($type === 'copy' || $type === 'reprint') ? 1 : 0; 

$object = new Facture($db);
if ($object->fetch($id) <= 0) die("Invoice not found.");
$object->fetch_thirdparty(); // 載入客戶資料以獲取統編

// 抓取電子發票擴充資料
$sql = "SELECT * FROM " . MAIN_DB_PREFIX . "taiwaneinvoice_data WHERE fk_object = " . (int)$id;
$resql = $db->query($sql);
$einv_data = $db->fetch_object($resql);

if (!$einv_data || empty($einv_data->einvoice_no)) {
    die("Error: This invoice has no electronic invoice number yet.");
}

// --- 2. 準備 PDF 參數 (57mm x 變動長度) ---
$page_width = 57;
$page_height = 200; // 預設長度，TCPDF 會自動分頁
$pdf = new TCPDF('P', 'mm', array($page_width, $page_height), true, 'UTF-8', false);

$pdf->SetCreator('Dolibarr TaiwanEInvoice');
$pdf->SetAuthor($mysoc->name);
$pdf->SetTitle('電子發票證明聯 - ' . $einv_data->einvoice_no);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(2, 5, 2);
$pdf->SetAutoPageBreak(true, 5);

// 設定中文字體 (使用 TCPDF 內建繁體中文字體)
$font_name = 'cid0ct'; // TCPDF 內建繁體中文字體
$pdf->SetFont($font_name, '', 10);
$pdf->AddPage();

// --- 3. 內容渲染 ---

// A. 標題與抬頭
$pdf->SetFont($font_name, 'B', 13);
$pdf->Cell(0, 7, $mysoc->name, 0, 1, 'C');
$pdf->SetFont($font_name, 'B', 15);
$pdf->Cell(0, 8, '電子發票證明聯', 0, 1, 'C');

// B. 補印標記 (異動點：排版優化)
if ($is_copy) {
    $pdf->SetFont($font_name, 'B', 12);
    $pdf->SetLineWidth(0.5);
    $pdf->Cell(0, 8, '【 補 印 】', 1, 1, 'C');
}

// C. 發票基本資訊
$pdf->Ln(2);
$pdf->SetFont($font_name, 'B', 12);
// 從發票日期計算年度和期別，使用 Dolibarr 發票物件的日期
$inv_timestamp = (is_numeric($object->date) ? $object->date : $db->jdate($object->date));
if (empty($inv_timestamp)) {
    $inv_timestamp = time(); // 防呆機制
}
$year = date('Y', $inv_timestamp) - 1911; // 轉換為民國年
$p_end = ceil(date('m', $inv_timestamp) / 2) * 2;
$period_text = sprintf("%02d-%02d月", $p_end - 1, $p_end);
$pdf->Cell(0, 6, $year . '年' . $period_text, 0, 1, 'C');
$pdf->Cell(0, 6, $einv_data->track_code . '-' . $einv_data->einvoice_no, 0, 1, 'C');

$pdf->SetFont($font_name, '', 8);
$pdf->Cell(0, 5, '格式：' . date('Y-m-d H:i:s', $inv_timestamp), 0, 1, 'L');
$pdf->Cell(0, 5, '隨機碼：' . $einv_data->random_no . '  總計：' . (int)$object->total_ttc, 0, 1, 'L');

$seller_ban = preg_replace('/[^0-9]/', '', $mysoc->tva_intra);
$buyer_ban  = ($einv_data->inv_type == '3') ? str_pad(preg_replace('/[^0-9]/', '', $object->thirdparty->tva_intra), 8, '0', STR_PAD_LEFT) : '00000000';
$pdf->Cell(0, 5, '賣方：' . $seller_ban . '  買方：' . ($buyer_ban == '00000000' ? '' : $buyer_ban), 0, 1, 'L');

// D. 條碼產製 (MIG 4.1 規範)
// 若為補印且系統設定隱藏條碼，則不顯示條碼
if (!$is_copy || empty($conf->global->TAIWAN_EINVOICE_HIDE_BARCODE_ON_REPRINT)) {
    // 一維條碼 (Code128): 年(3) + 期(2) + 號碼(8) + 隨機碼(4)
    $barcode_data = str_pad($year, 3, '0', STR_PAD_LEFT) . str_pad($period, 2, '0', STR_PAD_LEFT) . $einv_data->einvoice_no . $einv_data->random_no;
    $pdf->write1DBarcode($barcode_data, 'C128', 5, '', 48, 10, 0.4, array('position'=>'', 'align'=>'C', 'stretch'=>false, 'fitwidth'=>true, 'cellfitalign'=>'', 'border'=>false, 'hpadding'=>'auto', 'vpadding'=>'auto', 'fgcolor'=>array(0,0,0), 'bgcolor'=>false, 'text'=>false), 'N');
    $pdf->Ln(12);

    // 二維條碼 (QR Codes) - 左右兩個 QR Code
    $qr_left = $einv_data->track_code . $einv_data->einvoice_no . str_pad($year, 3, '0', STR_PAD_LEFT) . str_pad($period, 2, '0', STR_PAD_LEFT) . $einv_data->random_no;
    $qr_right = $seller_ban . $barcode_data;
    
    $y_pos = $pdf->GetY();
    $pdf->write2DBarcode($qr_left, 'QRCODE,L', 4, $y_pos, 22, 22, array('border'=>false), 'N');
    $pdf->write2DBarcode($qr_right, 'QRCODE,L', 31, $y_pos, 22, 22, array('border'=>false), 'N');
    $pdf->SetY($y_pos + 24);
} else {
    $pdf->Ln(4);
    $pdf->SetFont($font_name, 'B', 9);
    $pdf->Cell(0, 8, '--- (補印聯不提供領獎) ---', 0, 1, 'C');
}

// E. 備註與銷貨明細
$pdf->SetFont($font_name, '', 7);
$pdf->Cell(0, 5, '** 備註：退貨請攜帶證明聯 **', 0, 1, 'C');

if ($einv_data->inv_type == '3' || !empty($conf->global->TAIWAN_EINVOICE_ALWAYS_PRINT_DETAILS)) {
    $pdf->Ln(2);
    $pdf->Cell(0, 0, '', 'T', 1, 'C');
    $pdf->SetFont($font_name, 'B', 9);
    $pdf->Cell(0, 6, '銷貨明細', 0, 1, 'C');
    
    // 列印產品明細
    $pdf->SetFont($font_name, '', 7);
    $line_height = 4;
    foreach ($object->lines as $index => $line) {
        $desc = mb_substr($line->desc ?: $line->libelle, 0, 20);
        $qty = number_format($line->qty, 2);
        $price = number_format($line->subprice, 2);
        $total = number_format($line->total_ht, 2);
        
        $pdf->Cell(0, $line_height, ($index + 1) . '. ' . $desc . ' x' . $qty . ' @' . $price . ' = ' . $total, 0, 1, 'L');
    }
}

$pdf->Output('EInvoice_' . $einv_data->einvoice_no . '.pdf', 'I');
<?php
/**
 * 璦閣-臺灣電子發票模組 for Dolibarr V2x(符合財政部 MIG 4.1 規範)
 * 版本：V1.0.1
 * 開發公司：璦閣數位科技
 * 開發者：Solo-Man(Vincent Tsai)
 * 版權聲明：GPL-3
 *
 * 檔案功能：XML 產製核心庫，支援 C0401 (發票)、D0401 (折讓單)、C0501 (作廢) XML 產生
 */

function get_taiwan_invoice_xml_content($db, $invoice_id)
{
    global $mysoc;
    require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

    $object = new Facture($db);
    if ($object->fetch($invoice_id) <= 0) return null;
    $object->fetch_thirdparty();

    $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "taiwaneinvoice_data WHERE fk_object = " . (int)$invoice_id;
    $resql = $db->query($sql);
    $data = $db->fetch_object($resql);
    if (!$data || empty($data->einvoice_no)) return null;

    $buyer_tax_id = '';
    if (!empty($object->thirdparty->tva_intra)) {
        $buyer_tax_id = preg_replace('/[^0-9]/', '', $object->thirdparty->tva_intra);
    } elseif (!empty($object->thirdparty->idprof1)) {
        $buyer_tax_id = preg_replace('/[^0-9]/', '', $object->thirdparty->idprof1);
    }
    if ($data->inv_type == '3' && !empty($buyer_tax_id)) {
        $final_buyer_id = str_pad($buyer_tax_id, 8, '0', STR_PAD_LEFT);
    } else {
        $final_buyer_id = '0000000000';
    }

    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><Invoice xmlns="urn:GEINV:eInvoiceMessage:C0401:4.1" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"/>');

    $inv_timestamp = (is_numeric($object->date) ? $object->date : $db->jdate($object->date));
    if (empty($inv_timestamp)) {
        $inv_timestamp = time();
    }
    $main = $xml->addChild('Main');
    $main->addChild('InvoiceNumber', $data->einvoice_no);
    $main->addChild('InvoiceDate', date('Ymd', $inv_timestamp));
    $main->addChild('InvoiceTime', date('H:i:s', $inv_timestamp));

    $seller = $main->addChild('Seller');
    global $conf;
    $seller_id = (!empty($conf->global->MAIN_INFO_SIREN)) ? $conf->global->MAIN_INFO_SIREN : $mysoc->tva_intra;
    $seller->addChild('Identifier', preg_replace('/[^0-9]/', '', $seller_id));
    $seller->addChild('Name', htmlspecialchars(mb_substr($mysoc->name, 0, 60)));

    $buyer = $main->addChild('Buyer');
    $buyer->addChild('Identifier', $final_buyer_id);
    $buyer->addChild('Name', htmlspecialchars(mb_substr($object->thirdparty->name, 0, 60)));

    if ($data->inv_type == '2') {
        if (!empty($data->npoban)) {
            $main->addChild('DonateMark', '1');
            $main->addChild('NPOBAN', $data->npoban);
        } else {
            $main->addChild('DonateMark', '0');
            if (!empty($data->carrier_type)) {
                $main->addChild('CarrierType', $data->carrier_type);
                $main->addChild('CarrierId1', $data->carrier_id);
                $main->addChild('CarrierId2', $data->carrier_id);
            }
        }
    } else {
        $main->addChild('DonateMark', '0');
    }
    $details = $xml->addChild('Details');
    foreach ($object->lines as $index => $line) {
        $item = $details->addChild('ProductItem');
        $item->addChild('Description', htmlspecialchars(mb_substr($line->desc ?: $line->libelle, 0, 255)));
        $item->addChild('Quantity', number_format($line->qty, 2, '.', ''));
        $item->addChild('UnitPrice', number_format($line->subprice, 4, '.', ''));
        $item->addChild('Amount', number_format($line->total_ht, 2, '.', ''));
        $item->addChild('SequenceNumber', sprintf('%03d', $index + 1));
    }
    
    // Amount
    $amount = $xml->addChild('Amount');
    $amount->addChild('SalesAmount', (int) round($object->total_ht, 0));
    $amount->addChild('TaxType', ($object->lines[0]->tva_tx > 0 ? '1' : '3'));
    $amount->addChild('TaxAmount', (int) round($object->total_tva, 0));
    $amount->addChild('TotalAmount', (int) round($object->total_ttc, 0));

    return array(
        'filename' => "C0401_" . $data->einvoice_no . ".xml",
        'content'  => $xml->asXML()
    );
}

/**
 * 產製 D0401 (折讓) XML 內容
 */
function get_taiwan_allowance_xml_content($db, $invoice_id)
{
    global $mysoc;
    require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

    $object = new Facture($db);
    if ($object->fetch($invoice_id) <= 0) return null;
    $object->fetch_thirdparty(); // 載入客戶資料以獲取統編

    // 抓取折讓數據並關連原發票資訊 (關鍵點)
    $sql = "SELECT d.*, p.einvoice_no as parent_invoice_no, p.date_creation as parent_date_creation ";
    $sql.= " FROM " . MAIN_DB_PREFIX . "taiwaneinvoice_data as d ";
    $sql.= " LEFT JOIN " . MAIN_DB_PREFIX . "taiwaneinvoice_data as p ON d.fk_parent_invoice = p.fk_object ";
    $sql.= " WHERE d.fk_object = " . (int)$invoice_id;
    $resql = $db->query($sql);
    $data = $db->fetch_object($resql);

    if (!$data || empty($data->allowance_no)) return null;

    // 處理時間戳，使用 Dolibarr 發票物件的日期
    $inv_timestamp = (is_numeric($object->date) ? $object->date : $db->jdate($object->date));
    if (empty($inv_timestamp)) {
        $inv_timestamp = time(); // 防呆機制
    }

    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><Allowance xmlns="urn:GEINV:eInvoiceMessage:D0401:4.1" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"/>');

    // Main
    $main = $xml->addChild('Main');
    $main->addChild('AllowanceNumber', $data->allowance_no);
    $main->addChild('AllowanceDate', date('Ymd', $inv_timestamp));

    // 自動判斷 AllowanceType：若買方 ID 為 0000000000 則為 2（非營業人），否則為 1（營業人）
    // 優先檢查 tva_intra，若無則檢查 idprof1
    $buyer_tax_id = '';
    if (!empty($object->thirdparty->tva_intra)) {
        $buyer_tax_id = preg_replace('/[^0-9]/', '', $object->thirdparty->tva_intra);
    } elseif (!empty($object->thirdparty->idprof1)) {
        $buyer_tax_id = preg_replace('/[^0-9]/', '', $object->thirdparty->idprof1);
    }

    // 若有統編且為三聯式，輸出 8 碼；若無統編或為二聯式，輸出 10 碼 0
    if ($data->inv_type == '3' && !empty($buyer_tax_id)) {
        $buyer_id = str_pad($buyer_tax_id, 8, '0', STR_PAD_LEFT);
    } else {
        $buyer_id = '0000000000'; // 10 碼 0（一般消費者）
    }

    // AllowanceType: 1 = 買受人為營業人, 2 = 非營業人
    $allowance_type = ($buyer_id == '0000000000') ? '2' : '1';
    $main->addChild('AllowanceType', $allowance_type);

    $seller = $main->addChild('Seller');
    // 優先使用系統設定中的統編，若不存在則使用 $mysoc->tva_intra（台灣統一編號欄位）
    // 注意：請在 Dolibarr「設定 > 公司/組織」中填寫統編（VAT 號）
    global $conf;
    $seller_id = (!empty($conf->global->MAIN_INFO_SIREN)) ? $conf->global->MAIN_INFO_SIREN : $mysoc->tva_intra;
    if (empty($seller_id)) {
        // 統編為空的情況下會導致 XML 驗證失敗，請務必填寫
    }
    $seller->addChild('Identifier', preg_replace('/[^0-9]/', '', $seller_id));
    $seller->addChild('Name', htmlspecialchars(mb_substr($mysoc->name, 0, 60)));

    $buyer = $main->addChild('Buyer');
    $buyer->addChild('Identifier', $buyer_id);
    $buyer->addChild('Name', htmlspecialchars(mb_substr($object->thirdparty->name, 0, 60)));

    // Details (需關連原發票號碼與日期)
    // 處理原發票時間戳
    $parent_timestamp = 0;
    if (!empty($data->parent_date_creation)) {
        $parent_timestamp = $db->jdate($data->parent_date_creation);
        if (is_string($parent_timestamp)) {
            $parent_timestamp = strtotime($parent_timestamp);
        }
    }

    // 若無原發票日期，嘗試從原發票物件中獲取
    if (empty($parent_timestamp) || $parent_timestamp < 0) {
        if (!empty($object->fk_facture_source)) {
            $parent_invoice = new Facture($db);
            if ($parent_invoice->fetch($object->fk_facture_source) > 0) {
                $parent_timestamp = (is_numeric($parent_invoice->date) ? $parent_invoice->date : $db->jdate($parent_invoice->date));
            }
        }
    }

    // 若仍無法獲取原發票日期，使用折讓單建立日期作為備選
    if (empty($parent_timestamp) || $parent_timestamp < 0) {
        $parent_timestamp = $inv_timestamp;
    }

    $details = $xml->addChild('Details');
    foreach ($object->lines as $index => $line) {
        $item = $details->addChild('ProductItem');
        $item->addChild('OriginalInvoiceDate', date('Ymd', $parent_timestamp));
        $item->addChild('OriginalInvoiceNumber', $data->parent_invoice_no);
        $item->addChild('OriginalSequenceNumber', sprintf('%03d', $index + 1));
        $item->addChild('Description', htmlspecialchars(mb_substr($line->desc ?: $line->libelle, 0, 255)));
        $item->addChild('Quantity', abs($line->qty));
        $item->addChild('UnitPrice', number_format(abs($line->subprice), 4, '.', ''));
        $item->addChild('Amount', number_format(abs($line->total_ht), 2, '.', ''));
        $item->addChild('Tax', number_format(abs($line->total_tva), 2, '.', ''));
        $item->addChild('AllowanceType', '1'); // 1: 應稅
    }
    
    // Amount
    $amount = $xml->addChild('Amount');
    $amount->addChild('TaxAmount', (int) round(abs($object->total_tva), 0));
    $amount->addChild('TotalAmount', (int) round(abs($object->total_ttc), 0));

    return array(
        'filename' => "D0401_" . $data->allowance_no . ".xml",
        'content'  => $xml->asXML()
    );
}

/**
 * 產製 C0501 (發票作廢) XML 內容
 */
function get_taiwan_cancel_invoice_xml_content($db, $invoice_id)
{
    global $mysoc;
    require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

    $object = new Facture($db);
    if ($object->fetch($invoice_id) <= 0) return null;
    $object->fetch_thirdparty(); // 載入客戶資料以獲取統編

    // 抓取發票數據
    $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "taiwaneinvoice_data WHERE fk_object = " . (int)$invoice_id;
    $resql = $db->query($sql);
    $data = $db->fetch_object($resql);

    if (!$data || empty($data->einvoice_no)) return null;

    // 處理發票時間戳
    $inv_timestamp = (is_numeric($object->date) ? $object->date : $db->jdate($object->date));
    if (empty($inv_timestamp)) {
        $inv_timestamp = time(); // 防呆機制
    }

    // 處理作廢時間戳（使用當前系統時間）
    $cancel_timestamp = time();

    // 買受人 ID 處理
    $buyer_tax_id = '';
    if (!empty($object->thirdparty->tva_intra)) {
        $buyer_tax_id = preg_replace('/[^0-9]/', '', $object->thirdparty->tva_intra);
    } elseif (!empty($object->thirdparty->idprof1)) {
        $buyer_tax_id = preg_replace('/[^0-9]/', '', $object->thirdparty->idprof1);
    }

    if ($data->inv_type == '3' && !empty($buyer_tax_id)) {
        $buyer_id = str_pad($buyer_tax_id, 8, '0', STR_PAD_LEFT);
    } else {
        $buyer_id = '0000000000'; // 10 碼 0（一般消費者）
    }

    // 賣方 ID 處理
    global $conf;
    $seller_id = (!empty($conf->global->MAIN_INFO_SIREN)) ? $conf->global->MAIN_INFO_SIREN : $mysoc->tva_intra;
    if (empty($seller_id)) {
        return null; // 統編為空，無法產製 XML
    }
    $seller_id = preg_replace('/[^0-9]/', '', $seller_id);

    // 作廢原因（預設為「開立錯誤」，若資料庫有值則使用資料庫的值）
    $cancel_reason = (!empty($data->void_reason)) ? $data->void_reason : '開立錯誤';

    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><CancelInvoice xmlns="urn:GEINV:eInvoiceMessage:C0501:4.1" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"/>');

    // Main
    $main = $xml->addChild('Main');
    $main->addChild('CancelInvoiceNumber', $data->einvoice_no); // 原發票號碼
    $main->addChild('InvoiceDate', date('Ymd', $inv_timestamp)); // 原發票開立日期
    $main->addChild('CancelDate', date('Ymd', $cancel_timestamp)); // 作廢日期
    $main->addChild('CancelTime', date('H:i:s', $cancel_timestamp)); // 作廢時間
    $main->addChild('CancelReason', htmlspecialchars(mb_substr($cancel_reason, 0, 200))); // 作廢原因

    $seller = $main->addChild('Seller');
    $seller->addChild('Identifier', $seller_id);
    $seller->addChild('Name', htmlspecialchars(mb_substr($mysoc->name, 0, 60)));

    $buyer = $main->addChild('Buyer');
    $buyer->addChild('Identifier', $buyer_id);
    $buyer->addChild('Name', htmlspecialchars(mb_substr($object->thirdparty->name, 0, 60)));

    return array(
        'filename' => "C0501_" . $data->einvoice_no . ".xml",
        'content'  => $xml->asXML()
    );
}
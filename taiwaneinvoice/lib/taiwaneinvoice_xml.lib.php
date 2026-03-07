<?php
/**
 * [File Path: /taiwaneinvoice/lib/taiwaneinvoice_xml.lib.php]
 * 台灣電子發票模組 - XML 產製核心庫 (MIG 4.1)
 *
 * @package    TaiwanEInvoice
 * @author     Solo-man (Vincent Tsai)
 * @copyright  Copyright (c) 2026 Solo-man. All rights reserved.
 * @license    GNU General Public License v3.0 (GPL-3.0)
 * ---------------------------------------------------------
 * 支援規範：
 * - C0401: 銷貨發票
 * - D0401: 銷貨退回、進貨退出或折讓證明單
 */

/**
 * 產製 C0401 (發票) XML 內容
 */
function get_taiwan_invoice_xml_content($db, $invoice_id)
{
    global $mysoc;
    require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

    $object = new Facture($db);
    if ($object->fetch($invoice_id) <= 0) return null;

    $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "taiwaneinvoice_data WHERE fk_object = " . (int)$invoice_id;
    $resql = $db->query($sql);
    $data = $db->fetch_object($resql);
    if (!$data || empty($data->einvoice_no)) return null;

    // 買受人 ID 處理 (三聯式用統編，二聯式補 10 碼 0)
    $final_buyer_id = '0000000000';
    if ($data->inv_type == '3') {
        $final_buyer_id = str_pad(preg_replace('/[^0-9]/', '', $object->thirdparty->idprof1), 8, '0', STR_PAD_LEFT);
    }

    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><Invoice xmlns="urn:GEINV:eInvoiceMessage:C0401:4.1" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"/>');
    
    // Main
    $main = $xml->addChild('Main');
    $main->addChild('InvoiceNumber', $data->einvoice_no);
    $main->addChild('InvoiceDate', date('Ymd', $db->jdate($data->date_creation)));
    $main->addChild('InvoiceTime', date('H:i:s', $db->jdate($data->date_creation)));

    $seller = $main->addChild('Seller');
    $seller->addChild('Identifier', preg_replace('/[^0-9]/', '', $mysoc->idprof1));
    $seller->addChild('Name', htmlspecialchars(mb_substr($mysoc->name, 0, 60)));

    $buyer = $main->addChild('Buyer');
    $buyer->addChild('Identifier', $final_buyer_id);
    $buyer->addChild('Name', htmlspecialchars(mb_substr($object->thirdparty->name, 0, 60)));

    // 載具與捐贈處理
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

    // Details
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

    // 抓取折讓數據並關連原發票資訊 (關鍵點)
    $sql = "SELECT d.*, p.einvoice_no as parent_invoice_no, p.date_creation as parent_date_creation ";
    $sql.= " FROM " . MAIN_DB_PREFIX . "taiwaneinvoice_data as d ";
    $sql.= " LEFT JOIN " . MAIN_DB_PREFIX . "taiwaneinvoice_data as p ON d.fk_parent_invoice = p.fk_object ";
    $sql.= " WHERE d.fk_object = " . (int)$invoice_id;
    $resql = $db->query($sql);
    $data = $db->fetch_object($resql);

    if (!$data || empty($data->allowance_no)) return null;

    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><Allowance xmlns="urn:GEINV:eInvoiceMessage:D0401:4.1" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"/>');
    
    // Main
    $main = $xml->addChild('Main');
    $main->addChild('AllowanceNumber', $data->allowance_no);
    $main->addChild('AllowanceDate', date('Ymd', $db->jdate($data->date_creation)));
    $main->addChild('AllowanceType', ($data->inv_type == '3' ? '1' : '2')); // 1: 買受人為營業人, 2: 非營業人

    $seller = $main->addChild('Seller');
    $seller->addChild('Identifier', preg_replace('/[^0-9]/', '', $mysoc->idprof1));
    $seller->addChild('Name', htmlspecialchars(mb_substr($mysoc->name, 0, 60)));

    $buyer = $main->addChild('Buyer');
    $buyer_id = ($data->inv_type == '3') ? str_pad(preg_replace('/[^0-9]/', '', $object->thirdparty->idprof1), 8, '0', STR_PAD_LEFT) : '0000000000';
    $buyer->addChild('Identifier', $buyer_id);
    $buyer->addChild('Name', htmlspecialchars(mb_substr($object->thirdparty->name, 0, 60)));

    // Details (需關連原發票號碼與日期)
    $details = $xml->addChild('Details');
    foreach ($object->lines as $index => $line) {
        $item = $details->addChild('ProductItem');
        $item->addChild('OriginalInvoiceDate', date('Ymd', $db->jdate($data->parent_date_creation)));
        $item->addChild('OriginalInvoiceNumber', $data->parent_invoice_no);
        $item->addChild('OriginalSequenceNumber', sprintf('%03d', $index + 1));
        $item->addChild('Description', htmlspecialchars(mb_substr($line->desc ?: $line->libelle, 0, 255)));
        $item->addChild('Quantity', abs($line->qty));
        $item->addChild('UnitPrice', number_format($line->subprice, 4, '.', ''));
        $item->addChild('Amount', number_format(abs($line->total_ht), 2, '.', ''));
        $item->addChild('Tax', number_format(abs($line->total_tva), 2, '.', ''));
        $item->addChild('AllowanceType', '1'); // 1: 應稅
    }
    
    // Amount
    $amount = $xml->addChild('Amount');
    $amount->addChild('TaxAmount', (int) round(abs($object->total_tva), 0));
    $amount->addChild('TotalAmount', (int) round(abs($object->total_ht), 0));

    return array(
        'filename' => "D0401_" . $data->allowance_no . ".xml",
        'content'  => $xml->asXML()
    );
}
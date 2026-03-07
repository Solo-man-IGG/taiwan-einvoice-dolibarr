<?php
/**
 * [File Path: /taiwaneinvoice/core/modules/actions_taiwaneinvoice.class.php]
 * 台灣電子發票模組 (Taiwan E-Invoice Module) - Hook Actions
 *
 * @package    TaiwanEInvoice
 * @author     Solo-man (Vincent Tsai)
 * @copyright  Copyright (c) 2026 Solo-man. All rights reserved.
 * @license    GNU General Public License v3.0 (GPL-3.0)
 */

class ActionsTaiwanEInvoice
{
    /**
     * 檢查發票是否符合台灣作廢法規：次月 15 日前 (雙月申報制)
     * * @param int $invoice_date_timestamp 發票建立時間戳
     * @return bool 是否可作廢
     */
    private function isVoidable($invoice_date_timestamp)
    {
        if (!$invoice_date_timestamp) return true;
        
        $current_time = time();
        $inv_year     = (int)date('Y', $invoice_date_timestamp);
        $inv_month    = (int)date('m', $invoice_date_timestamp);
        
        // 雙月申報制邏輯：計算申報截止日
        $period_end_month = ($inv_month % 2 == 0) ? $inv_month + 1 : $inv_month + 2;
        $period_end_year  = $inv_year;
        
        if ($period_end_month > 12) {
            $period_end_month = 1;
            $period_end_year++;
        }
        
        // 截止日為次月 15 日 23:59:59
        $deadline_time = mktime(23, 59, 59, $period_end_month, 15, $period_end_year);
        return ($current_time <= $deadline_time);
    }

    /**
     * 注入前端欄位模板 (沙盒原則)
     * * @param object $object     Dolibarr 物件
     * @param array  $parameters Hook 參數
     */
    private function includeEInvoicingTemplate(&$object, $parameters)
    {
        // 沙盒容器隔離，確保 CSS 影響範圍不出界
        echo '<div id="taiwan-einv-logic-wrapper" style="width: 100%; clear: both; margin-bottom: 15px;">';
        $tpl_path = dol_buildpath('/taiwaneinvoice/core/tpl/einv_fields.tpl.php', 0);
        if (file_exists($tpl_path)) {
            include $tpl_path;
        }
        echo '</div>';
    }

    /**
     * Hook: 發票/廠商卡片頁面欄位渲染
     */
    public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        $context = $parameters['currentcontext'];
        // 僅在草稿態 (statut 0) 或建立頁面時顯示欄位注入
        if ($context == 'thirdpartycard' || ($context == 'invoicecard' && is_object($object) && $object->statut == 0) || $context == 'invoicecreate') {
            $this->includeEInvoicingTemplate($object, $parameters);
        }
        return 0;
    }

    /**
     * Hook: 渲染資訊面板並攔截原生按鈕 (狀態隔離原則)
     */
    public function tabContentViewInvoice($parameters, &$object, &$action, $hookmanager)
    {
        global $db, $langs;
        $contexts = array('invoicecard', 'facturecard');
        
        if (in_array($parameters['currentcontext'], $contexts) && is_object($object) && $object->statut > 0) {
            
            $sql = "SELECT d.status, d.date_creation, d.einvoice_no, d.allowance_no, d.fk_parent_invoice ";
            $sql.= " FROM ".MAIN_DB_PREFIX."taiwaneinvoice_data as d ";
            $sql.= " WHERE d.fk_object = ".(int)$object->id;
            
            $resql = $db->query($sql);
            $inv_data = ($resql) ? $db->fetch_object($resql) : null;
            
            $display_invoice_no = '';
            $display_allowance_nos = array();

            if ($inv_data) {
                if (!empty($inv_data->einvoice_no)) $display_invoice_no = $inv_data->einvoice_no;
                
                if (!empty($inv_data->allowance_no)) {
                    $display_allowance_nos[] = $inv_data->allowance_no;
                    // 若為折讓單，找尋父發票號碼
                    if ($inv_data->fk_parent_invoice > 0) {
                        $sql_p = "SELECT einvoice_no FROM ".MAIN_DB_PREFIX."taiwaneinvoice_data WHERE fk_object = ".(int)$inv_data->fk_parent_invoice;
                        $res_p = $db->query($sql_p);
                        if ($res_p && ($parent = $db->fetch_object($res_p))) {
                            $display_invoice_no = $parent->einvoice_no;
                        }
                    }
                }
            }

            // 處理發票與其關聯的所有折讓單號
            if ($object->type != 2) {
                $sql_c = "SELECT allowance_no FROM ".MAIN_DB_PREFIX."taiwaneinvoice_data WHERE fk_parent_invoice = ".(int)$object->id;
                $res_c = $db->query($sql_c);
                while($res_c && ($child = $db->fetch_object($res_c))) {
                    if (!in_array($child->allowance_no, $display_allowance_nos)) {
                        $display_allowance_nos[] = $child->allowance_no;
                    }
                }
            }

            // 正式態：顯示電子發票資訊區塊並啟動按鈕攔截
            if (!empty($display_invoice_no) || !empty($display_allowance_nos)) {
                $is_voidable = ($inv_data) ? $this->isVoidable($db->jdate($inv_data->date_creation)) : true;
                
                echo '<div id="taiwan-einv-logic-wrapper">';
                echo '<div id="taiwan-einv-info-display" style="margin: 15px 0; padding: 15px; background: #f9feff; border: 1px solid #bdeaff; border-left: 5px solid #0076ad; border-radius: 4px; clear: both; line-height: 1.8;">';
                echo '<span style="color: #004d71; font-weight: bold; font-size: 1.1em;">🔹 台灣電子發票編號 / 折讓單號：</span><br>';
                
                if (!empty($display_invoice_no)) {
                    echo '<span style="color: #d40000; font-weight: bold; font-size: 1.4em;">' . $display_invoice_no . '</span>';
                }

                if (!empty($display_allowance_nos)) {
                    foreach($display_allowance_nos as $ano) {
                        echo '<span style="color: #ccc; font-size: 1.2em; padding: 0 8px;">|</span>';
                        echo '<span style="color: #e67e22; font-weight: bold; font-size: 1.3em; background: #fffbe6; padding: 2px 8px; border-radius: 4px; border: 1px solid #ffe58f;">' . $ano . '</span> ';
                    }
                }

                if ($inv_data && (int)$inv_data->status === 9) {
                    echo ' <span class="badge badge-status9" style="background-color: #ff4d4f; color: #ffffff; padding: 2px 10px; border-radius: 10px; margin-left:10px;">已作廢 VOID</span>';
                }
                echo '</div></div>';

                // 攔截邏輯：移除修改、刪除、及原生折讓按鈕，確保資料一致性
                echo '<script type="text/javascript">
                $(document).ready(function() {
                    $("a.butActionDelete, a[href*=\'action=delete\'], a[href*=\'action=modif\'], a[href*=\'action=create_allowance\']").remove();';
                    if (!empty($display_allowance_nos) || !$is_voidable) {
                        // 若已有折讓或超過申報期，移除原生取消按鈕
                        echo '$("a.butAction[href*=\'action=canceled\']").remove();';
                    }
                echo '});
                </script>';
            }
        }
        return 0;
    }

    /**
     * Hook: 加入台灣專用操作按鈕 (列印、補印、XML)
     * 功能不追減原則：確保列印證明聯、補印證明聯、匯出 XML 功能存在
     */
    public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
    {
        global $db;
        if (in_array($parameters['currentcontext'], array('invoicecard', 'facturecard')) && is_object($object) && $object->statut > 0) {
            
            $sql = "SELECT status, einvoice_no, allowance_no FROM ".MAIN_DB_PREFIX."taiwaneinvoice_data WHERE fk_object = ".(int)$object->id;
            $resql = $db->query($sql);
            $inv_data = ($resql) ? $db->fetch_object($resql) : null;
            
            if ($inv_data) {
                // 折讓單處理 (Type 2 或 具備折讓號)
                if ($object->type == 2 || !empty($inv_data->allowance_no)) {
                    print '<a class="butAction" href="'.dol_buildpath('/taiwaneinvoice/print_allowance.php', 1).'?id='.$object->id.'" target="_blank">列印折讓證明聯 (A4)</a>';
                    print '<a class="butAction" href="'.dol_buildpath('/taiwaneinvoice/manage_invoice.php', 1).'?action=download_xml&id='.$object->id.'">匯出折讓 XML (D0401)</a>';
                } 
                // 正式發票處理
                elseif (!empty($inv_data->einvoice_no)) {
                    $is_void = ((int)$inv_data->status === 9);
                    $print_url = dol_buildpath('/taiwaneinvoice/print.php', 1) . '?id=' . $object->id;
                    $xml_url   = dol_buildpath('/taiwaneinvoice/manage_invoice.php', 1) . '?id=' . $object->id;
                    
                    if ($is_void) {
                        print '<a class="butAction" href="'.$print_url.'&type=reprint" target="_blank">補印證明聯(作廢)</a>';
                    } else {
                        print '<a class="butAction" href="'.$print_url.'&type=normal" target="_blank">列印證明聯</a>';
                        print '<a class="butAction" href="'.$print_url.'&type=copy" target="_blank">補印證明聯</a>';
                    }
                    print '<a class="butAction" href="'.$xml_url.'&action=download_xml">匯出 XML (C0401)</a>';
                }
            }
        }
        return 0;
    }
}
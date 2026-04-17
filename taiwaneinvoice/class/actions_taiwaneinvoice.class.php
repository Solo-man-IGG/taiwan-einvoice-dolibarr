<?php
/**
 * 璦閣-臺灣電子發票模組 for Dolibarr V2x(符合財政部 MIG 4.1 規範)
 * 版本：V1.0.1
 * 開發公司：璦閣數位科技
 * 開發者：Solo-Man(Vincent Tsai)
 * 版權聲明：GPL-3
 *
 * 檔案功能：Hook Actions 類別，處理發票頁面的按鈕顯示與攔截邏輯
 */

class ActionsTaiwanEInvoice
{
    private function isVoidable($invoice_date_timestamp)
    {
        $invoice_date = getdate($invoice_date_timestamp);
        $current_date = getdate(time());
        $invoice_year = $invoice_date['year'];
        $invoice_month = $invoice_date['mon'];
        $current_year = $current_date['year'];
        $current_month = $current_date['mon'];
        if ($invoice_year < $current_year) {
            return false;
        }
        if ($invoice_year == $current_year && $invoice_month < $current_month) {
            return false;
        }
        return true;
    }

    private function includeEInvoicingTemplate(&$object, $parameters)
    {
        echo '<div id="taiwan-einv-logic-wrapper" style="width: 100%; clear: both; margin-bottom: 15px;">';
        $tpl_path = dol_buildpath('/taiwaneinvoice/core/tpl/einv_fields.tpl.php', 0);
        if (file_exists($tpl_path)) {
            include $tpl_path;
        }
        echo '</div>';
    }

    public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        global $db, $conf, $langs;
        if ($parameters['context'] == 'invoicecard' || $parameters['context'] == 'thirdpartycard') {
            $this->includeEInvoicingTemplate($object, $parameters);
        }
        return 0;
    }

    public function tabContentViewInvoice($parameters, &$object, &$action, $hookmanager)
    {
        global $db, $langs;
        $contexts = array('invoicecard', 'facturecard');
        
        if (in_array($parameters['currentcontext'], $contexts) && is_object($object) && $object->statut > 0) {
            $sql = "SELECT d.status, d.date_creation, d.einvoice_no, d.track_code, d.allowance_no, d.fk_parent_invoice ";
            $sql.= " FROM ".MAIN_DB_PREFIX."taiwaneinvoice_data as d ";
            $sql.= " WHERE d.fk_object = ".(int)$object->id;

            $resql = $db->query($sql);
            $inv_data = ($resql) ? $db->fetch_object($resql) : null;

            $display_invoice_no = '';
            $display_track_code = '';
            $display_allowance_nos = array();

            if ($inv_data) {
                if (!empty($inv_data->track_code)) $display_track_code = $inv_data->track_code;
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

            if (!empty($display_invoice_no) || !empty($display_allowance_nos)) {
                $is_voidable = ($inv_data) ? $this->isVoidable($db->jdate($inv_data->date_creation)) : true;
                $is_void = ($inv_data && (int)$inv_data->status === 9);

                echo '<div id="taiwan-einv-logic-wrapper">';
                if ($is_void) {
                    echo '<div class="error" style="margin: 15px 0; padding: 12px; background: #fff5f5; border: 1px solid #ffcccc; border-left: 5px solid #ff4d4f; border-radius: 4px; clear: both;">';
                    echo '<span style="color: #cc0000; font-weight: bold;">⚠️ 此發票已作廢，現僅提供作廢 XML (C0501) 匯出。</span>';
                    echo '</div>';
                }
                echo '<div id="taiwan-einv-info-display" style="margin: 15px 0; padding: 15px; background: #f9feff; border: 1px solid #bdeaff; border-left: 5px solid #0076ad; border-radius: 4px; clear: both; line-height: 1.8;">';
                echo '<div class="einv-header" style="font-size: 14px; font-weight: bold; color: #0055aa; margin-bottom: 10px;">';
                echo '<span style="font-size: 16px;">📄</span> 電子發票資訊';
                if (!empty($display_invoice_no)) {
                    echo ' <span style="margin-left:10px; color:#d22d2d;">發票號碼：'.$display_invoice_no.'</span>';
                }
                if (!empty($display_track_code)) {
                    echo ' <span style="margin-left:10px; color:#0055aa;">字軌：'.$display_track_code.'</span>';
                }
                if (!empty($display_allowance_nos)) {
                    echo ' <span style="margin-left:10px; color:#0055aa;">折讓單：'.implode(', ', $display_allowance_nos).'</span>';
                }
                echo '</div>';
                if ($is_void) {
                    echo ' <span class="badge badge-status9" style="background-color: #ff4d4f; color: #ffffff; padding: 2px 10px; border-radius: 10px; margin-left:10px;">已作廢 VOID</span>';
                }
                echo '</div></div>';
                echo '<script type="text/javascript">
                $(document).ready(function() {
                    $("a.butActionDelete, a[href*=\'action=delete\'], a[href*=\'action=modif\'], a[href*=\'action=create_allowance\'], a[href*=\'action=reopen\']").remove();';
                    if (!empty($display_allowance_nos) || !$is_voidable || $is_void) {
                        echo '$("a.butAction[href*=\'action=canceled\']").remove();';
                    }
                    if ($is_void) {
                        echo '$("a[href*=\'fac_avoir\']").remove();';
                    }
                echo '});
                </script>';
            }
        }
        return 0;
    }

    public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
    {
        global $db;
        if (in_array($parameters['currentcontext'], array('invoicecard', 'facturecard')) && is_object($object) && $object->statut > 0) {

            $sql = "SELECT status, einvoice_no, allowance_no FROM ".MAIN_DB_PREFIX."taiwaneinvoice_data WHERE fk_object = ".(int)$object->id;
            $resql = $db->query($sql);
            $inv_data = ($resql) ? $db->fetch_object($resql) : null;

            if ($inv_data) {
                $is_void = ((int)$inv_data->status === 9);
                $print_url = dol_buildpath('/taiwaneinvoice/print.php', 1).'?id='.$object->id;
                $xml_url = dol_buildpath('/taiwaneinvoice/manage_invoice.php', 1).'?action=download_xml&id='.$object->id;
                if ($object->type == 2 || !empty($inv_data->allowance_no)) {
                    $is_void = ((int)$inv_data->status === 9);
                    if (!$is_void) {
                        print '<a class="butAction" href="'.dol_buildpath('/taiwaneinvoice/print_allowance.php', 1).'?id='.$object->id.'" target="_blank">列印折讓證明聯 (A4)</a>';
                        print '<a class="butAction" href="'.dol_buildpath('/taiwaneinvoice/manage_invoice.php', 1).'?action=download_xml&id='.$object->id.'">匯出折讓 XML (D0401)</a>';
                    }
                } else {
                    if ($is_void) {
                        print '<a class="butAction" href="'.$xml_url.'&xml_type=C0501">匯出作廢 XML (C0501)</a>';
                    } else {
                        print '<a class="butAction" href="'.$print_url.'&type=normal" target="_blank">列印證明聯</a>';
                        print '<a class="butAction" href="'.$print_url.'&type=copy" target="_blank">補印證明聯</a>';
                        print '<a class="butAction" href="'.$xml_url.'&xml_type=C0401">匯出 XML (C0401)</a>';
                    }
                }
            }
        }
        return 0;
    }
}
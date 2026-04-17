<?php
/**
 * 璦閣-臺灣電子發票模組 for Dolibarr V2x(符合財政部 MIG 4.1 規範)
 * 版本：V1.0.1
 * 開發公司：璦閣數位科技
 * 開發者：Solo-Man(Vincent Tsai)
 * 版權聲明：GPL-3
 *
 * 檔案功能：前端顯示樣板，顯示發票欄位、作廢狀態、按鈕控管
 */

global $db, $conf, $langs;

// 判定當前操作對象是廠商 (societe) 還是發票 (facture)
$target_type = ($object->element == 'societe') ? 'thirdparty' : 'invoice';
$thirdparty = ($target_type == 'thirdparty') ? $object : $object->thirdparty;

// 偵測是否為折讓單（type = 2）
$is_allowance = ($target_type == 'invoice' && is_object($object) && property_exists($object, 'type') && $object->type == 2);

// 偵測 URL 中的 facid 參數，如果存在表示發票已經建立
$facid_from_url = GETPOST('facid', 'int');
$has_facid = ($facid_from_url > 0);

$current_socid = 0;
if (is_object($thirdparty) && $thirdparty->id > 0) {
    $current_socid = $thirdparty->id;
} elseif (GETPOST('socid', 'int') > 0) {
    $current_socid = GETPOST('socid', 'int');
}

$selected_inv_type = -1;
$selected_carrier_type = '';
$carrier_id = '';
$has_real_invoice_data = false;
$einv_status = 1;
$real_einvoice_no = '';
$allowance_numbers = array(); // 折讓單號陣列
$void_date = ''; // 作廢日期

// 優先抓取已存在的發票資料
if ($target_type == 'invoice' && !empty($object->id) && $object->id > 0) {
    $sql = "SELECT inv_type, carrier_type, carrier_id, npoban, status, einvoice_no, date_void FROM " . MAIN_DB_PREFIX . "taiwaneinvoice_data WHERE fk_object = " . (int)$object->id;
    $resql = $db->query($sql);
    if ($resql && ($row = $db->fetch_object($resql))) {
        $einv_status = $row->status;
        $real_einvoice_no = $row->einvoice_no;
        $void_date = $row->date_void;
        if ($row->inv_type != -1 && $row->inv_type !== null) {
            $selected_inv_type = $row->inv_type;
            $selected_carrier_type = $row->carrier_type;
            $carrier_id = (!empty($row->npoban)) ? $row->npoban : $row->carrier_id;
            if (!empty($row->npoban)) $selected_carrier_type = 'LOVEDON';
            $has_real_invoice_data = (!empty($real_einvoice_no));
        }
    }

    // 查詢是否有以當前發票為原發票的折讓單
    if (!$is_allowance) {
        $sql_allowance = "SELECT allowance_no FROM " . MAIN_DB_PREFIX . "taiwaneinvoice_data WHERE fk_parent_invoice = " . (int)$object->id . " AND allowance_no IS NOT NULL AND allowance_no != ''";
        $resql_allowance = $db->query($sql_allowance);
        if ($resql_allowance) {
            while ($row_allowance = $db->fetch_object($resql_allowance)) {
                $allowance_numbers[] = $row_allowance->allowance_no;
            }
        }
    }
}

// 若發票類型未設定，則抓取廠商預設偏好
if ($selected_inv_type == -1 && $current_socid > 0) {
    $sql_pref = "SELECT inv_type, carrier_type, carrier_id, npoban FROM " . MAIN_DB_PREFIX . "taiwaneinvoice_customer_pref WHERE fk_soc = " . (int)$current_socid;
    $res_pref = $db->query($sql_pref);
    if ($res_pref && ($row_pref = $db->fetch_object($res_pref))) {
        $selected_inv_type = $row_pref->inv_type;
        $selected_carrier_type = $row_pref->carrier_type;
        $carrier_id = (!empty($row_pref->npoban)) ? $row_pref->npoban : $row_pref->carrier_id;
        if (!empty($row_pref->npoban)) $selected_carrier_type = 'LOVEDON';
    }
}

$is_readonly = ($einv_status == 9) ? 'disabled' : '';

// 如果沒有 facid（新建發票），則不顯示欄位
$show_fields = ($target_type == 'invoice' && $has_facid);
?>

<style>
/* 沙盒原則：CSS 樣式僅限縮於模板容器內 */
#taiwan-einv-logic-inner .tw-field-row { border-bottom: 1px solid #f4f4f4; padding: 8px 0; display: flex; align-items: center; }
#taiwan-einv-logic-inner .tw-label { width: 25%; min-width: 140px; font-weight: bold; color: #0055aa; }
#taiwan-einv-logic-inner .tw-value { flex-grow: 1; }
.einv-void-banner { background-color: #fff5f5; color: #d22d2d; font-weight: bold; text-align: center; padding: 10px; border: 1px dashed #d22d2d; margin-bottom: 10px; }
</style>

<?php if ($show_fields): ?>
<div id="taiwan-einv-logic-inner" style="border-top: 2px solid #0055aa; margin-top: 10px; padding-top: 5px;">
    <?php if ($einv_status == 9): ?>
        <?php
        $void_date_display = '';
        if (!empty($void_date)) {
            $void_timestamp = $db->jdate($void_date);
            if (is_string($void_timestamp)) {
                $void_timestamp = strtotime($void_timestamp);
            }
            if ($void_timestamp > 0) {
                $void_date_display = date('Y-m-d', $void_timestamp);
            }
        }
        ?>
        <div class="einv-void-banner">🚫 此發票已於 <?php echo $void_date_display; ?> 作廢，僅供 C0501 XML 匯出。</div>
        <div class="einv-void-warning" style="background-color: #ffcccc; color: #cc0000; font-weight: bold; text-align: center; padding: 8px; border: 1px solid #cc0000; margin-bottom: 10px; border-radius: 4px;">⚠️ 此發票號碼已失效，僅供存查。</div>
    <?php endif; ?>

    <?php if (!$is_allowance): ?>
    <div class="tw-field-row">
        <div class="tw-label">發票類型</div>
        <div class="tw-value">
            <select id="tw_inv_type" class="flat" style="width: 100%; max-width: 300px; border: 1px solid #0055aa;"
                    onchange="updateMainUI(); saveEInvField('inv_type', this.value)" <?php echo $is_readonly; ?>>
                <option value="-1">-- 請選擇 --</option>
                <option value="0" <?php echo ($selected_inv_type === '0' ? 'selected' : ''); ?>>[0] 不開發票</option>
                <option value="2" <?php echo ($selected_inv_type === '2' ? 'selected' : ''); ?>>[2] 二聯式</option>
                <option value="3" <?php echo ($selected_inv_type === '3' ? 'selected' : ''); ?>>[3] 三聯式 (統編: <?php echo $thirdparty->tva_intra; ?>)</option>
            </select>
            <span id="tw_einvoice_no_display" style="margin-left:10px; font-weight:bold; color:#d22d2d;"><?php echo $real_einvoice_no; ?></span>
            <?php if (!empty($allowance_numbers)): ?>
            <span style="margin-left:10px; font-weight:bold; color:#0055aa;">折讓單：<?php echo implode(', ', $allowance_numbers); ?></span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($is_allowance): ?>
    <div class="tw-field-row">
        <div class="tw-label">折讓單號碼</div>
        <div class="tw-value">
            <span id="tw_allowance_no_display" style="font-weight:bold; color:#d22d2d;"><?php echo $real_einvoice_no; ?></span>
        </div>
    </div>
    <?php endif; ?>

    <div class="tw-field-row einv-dynamic-row" style="display: none;">
        <div class="tw-label">載具/愛心碼類型</div>
        <div class="tw-value">
            <select id="tw_carrier_type" class="flat" style="width: 100%; max-width: 300px;" 
                    onchange="updateCarrierUI(); saveEInvField('carrier_type', this.value);" <?php echo $is_readonly; ?>>
                <option value="">-- 紙本證明聯 --</option>
                <option value="3J0002" <?php echo ($selected_carrier_type == '3J0002' ? 'selected' : ''); ?>>手機條碼</option>
                <option value="CQ0001" <?php echo ($selected_carrier_type == 'CQ0001' ? 'selected' : ''); ?>>自然人憑證</option>
                <option value="LOVEDON" <?php echo ($selected_carrier_type == 'LOVEDON' ? 'selected' : ''); ?>>捐贈碼 (愛心碼)</option>
            </select>
        </div>
    </div>

    <div class="tw-field-row einv-dynamic-row einv-carrier-id-row" style="display: none;">
        <div class="tw-label">載具/捐贈編號</div>
        <div class="tw-value">
            <input type="text" id="tw_carrier_id" class="flat" style="width: 100%; max-width: 300px; border: 1px solid #0055aa;" 
                   value="<?php echo htmlspecialchars($carrier_id); ?>"
                   onblur="validateCarrier(this.value); saveEInvField('carrier_id', this.value);" <?php echo $is_readonly; ?>>
            <div id="carrier_error" style="color: #d22d2d; font-size: 0.85em; display: none; margin-top: 4px;"></div>
        </div>
    </div>
</div>

<script type="text/javascript">
$(document).ready(function() {
    updateMainUI();

    var hasEInvNo = '<?php echo $has_real_invoice_data ? "1" : "0"; ?>';
    var einvStatus = '<?php echo $einv_status; ?>';
    var invoiceDate = '<?php echo (is_numeric($object->date) ? date('Y-m-d', $object->date) : ''); ?>';
    var abandonBtn = $('a[href*="action=setabandon"]');
    var printBtn = $('a[href*="/taiwaneinvoice/print.php"]');
    var xmlBtn = $('a[href*="/taiwaneinvoice/manage_invoice.php"]');

    // 作廢狀態按鈕控管
    if (einvStatus === "9") {
        // 禁用按鈕（無法移除的按鈕改為 disable）
        var reopenBtn = $('a[href*="action=reopen"]');
        var reprintBtn = $('a[href*="action=valid"]');
        var allowanceBtn = $('a[href*="fac_avoir"]');

        // 禁用所有按鈕
        if (reopenBtn.length > 0) reopenBtn.addClass('disabled').css('pointer-events', 'none').attr('title', '發票已作廢，無法重新開啟');
        if (printBtn.length > 0) printBtn.addClass('disabled').css('pointer-events', 'none').attr('title', '發票已作廢，無法列印證明聯');
        if (abandonBtn.length > 0) abandonBtn.addClass('disabled').css('pointer-events', 'none').attr('title', '發票已作廢');
        if (allowanceBtn.length > 0) allowanceBtn.addClass('disabled').css('pointer-events', 'none').attr('title', '發票已作廢，無法開立折讓單');
        if (xmlBtn.length > 0) xmlBtn.addClass('disabled').css('pointer-events', 'none').attr('title', '發票已作廢，僅供 C0501 XML 匯出');

        // 新增「匯出作廢 XML (C0501)」按鈕
        var cancelXmlUrl = '<?php echo dol_buildpath("/taiwaneinvoice/manage_invoice.php", 1); ?>?id=<?php echo (int)$object->id; ?>&xml_type=C0501';
        var cancelXmlBtn = $('<a class="butAction" href="' + cancelXmlUrl + '">匯出作廢 XML (C0501)</a>');
        if (xmlBtn.length > 0) {
            xmlBtn.parent().append(cancelXmlBtn);
        } else {
            $('.tabsAction').append(cancelXmlBtn);
        }
    }

    // 攔截原則：若已配號，強制接管作廢邏輯並要求填寫理由
    if (hasEInvNo === "1" && abandonBtn.length > 0 && einvStatus !== "9") {
        abandonBtn.html('<span class="fa fa-ban"></span> 作廢發票 <i class="fa fa-lock" title="法規鎖定"></i>');
        abandonBtn.addClass('butActionDelete').css("color", "#fff");

        abandonBtn.attr('href', '#').off('click').on('click', function(e) {
            e.preventDefault();

            // 法規時效預檢：檢查是否超過次月15日
            if (!checkVoidDeadline(invoiceDate)) {
                return;
            }

            var reason = prompt("【法規鎖定】此發票已配號，作廢需填寫原因 (至少5字)：", "");
            if (reason === null) return;
            if (reason.trim().length < 5) {
                alert("❌ 作廢失敗：原因字數不足。");
                return;
            }
            if (confirm("⚠️ 注意：作廢電子發票為不可逆操作，確定要繼續嗎？")) {
                saveEInvField('void_logic', reason);
                setTimeout(function(){ location.reload(); }, 1200);
            }
        });
    }
});

/**
 * 法規時效預檢：檢查是否超過次月15日
 * @param {string} invoiceDateStr 發票日期 (YYYY-MM-DD)
 * @returns {boolean} 是否可以作廢
 */
function checkVoidDeadline(invoiceDateStr) {
    if (!invoiceDateStr) return true; // 無法判斷，允許作廢

    var invoiceDate = new Date(invoiceDateStr);
    var currentDate = new Date();

    // 計算次月15日
    var nextMonth = new Date(invoiceDate);
    nextMonth.setMonth(nextMonth.getMonth() + 1);
    nextMonth.setDate(15);
    nextMonth.setHours(23, 59, 59, 999);

    // 檢查是否超過次月15日
    if (currentDate > nextMonth) {
        alert("❌ 已過作廢期限（次月15日），請改用『折讓』功能。\n發票日期：" + invoiceDateStr + "\n作廢期限：" + nextMonth.toISOString().split('T')[0]);
        return false;
    }

    return true;
}

/**
 * 根據發票類型顯示或隱藏載具欄位
 */
function updateMainUI() {
    var invType = $("#tw_inv_type").val();
    if (invType == "2" || invType == "3") {
        $(".einv-dynamic-row").show();
        updateCarrierUI();
    } else {
        $(".einv-dynamic-row").hide();
    }
}

/**
 * 根據載具類型切換 UI 顯示與 Placeholder
 */
function updateCarrierUI() {
    var type = $("#tw_carrier_type").val();
    if (type === "") {
        $(".einv-carrier-id-row").hide();
    } else {
        $(".einv-carrier-id-row").show();
        var placeholder = (type === "3J0002") ? "/ABC1234" : (type === "CQ0001" ? "AB12345678" : "請輸入捐贈碼");
        $("#tw_carrier_id").attr("placeholder", placeholder);
    }
}

/**
 * 攔截預檢：驗證載具格式
 */
function validateCarrier(val) {
    var type = $("#tw_carrier_type").val();
    var err = $("#carrier_error").hide();
    if (type === "3J0002" && !(/^\/[A-Z0-9.+-]{7}$/.test(val))) {
        err.text("❌ 手機條碼需為 / 開頭共 8 碼").show();
    } else if (type === "CQ0001" && !(/^[A-Z]{2}[0-9]{14}$/.test(val))) {
        err.text("❌ 自然人憑證格式不符").show();
    }
}

/**
 * AJAX 儲存欄位數據
 */
function saveEInvField(f, v) {
    var targetId = '<?php echo (int)$object->id; ?>';
    // 如果 target_id 不存在（發票建立頁面），則不執行保存
    if (!targetId || targetId === '0') {
        console.log("跳過保存：target_id 不存在（發票尚未建立）");
        return;
    }

    $("#taiwan-einv-logic-inner").css("opacity", "0.5");
    // 路徑採用 dol_buildpath 確保相容性
    var postUrl = '<?php echo dol_buildpath("/taiwaneinvoice/ajax/save_data.php", 1); ?>';
    var token = '<?php echo function_exists('newToken') ? newToken() : $_SESSION['newtoken']; ?>';
    $.post(postUrl, {
        target_type: '<?php echo $target_type; ?>',
        target_id: targetId,
        field: f,
        value: v,
        token: token
    }, function(response) {
        $("#taiwan-einv-logic-inner").css("opacity", "1");
        if(!response.success) alert("❌ 儲存失敗：" + response.error);
    }, 'json');
}
</script>
<?php endif; ?>
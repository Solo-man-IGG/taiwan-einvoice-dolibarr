-- 台灣電子發票模組 - 資料庫建置腳本 (V1.0 開源版)
-- @package    TaiwanEInvoice
-- @author     Solo-man (Vincent Tsai)
-- @copyright  Copyright (c) 2026 Solo-man. All rights reserved.
-- @license    GNU General Public License v3.0 (GPL-3.0)

-- 1. 核心數據表：儲存發票與折讓關聯數據
CREATE TABLE IF NOT EXISTS `llx_taiwaneinvoice_data` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `fk_object` int(11) NOT NULL COMMENT '對應 Dolibarr facture rowid',
  `fk_parent_invoice` int(11) DEFAULT 0 COMMENT '原發票 ID (折讓單 D0401 必填)',
  `type_object` varchar(32) NOT NULL DEFAULT 'facture',
  
  -- 發票/折讓號碼與基礎資訊
  `einvoice_no` varchar(10) DEFAULT NULL COMMENT '發票號碼 (含字軌, 如 AB12345678)',
  `track_code` varchar(2) DEFAULT NULL COMMENT '字軌 (如 AB)',
  `allowance_no` varchar(20) DEFAULT NULL COMMENT '折讓單號碼',
  `random_no` varchar(4) DEFAULT NULL COMMENT '隨機碼 (4位數字)',
  
  -- 法規必要欄位
  `inv_type` varchar(2) DEFAULT '3' COMMENT '資推類別: 2:二聯, 3:三聯',
  `carrier_type` varchar(20) DEFAULT NULL COMMENT '載具類別',
  `carrier_id` varchar(64) DEFAULT NULL COMMENT '載具隱碼/捐贈碼',
  `npoban` varchar(10) DEFAULT NULL COMMENT '捐贈對象統編',
  
  -- 狀態與紀錄
  `status` int(11) DEFAULT 1 COMMENT '1:已開立, 9:已作廢',
  `void_reason` varchar(255) DEFAULT NULL COMMENT '作廢原因',
  `date_void` datetime DEFAULT NULL COMMENT '作廢日期',
  `date_creation` datetime DEFAULT NULL COMMENT '發票開立日期',
  `tms` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`rowid`),
  UNIQUE KEY `uk_taiwaneinvoice_data_object` (`fk_object`),
  KEY `idx_taiwaneinvoice_no` (`einvoice_no`),
  KEY `idx_taiwaneinvoice_allowance` (`allowance_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. 字軌管理表：支援多組字軌配號與優先權排序
CREATE TABLE IF NOT EXISTS `llx_taiwaneinvoice_track` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `year` int(11) NOT NULL COMMENT '民國年度 (如: 115)',
  `period` varchar(2) NOT NULL COMMENT '期別 (02, 04, 06, 08, 10, 12)',
  `track_code` varchar(2) NOT NULL COMMENT '字軌 (如: AB)',
  `start_number` int(11) NOT NULL,
  `end_number` int(11) NOT NULL,
  `current_number` int(11) NOT NULL,
  `active` tinyint(4) DEFAULT 1 COMMENT '1:使用中, 0:停用/用罄',
  `sortorder` int(11) DEFAULT 0 COMMENT '配號優先權 (越大越優先)',
  `datec` datetime DEFAULT NULL,
  `tms` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `entity` int(11) DEFAULT 1,
  PRIMARY KEY (`rowid`),
  KEY `idx_taiwaneinvoice_track_lookup` (`year`, `period`, `active`, `sortorder`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. 客戶偏好設定：儲存客戶常用的載具或統編資訊
CREATE TABLE IF NOT EXISTS `llx_taiwaneinvoice_customer_pref` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `fk_soc` int(11) NOT NULL COMMENT '對應 llx_societe rowid',
  `default_inv_type` varchar(2) DEFAULT '3',
  `default_carrier_type` varchar(20) DEFAULT NULL,
  `default_carrier_id` varchar(64) DEFAULT NULL,
  `default_npoban` varchar(10) DEFAULT NULL,
  `tms` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`rowid`),
  UNIQUE KEY `uk_taiwaneinvoice_cust_soc` (`fk_soc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
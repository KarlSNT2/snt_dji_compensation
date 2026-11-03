<?php
/**
 * 2007-2026 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2026 PrestaShop SA
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */

$sql = array();

// Table des declarations clients
$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'dji_compensation_declaration` (
    `id_declaration` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_customer` int(11) UNSIGNED NOT NULL,
    `reference` varchar(32) NOT NULL,
    `xlsx_s3_key` varchar(512) DEFAULT NULL,
    `xlsx_original_filename` varchar(255) DEFAULT NULL,
    `has_mic_power_products` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
    `status` enum("draft","submitted","processing","registered","validated","rejected") NOT NULL DEFAULT "draft",
    `admin_notes` text,
    `date_add` datetime NOT NULL,
    `date_upd` datetime NOT NULL,
    PRIMARY KEY (`id_declaration`),
    KEY `id_customer` (`id_customer`),
    KEY `reference` (`reference`),
    KEY `status` (`status`),
    KEY `date_add` (`date_add`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

// Table des factures PDF
$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'dji_compensation_invoice` (
    `id_invoice` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_declaration` int(11) UNSIGNED NOT NULL,
    `s3_key` varchar(512) NOT NULL,
    `original_filename` varchar(255) NOT NULL,
    `file_size` int(11) UNSIGNED NOT NULL DEFAULT 0,
    `date_add` datetime NOT NULL,
    PRIMARY KEY (`id_invoice`),
    KEY `id_declaration` (`id_declaration`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

// Table des preuves de stock (images pour DJI Mic & Power)
$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'dji_compensation_stock_proof` (
    `id_stock_proof` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_declaration` int(11) UNSIGNED NOT NULL,
    `product_type` enum("mic","power") NOT NULL,
    `s3_key` varchar(512) NOT NULL,
    `original_filename` varchar(255) NOT NULL,
    `file_size` int(11) UNSIGNED NOT NULL DEFAULT 0,
    `date_add` datetime NOT NULL,
    PRIMARY KEY (`id_stock_proof`),
    KEY `id_declaration` (`id_declaration`),
    KEY `product_type` (`product_type`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

// Table de l'historique des actions sur une declaration
$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'dji_compensation_history` (
    `id_history` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_declaration` int(11) UNSIGNED NOT NULL,
    `actor_type` enum("customer","employee","system") NOT NULL DEFAULT "customer",
    `id_actor` int(11) UNSIGNED NOT NULL DEFAULT 0,
    `actor_name` varchar(255) DEFAULT NULL,
    `action` varchar(50) NOT NULL,
    `details` varchar(1000) DEFAULT NULL,
    `date_add` datetime NOT NULL,
    PRIMARY KEY (`id_history`),
    KEY `id_declaration` (`id_declaration`),
    KEY `date_add` (`date_add`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

// Table pour l'historique des templates admin
$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'dji_compensation_template_history` (
    `id_template` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `filename` varchar(255) NOT NULL,
    `original_filename` varchar(255) NOT NULL,
    `file_size` int(11) UNSIGNED NOT NULL DEFAULT 0,
    `is_active` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
    `id_employee` int(11) UNSIGNED DEFAULT NULL,
    `date_add` datetime NOT NULL,
    PRIMARY KEY (`id_template`),
    KEY `is_active` (`is_active`),
    KEY `date_add` (`date_add`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

// Table des telechargements du template par les clients
// Sert a detecter qu'un client depose une declaration batie sur un template obsolete
$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'dji_compensation_template_download` (
    `id_download` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_customer` int(11) UNSIGNED NOT NULL,
    `id_template` int(11) UNSIGNED DEFAULT NULL,
    `filename` varchar(255) NOT NULL,
    `date_add` datetime NOT NULL,
    PRIMARY KEY (`id_download`),
    KEY `id_customer` (`id_customer`, `date_add`),
    KEY `id_template` (`id_template`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

foreach ($sql as $query) {
    if (Db::getInstance()->execute($query) == false) {
        return false;
    }
}

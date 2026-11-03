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

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_2_0($module)
{
    $sql = [];

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

    foreach ($sql as $query) {
        if (Db::getInstance()->execute($query) == false) {
            return false;
        }
    }

    return true;
}

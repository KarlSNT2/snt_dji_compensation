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

/**
 * Journalise les telechargements du template par les clients, pour pouvoir
 * avertir au depot qu'une declaration repose sur une version obsolete.
 */
function upgrade_module_1_4_0($module)
{
    $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'dji_compensation_template_download` (
        `id_download` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_customer` int(11) UNSIGNED NOT NULL,
        `id_template` int(11) UNSIGNED DEFAULT NULL,
        `filename` varchar(255) NOT NULL,
        `date_add` datetime NOT NULL,
        PRIMARY KEY (`id_download`),
        KEY `id_customer` (`id_customer`, `date_add`),
        KEY `id_template` (`id_template`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

    return (bool) Db::getInstance()->execute($sql);
}

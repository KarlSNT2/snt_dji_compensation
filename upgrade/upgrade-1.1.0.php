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

function upgrade_module_1_1_0($module)
{
    $sql = [];

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

    foreach ($sql as $query) {
        if (Db::getInstance()->execute($query) == false) {
            return false;
        }
    }

    // Migrer le template actuel vers l'historique si existant
    $currentTemplate = Configuration::get('SNT_DJI_COMPENSATION_TEMPLATE_FILE');
    if ($currentTemplate) {
        $templatePath = _PS_MODULE_DIR_ . $module->name . '/uploads/templates/' . $currentTemplate;
        if (file_exists($templatePath)) {
            $fileSize = filesize($templatePath);
            Db::getInstance()->insert('dji_compensation_template_history', [
                'filename' => pSQL($currentTemplate),
                'original_filename' => pSQL($currentTemplate),
                'file_size' => (int) $fileSize,
                'is_active' => 1,
                'id_employee' => null,
                'date_add' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    return true;
}

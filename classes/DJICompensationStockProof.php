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

class DJICompensationStockProof extends ObjectModel
{
    const TYPE_MIC = 'mic';
    const TYPE_POWER = 'power';

    public $id_stock_proof;
    public $id_declaration;
    public $product_type;
    public $s3_key;
    public $original_filename;
    public $file_size;
    public $date_add;

    public static $definition = [
        'table' => 'dji_compensation_stock_proof',
        'primary' => 'id_stock_proof',
        'fields' => [
            'id_declaration' => [
                'type' => self::TYPE_INT,
                'validate' => 'isUnsignedId',
                'required' => true,
            ],
            'product_type' => [
                'type' => self::TYPE_STRING,
                'validate' => 'isGenericName',
                'required' => true,
                'size' => 10,
            ],
            's3_key' => [
                'type' => self::TYPE_STRING,
                'validate' => 'isGenericName',
                'required' => true,
                'size' => 512,
            ],
            'original_filename' => [
                'type' => self::TYPE_STRING,
                'validate' => 'isGenericName',
                'required' => true,
                'size' => 255,
            ],
            'file_size' => [
                'type' => self::TYPE_INT,
                'validate' => 'isUnsignedInt',
            ],
            'date_add' => [
                'type' => self::TYPE_DATE,
                'validate' => 'isDate',
            ],
        ],
    ];

    /**
     * Récupère les preuves de stock d'une déclaration
     */
    public static function getByDeclaration($idDeclaration, $productType = null)
    {
        $sql = new DbQuery();
        $sql->select('*');
        $sql->from(self::$definition['table']);
        $sql->where('id_declaration = ' . (int) $idDeclaration);

        if ($productType) {
            $sql->where('product_type = "' . pSQL($productType) . '"');
        }

        $sql->orderBy('product_type ASC, date_add ASC');

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Compte les preuves de stock d'une déclaration
     */
    public static function countByDeclaration($idDeclaration)
    {
        $sql = new DbQuery();
        $sql->select('COUNT(*)');
        $sql->from(self::$definition['table']);
        $sql->where('id_declaration = ' . (int) $idDeclaration);

        return (int) Db::getInstance()->getValue($sql);
    }

    /**
     * Supprime toutes les preuves de stock d'une déclaration
     */
    public static function deleteByDeclaration($idDeclaration)
    {
        return Db::getInstance()->delete(
            self::$definition['table'],
            'id_declaration = ' . (int) $idDeclaration
        );
    }

    /**
     * Retourne le libellé du type de produit
     */
    public function getProductTypeLabel()
    {
        $labels = [
            self::TYPE_MIC => 'DJI Mic',
            self::TYPE_POWER => 'DJI Power',
        ];

        return $labels[$this->product_type] ?? $this->product_type;
    }

    /**
     * Retourne la taille formatée
     */
    public function getFormattedSize()
    {
        $bytes = $this->file_size;

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' Mo';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' Ko';
        }

        return $bytes . ' octets';
    }

    /**
     * Retourne les types de produits disponibles
     */
    public static function getProductTypes()
    {
        return [
            self::TYPE_MIC => 'DJI Mic',
            self::TYPE_POWER => 'DJI Power',
        ];
    }
}

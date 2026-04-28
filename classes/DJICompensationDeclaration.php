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

class DJICompensationDeclaration extends ObjectModel
{
    const STATUS_DRAFT = 'draft';
    const STATUS_SUBMITTED = 'submitted';
    const STATUS_PROCESSING = 'processing';
    const STATUS_VALIDATED = 'validated';
    const STATUS_REJECTED = 'rejected';

    public $id_declaration;
    public $id_customer;
    public $reference;
    public $xlsx_s3_key;
    public $xlsx_original_filename;
    public $has_mic_power_products;
    public $status;
    public $admin_notes;
    public $date_add;
    public $date_upd;

    public static $definition = [
        'table' => 'dji_compensation_declaration',
        'primary' => 'id_declaration',
        'fields' => [
            'id_customer' => [
                'type' => self::TYPE_INT,
                'validate' => 'isUnsignedId',
                'required' => true,
            ],
            'reference' => [
                'type' => self::TYPE_STRING,
                'validate' => 'isGenericName',
                'required' => true,
                'size' => 32,
            ],
            'xlsx_s3_key' => [
                'type' => self::TYPE_STRING,
                'validate' => 'isGenericName',
                'size' => 512,
            ],
            'xlsx_original_filename' => [
                'type' => self::TYPE_STRING,
                'validate' => 'isGenericName',
                'size' => 255,
            ],
            'has_mic_power_products' => [
                'type' => self::TYPE_BOOL,
                'validate' => 'isBool',
            ],
            'status' => [
                'type' => self::TYPE_STRING,
                'validate' => 'isGenericName',
                'required' => true,
                'size' => 20,
            ],
            'admin_notes' => [
                'type' => self::TYPE_HTML,
                'validate' => 'isCleanHtml',
            ],
            'date_add' => [
                'type' => self::TYPE_DATE,
                'validate' => 'isDate',
            ],
            'date_upd' => [
                'type' => self::TYPE_DATE,
                'validate' => 'isDate',
            ],
        ],
    ];

    /**
     * Genere une reference unique pour la declaration
     */
    public static function generateReference()
    {
        return 'DJI-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
    }

    /**
     * Recupere les declarations d'un client
     */
    public static function getByCustomer($idCustomer)
    {
        $sql = new DbQuery();
        $sql->select('*');
        $sql->from(self::$definition['table']);
        $sql->where('id_customer = ' . (int) $idCustomer);
        $sql->orderBy('date_add DESC');

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Recupere la derniere declaration d'un client (brouillon en cours ou derniere soumise)
     */
    public static function getLastByCustomer($idCustomer)
    {
        // D'abord chercher un brouillon en cours
        $sql = new DbQuery();
        $sql->select('id_declaration');
        $sql->from(self::$definition['table']);
        $sql->where('id_customer = ' . (int) $idCustomer);
        $sql->where('status = "' . pSQL(self::STATUS_DRAFT) . '"');
        $sql->orderBy('date_add DESC');

        $id = Db::getInstance()->getValue($sql);

        if ($id) {
            return new self($id);
        }

        return null;
    }

    /**
     * Recupere toutes les declarations (admin)
     */
    public static function getAll($status = null, $limit = 50, $offset = 0)
    {
        $sql = new DbQuery();
        $sql->select('d.*, c.firstname, c.lastname, c.email, c.company');
        $sql->from(self::$definition['table'], 'd');
        $sql->leftJoin('customer', 'c', 'c.id_customer = d.id_customer');

        if ($status) {
            $sql->where('d.status = "' . pSQL($status) . '"');
        }

        $sql->orderBy('d.date_add DESC');
        $sql->limit($limit, $offset);

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Compte les declarations
     */
    public static function countAll($status = null)
    {
        $sql = new DbQuery();
        $sql->select('COUNT(*)');
        $sql->from(self::$definition['table']);

        if ($status) {
            $sql->where('status = "' . pSQL($status) . '"');
        }

        return (int) Db::getInstance()->getValue($sql);
    }

    /**
     * Compte les declarations d'un client
     */
    public static function countByCustomer($idCustomer)
    {
        $sql = new DbQuery();
        $sql->select('COUNT(*)');
        $sql->from(self::$definition['table']);
        $sql->where('id_customer = ' . (int) $idCustomer);

        return (int) Db::getInstance()->getValue($sql);
    }

    /**
     * Recupere les factures associees
     */
    public function getInvoices()
    {
        return DJICompensationInvoice::getByDeclaration($this->id);
    }

    /**
     * Recupere les preuves de stock associees
     */
    public function getStockProofs()
    {
        return DJICompensationStockProof::getByDeclaration($this->id);
    }

    /**
     * Retourne le libelle du statut
     */
    public function getStatusLabel()
    {
        $labels = [
            self::STATUS_DRAFT => 'Brouillon',
            self::STATUS_SUBMITTED => 'Soumise',
            self::STATUS_PROCESSING => 'En traitement',
            self::STATUS_VALIDATED => 'Validee',
            self::STATUS_REJECTED => 'Rejetee',
        ];

        return $labels[$this->status] ?? $this->status;
    }

    /**
     * Retourne la classe CSS du statut
     */
    public function getStatusBadgeClass()
    {
        $classes = [
            self::STATUS_DRAFT => 'secondary',
            self::STATUS_SUBMITTED => 'info',
            self::STATUS_PROCESSING => 'warning',
            self::STATUS_VALIDATED => 'success',
            self::STATUS_REJECTED => 'danger',
        ];

        return $classes[$this->status] ?? 'secondary';
    }

    /**
     * Verifie si la declaration peut etre modifiee
     */
    public function isEditable()
    {
        return in_array($this->status, [
            self::STATUS_DRAFT,
            self::STATUS_SUBMITTED,
            self::STATUS_PROCESSING,
        ]);
    }

    /**
     * Recupere une declaration par son ID et verifie l'appartenance au client
     */
    public static function getByIdAndCustomer($idDeclaration, $idCustomer)
    {
        $sql = new DbQuery();
        $sql->select('id_declaration');
        $sql->from(self::$definition['table']);
        $sql->where('id_declaration = ' . (int) $idDeclaration);
        $sql->where('id_customer = ' . (int) $idCustomer);

        $id = Db::getInstance()->getValue($sql);

        if ($id) {
            return new self($id);
        }

        return null;
    }

    /**
     * Retourne les statuts disponibles
     */
    public static function getStatuses()
    {
        return [
            self::STATUS_DRAFT => 'Brouillon',
            self::STATUS_SUBMITTED => 'Soumise',
            self::STATUS_PROCESSING => 'En traitement',
            self::STATUS_VALIDATED => 'Validee',
            self::STATUS_REJECTED => 'Rejetee',
        ];
    }
}

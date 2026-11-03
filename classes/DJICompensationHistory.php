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

class DJICompensationHistory extends ObjectModel
{
    // Types d'acteur
    const ACTOR_CUSTOMER = 'customer';
    const ACTOR_EMPLOYEE = 'employee';
    const ACTOR_SYSTEM = 'system';

    // Actions tracees
    const ACTION_DRAFT_CREATED = 'draft_created';
    const ACTION_DRAFT_UPDATED = 'draft_updated';
    const ACTION_XLSX_UPLOADED = 'xlsx_uploaded';
    const ACTION_INVOICE_ADDED = 'invoice_added';
    const ACTION_INVOICE_DELETED = 'invoice_deleted';
    const ACTION_STOCK_PROOF_ADDED = 'stock_proof_added';
    const ACTION_STOCK_PROOF_DELETED = 'stock_proof_deleted';
    const ACTION_SUBMITTED = 'submitted';
    const ACTION_RESUBMITTED = 'resubmitted';
    const ACTION_STATUS_CHANGED = 'status_changed';
    const ACTION_NOTES_UPDATED = 'notes_updated';

    public $id_history;
    public $id_declaration;
    public $actor_type;
    public $id_actor;
    public $actor_name;
    public $action;
    public $details;
    public $date_add;

    public static $definition = [
        'table' => 'dji_compensation_history',
        'primary' => 'id_history',
        'fields' => [
            'id_declaration' => [
                'type' => self::TYPE_INT,
                'validate' => 'isUnsignedId',
                'required' => true,
            ],
            'actor_type' => [
                'type' => self::TYPE_STRING,
                'validate' => 'isGenericName',
                'required' => true,
                'size' => 20,
            ],
            'id_actor' => [
                'type' => self::TYPE_INT,
                'validate' => 'isUnsignedInt',
            ],
            'actor_name' => [
                'type' => self::TYPE_STRING,
                'validate' => 'isGenericName',
                'size' => 255,
            ],
            'action' => [
                'type' => self::TYPE_STRING,
                'validate' => 'isGenericName',
                'required' => true,
                'size' => 50,
            ],
            'details' => [
                'type' => self::TYPE_STRING,
                'validate' => 'isCleanHtml',
                'size' => 1000,
            ],
            'date_add' => [
                'type' => self::TYPE_DATE,
                'validate' => 'isDate',
            ],
        ],
    ];

    /**
     * Enregistre une entree dans l'historique d'une declaration
     *
     * @param int $idDeclaration
     * @param string $action     Une des constantes ACTION_*
     * @param string|null $details Information complementaire (nom de fichier, transition de statut...)
     * @param string $actorType  Une des constantes ACTOR_*
     * @param int $idActor       ID du client ou de l'employe (0 si systeme)
     * @param string $actorName  Nom affiche de l'acteur
     * @return bool
     */
    public static function log($idDeclaration, $action, $details = null, $actorType = self::ACTOR_SYSTEM, $idActor = 0, $actorName = '')
    {
        if (!$idDeclaration) {
            return false;
        }

        $history = new self();
        $history->id_declaration = (int) $idDeclaration;
        $history->action = $action;
        $history->details = $details !== null ? Tools::substr($details, 0, 1000) : null;
        $history->actor_type = $actorType;
        $history->id_actor = (int) $idActor;
        $history->actor_name = $actorName ? Tools::substr($actorName, 0, 255) : '';

        return $history->save();
    }

    /**
     * Recupere l'historique d'une declaration (plus recent en premier)
     */
    public static function getByDeclaration($idDeclaration, $orderWay = 'DESC')
    {
        $orderWay = (Tools::strtoupper($orderWay) === 'ASC') ? 'ASC' : 'DESC';

        $sql = new DbQuery();
        $sql->select('*');
        $sql->from(self::$definition['table']);
        $sql->where('id_declaration = ' . (int) $idDeclaration);
        $sql->orderBy('date_add ' . $orderWay . ', id_history ' . $orderWay);

        $rows = Db::getInstance()->executeS($sql);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Supprime l'historique d'une declaration
     */
    public static function deleteByDeclaration($idDeclaration)
    {
        return Db::getInstance()->delete(
            self::$definition['table'],
            'id_declaration = ' . (int) $idDeclaration
        );
    }

    /**
     * Libelles humains des actions
     */
    public static function getActionLabels()
    {
        return [
            self::ACTION_DRAFT_CREATED => 'Creation du brouillon',
            self::ACTION_DRAFT_UPDATED => 'Enregistrement du brouillon',
            self::ACTION_XLSX_UPLOADED => 'Depot du fichier de declaration (XLSX)',
            self::ACTION_INVOICE_ADDED => 'Ajout d\'une facture',
            self::ACTION_INVOICE_DELETED => 'Suppression d\'une facture',
            self::ACTION_STOCK_PROOF_ADDED => 'Ajout d\'une photo de stock',
            self::ACTION_STOCK_PROOF_DELETED => 'Suppression d\'une photo de stock',
            self::ACTION_SUBMITTED => 'Soumission de la declaration',
            self::ACTION_RESUBMITTED => 'Mise a jour de la declaration',
            self::ACTION_STATUS_CHANGED => 'Changement de statut',
            self::ACTION_NOTES_UPDATED => 'Mise a jour des notes administrateur',
        ];
    }

    /**
     * Retourne le libelle d'une action
     */
    public static function getActionLabel($action)
    {
        $labels = self::getActionLabels();

        return $labels[$action] ?? $action;
    }

    /**
     * Retourne le nom d'icone Material Icons associe a une action (front)
     */
    public static function getActionIcon($action)
    {
        $icons = [
            self::ACTION_DRAFT_CREATED => 'note_add',
            self::ACTION_DRAFT_UPDATED => 'save',
            self::ACTION_XLSX_UPLOADED => 'description',
            self::ACTION_INVOICE_ADDED => 'picture_as_pdf',
            self::ACTION_INVOICE_DELETED => 'delete',
            self::ACTION_STOCK_PROOF_ADDED => 'add_photo_alternate',
            self::ACTION_STOCK_PROOF_DELETED => 'delete',
            self::ACTION_SUBMITTED => 'send',
            self::ACTION_RESUBMITTED => 'send',
            self::ACTION_STATUS_CHANGED => 'flag',
            self::ACTION_NOTES_UPDATED => 'comment',
        ];

        return $icons[$action] ?? 'history';
    }

    /**
     * Libelles humains des types d'acteur
     */
    public static function getActorTypeLabel($actorType)
    {
        $labels = [
            self::ACTOR_CUSTOMER => 'Client',
            self::ACTOR_EMPLOYEE => 'Administrateur',
            self::ACTOR_SYSTEM => 'Systeme (automatique)',
        ];

        return $labels[$actorType] ?? $actorType;
    }
}

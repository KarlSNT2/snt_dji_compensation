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

require_once _PS_MODULE_DIR_ . 'snt_dji_compensation/classes/DJICompensationDeclaration.php';
require_once _PS_MODULE_DIR_ . 'snt_dji_compensation/classes/DJICompensationInvoice.php';
require_once _PS_MODULE_DIR_ . 'snt_dji_compensation/classes/DJICompensationStockProof.php';
require_once _PS_MODULE_DIR_ . 'snt_dji_compensation/classes/DJICompensationHistory.php';

class AdminDJICompensationController extends ModuleAdminController
{
    public function __construct()
    {
        $this->table = 'dji_compensation_declaration';
        $this->className = 'DJICompensationDeclaration';
        $this->identifier = 'id_declaration';
        $this->bootstrap = true;
        $this->lang = false;
        $this->list_no_link = true;

        parent::__construct();

        $this->_select = 'c.firstname, c.lastname, c.email, c.company';
        $this->_join = 'LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON (c.id_customer = a.id_customer)';
        $this->_defaultOrderBy = 'date_add';
        $this->_defaultOrderWay = 'DESC';

        $this->fields_list = [
            'id_declaration' => [
                'title' => 'ID',
                'align' => 'center',
                'class' => 'fixed-width-xs',
            ],
            'reference' => [
                'title' => $this->l('Reference'),
            ],
            'lastname' => [
                'title' => $this->l('Client'),
                'filter_key' => 'c!lastname',
                'callback' => 'getCustomerName',
            ],
            'company' => [
                'title' => $this->l('Societe'),
                'filter_key' => 'c!company',
            ],
            'xlsx_original_filename' => [
                'title' => $this->l('Fichier'),
                'callback' => 'displayFileName',
            ],
            'status' => [
                'title' => $this->l('Statut'),
                'type' => 'select',
                'list' => DJICompensationDeclaration::getStatuses(),
                'filter_key' => 'a!status',
                'callback' => 'displayStatus',
            ],
            'date_add' => [
                'title' => $this->l('Creation'),
                'type' => 'datetime',
                'align' => 'center',
            ],
            'date_upd' => [
                'title' => $this->l('Derniere action'),
                'type' => 'datetime',
                'align' => 'center',
            ],
        ];

        $this->bulk_actions = [
            'delete' => [
                'text' => $this->l('Supprimer'),
                'confirm' => $this->l('Supprimer les elements selectionnes ?'),
                'icon' => 'icon-trash',
            ],
        ];

        $this->addRowAction('view');
        $this->addRowAction('delete');
    }

    public function getCustomerName($value, $row)
    {
        return $row['firstname'] . ' ' . $row['lastname'] . '<br><small>' . $row['email'] . '</small>';
    }

    public function displayFileName($value, $row)
    {
        if (empty($value)) {
            return '<span class="text-muted">-</span>';
        }
        return '<small>' . Tools::substr($value, 0, 30) . (Tools::strlen($value) > 30 ? '...' : '') . '</small>';
    }

    public function displayStatus($value, $row)
    {
        $classes = [
            'draft' => 'default',
            'submitted' => 'info',
            'processing' => 'warning',
            'registered' => 'default',
            'validated' => 'success',
            'rejected' => 'danger',
        ];
        $labels = DJICompensationDeclaration::getStatuses();
        $class = $classes[$value] ?? 'default';
        $label = $labels[$value] ?? $value;

        return '<span class="label label-' . $class . '">' . $label . '</span>';
    }

    public function renderView()
    {
        $declaration = new DJICompensationDeclaration((int) Tools::getValue('id_declaration'));

        if (!Validate::isLoadedObject($declaration)) {
            return $this->displayWarning($this->l('Declaration non trouvee.'));
        }

        $customer = new Customer($declaration->id_customer);
        $invoices = DJICompensationInvoice::getByDeclaration($declaration->id);
        $stockProofs = DJICompensationStockProof::getByDeclaration($declaration->id);

        $history = DJICompensationHistory::getByDeclaration($declaration->id, 'DESC');
        foreach ($history as &$row) {
            $row['action_label'] = DJICompensationHistory::getActionLabel($row['action']);
            $row['actor_type_label'] = DJICompensationHistory::getActorTypeLabel($row['actor_type']);
        }
        unset($row);

        $this->context->smarty->assign([
            'declaration' => $declaration,
            'customer' => $customer,
            'invoices' => $invoices,
            'stock_proofs' => $stockProofs,
            'history' => $history,
            'statuses' => DJICompensationDeclaration::getStatuses(),
            'back_link' => $this->context->link->getAdminLink('AdminDJICompensation'),
            'ajax_url' => $this->context->link->getAdminLink('AdminDJICompensation'),
        ]);

        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'snt_dji_compensation/views/templates/admin/declaration_view.tpl');
    }

    public function ajaxProcessUpdateStatus()
    {
        $idDeclaration = (int) Tools::getValue('id_declaration');
        $status = Tools::getValue('status');

        $declaration = new DJICompensationDeclaration($idDeclaration);
        if (!Validate::isLoadedObject($declaration)) {
            die(json_encode(['success' => false, 'message' => $this->l('Declaration non trouvee')]));
        }

        $validStatuses = array_keys(DJICompensationDeclaration::getStatuses());
        if (!in_array($status, $validStatuses)) {
            die(json_encode(['success' => false, 'message' => $this->l('Statut invalide')]));
        }

        $oldStatus = $declaration->status;
        $declaration->status = $status;
        if ($declaration->save()) {
            if ($oldStatus !== $status) {
                $labels = DJICompensationDeclaration::getStatuses();
                $details = ($labels[$oldStatus] ?? $oldStatus) . ' -> ' . ($labels[$status] ?? $status);
                $this->logEmployeeHistory($declaration->id, DJICompensationHistory::ACTION_STATUS_CHANGED, $details);
            }
            die(json_encode(['success' => true, 'message' => $this->l('Statut mis a jour')]));
        }

        die(json_encode(['success' => false, 'message' => $this->l('Erreur')]));
    }

    /**
     * Enregistre une action de l'employe dans l'historique d'une declaration
     */
    protected function logEmployeeHistory($idDeclaration, $action, $details = null)
    {
        $employee = $this->context->employee;
        $actorName = $employee ? trim($employee->firstname . ' ' . $employee->lastname) : '';

        DJICompensationHistory::log(
            $idDeclaration,
            $action,
            $details,
            DJICompensationHistory::ACTOR_EMPLOYEE,
            $employee ? (int) $employee->id : 0,
            $actorName
        );
    }

    public function ajaxProcessUpdateNotes()
    {
        $idDeclaration = (int) Tools::getValue('id_declaration');
        $notes = Tools::getValue('notes');

        $declaration = new DJICompensationDeclaration($idDeclaration);
        if (!Validate::isLoadedObject($declaration)) {
            die(json_encode(['success' => false, 'message' => $this->l('Declaration non trouvee')]));
        }

        $declaration->admin_notes = pSQL($notes);
        if ($declaration->save()) {
            die(json_encode(['success' => true, 'message' => $this->l('Notes mises a jour')]));
        }

        die(json_encode(['success' => false, 'message' => $this->l('Erreur')]));
    }

    public function ajaxProcessGetFileUrl()
    {
        $type = Tools::getValue('type');
        $id = (int) Tools::getValue('id');

        try {
            $module = Module::getInstanceByName('snt_dji_compensation');
            $s3Service = $module->getS3Service();
            $s3Key = null;

            switch ($type) {
                case 'xlsx':
                    $declaration = new DJICompensationDeclaration($id);
                    if (Validate::isLoadedObject($declaration)) {
                        $s3Key = $declaration->xlsx_s3_key;
                    }
                    break;
                case 'invoice':
                    $invoice = new DJICompensationInvoice($id);
                    if (Validate::isLoadedObject($invoice)) {
                        $s3Key = $invoice->s3_key;
                    }
                    break;
                case 'stock_proof':
                    $stockProof = new DJICompensationStockProof($id);
                    if (Validate::isLoadedObject($stockProof)) {
                        $s3Key = $stockProof->s3_key;
                    }
                    break;
            }

            if ($s3Key) {
                $url = $s3Service->getPresignedUrl($s3Key, 3600);
                die(json_encode(['success' => true, 'url' => $url]));
            }

            die(json_encode(['success' => false, 'message' => $this->l('Fichier non trouve')]));
        } catch (Exception $e) {
            die(json_encode(['success' => false, 'message' => $e->getMessage()]));
        }
    }
}

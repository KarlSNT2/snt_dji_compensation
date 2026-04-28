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

class Snt_dji_compensationDeclarationModuleFrontController extends ModuleFrontController
{
    public $auth = true;
    public $authRedirection = 'my-account';
    public $ssl = true;

    private $declaration;

    /**
     * Notifie une erreur d'upload par email si active
     */
    protected function notifyUploadError($errorType, $errorMessage, $filename = null)
    {
        if (!Configuration::get('SNT_DJI_COMPENSATION_NOTIFY_ERRORS')) {
            return;
        }

        DJICompensationMailService::logUploadError(
            $errorType,
            $errorMessage,
            $this->context->customer->id,
            $this->declaration ? $this->declaration->id : null
        );

        DJICompensationMailService::sendUploadErrorNotification(
            $this->context->customer->id,
            $errorType,
            $errorMessage,
            $filename,
            $this->declaration ? $this->declaration->id : null
        );
    }

    public function init()
    {
        parent::init();

        if (!$this->module->isLive()) {
            Tools::redirect('index.php?controller=my-account');
        }

        if (!$this->module->hasTemplate()) {
            $this->errors[] = $this->module->l('Le service de compensation n\'est pas disponible actuellement.', 'declaration');
            $this->redirectWithNotifications($this->context->link->getPageLink('my-account'));
        }

        // Verifier si on edite une declaration existante
        $editId = (int) Tools::getValue('edit');
        if ($editId) {
            $this->declaration = DJICompensationDeclaration::getByIdAndCustomer($editId, $this->context->customer->id);
            if (!$this->declaration || !$this->declaration->isEditable()) {
                $this->errors[] = $this->module->l('Cette declaration ne peut pas etre modifiee.', 'declaration');
                $this->declaration = null;
            }
        } else {
            // Recuperer le brouillon en cours ou null
            $this->declaration = DJICompensationDeclaration::getLastByCustomer($this->context->customer->id);
        }
    }

    public function initContent()
    {
        parent::initContent();

        // Traiter les actions AJAX
        if (Tools::getValue('ajax')) {
            $this->processAjaxAction();
            return;
        }

        if (Tools::isSubmit('submitDeclaration')) {
            $this->processDeclaration();
        }

        // Fichiers de la declaration en cours
        $invoices = [];
        $stockProofs = [];

        if ($this->declaration) {
            $invoices = DJICompensationInvoice::getByDeclaration($this->declaration->id);
            $stockProofs = DJICompensationStockProof::getByDeclaration($this->declaration->id);
        }

        // Historique des declarations
        $declarationHistory = DJICompensationDeclaration::getByCustomer($this->context->customer->id);
        foreach ($declarationHistory as &$item) {
            $decl = new DJICompensationDeclaration($item['id_declaration']);
            $item['status_label'] = $decl->getStatusLabel();
            $item['status_class'] = $decl->getStatusBadgeClass();
        }

        $maxFileSize = (int) Configuration::get('SNT_DJI_COMPENSATION_MAX_FILE_SIZE');

        $this->context->smarty->assign([
            'declaration' => $this->declaration,
            'declaration_history' => $declarationHistory,
            'invoices' => $invoices,
            'stock_proofs' => $stockProofs,
            'has_template' => $this->module->hasTemplate(),
            'download_link' => $this->context->link->getModuleLink($this->module->name, 'download'),
            'my_account_link' => $this->context->link->getPageLink('my-account'),
            'max_file_size' => $maxFileSize,
            'max_file_size_bytes' => $maxFileSize * 1024 * 1024,
            'max_invoices' => (int) Configuration::get('SNT_DJI_COMPENSATION_MAX_INVOICES'),
            'max_stock_proofs' => (int) Configuration::get('SNT_DJI_COMPENSATION_MAX_STOCK_PROOFS'),
            'product_types' => DJICompensationStockProof::getProductTypes(),
            'token' => Tools::getToken(false),
            'xlsx_editable' => !$this->declaration || $this->declaration->isXlsxEditable(),
        ]);

        $this->setTemplate('module:snt_dji_compensation/views/templates/front/declaration.tpl');
    }

    protected function processDeclaration()
    {
        if (Tools::getToken(false) !== Tools::getValue('token')) {
            $this->errors[] = $this->module->l('Session expiree, veuillez reessayer.', 'declaration');
            return;
        }

        // Creer une nouvelle declaration si pas de brouillon
        if (!$this->declaration) {
            $this->declaration = new DJICompensationDeclaration();
            $this->declaration->id_customer = $this->context->customer->id;
            $this->declaration->reference = DJICompensationDeclaration::generateReference();
            $this->declaration->status = DJICompensationDeclaration::STATUS_DRAFT;
        }

        $hasMicPowerProducts = (bool) Tools::getValue('has_mic_power_products');
        $this->declaration->has_mic_power_products = $hasMicPowerProducts;

        try {
            $s3Service = $this->module->getS3Service();
        } catch (Exception $e) {
            $errorMsg = 'Erreur de configuration du stockage: ' . $e->getMessage();
            $this->errors[] = $this->module->l('Erreur de configuration du stockage.', 'declaration');
            $this->notifyUploadError('s3', $errorMsg);
            return;
        }

        if (!$this->declaration->save()) {
            $errorMsg = 'Erreur lors de la sauvegarde de la declaration';
            $this->errors[] = $this->module->l('Erreur lors de la sauvegarde.', 'declaration');
            $this->notifyUploadError('declaration', $errorMsg);
            return;
        }

        // Traiter XLSX (seulement si brouillon)
        if (isset($_FILES['xlsx_file']) && $_FILES['xlsx_file']['error'] === UPLOAD_ERR_OK) {
            if (!$this->declaration->isXlsxEditable()) {
                $this->errors[] = $this->module->l('Le fichier XLSX ne peut plus etre modifie apres soumission.', 'declaration');
                return;
            }
            if (!$this->processXlsxUpload($s3Service)) {
                return;
            }
        }

        // Traiter factures PDF
        if (isset($_FILES['invoices']) && is_array($_FILES['invoices']['name'])) {
            $this->processInvoicesUpload($s3Service);
        }

        // Traiter photos stock
        if ($hasMicPowerProducts && isset($_FILES['stock_proofs']) && is_array($_FILES['stock_proofs']['name'])) {
            $this->processStockProofsUpload($s3Service);
        }

        // Soumettre si demande
        if (Tools::getValue('action') === 'submit') {
            if (empty($this->declaration->xlsx_s3_key)) {
                $this->errors[] = $this->module->l('Veuillez televerser le fichier de declaration XLSX.', 'declaration');
                return;
            }

            if (DJICompensationInvoice::countByDeclaration($this->declaration->id) === 0) {
                $this->errors[] = $this->module->l('Veuillez televerser au moins une facture PDF.', 'declaration');
                return;
            }

            if ($hasMicPowerProducts && DJICompensationStockProof::countByDeclaration($this->declaration->id) === 0) {
                $this->errors[] = $this->module->l('Veuillez televerser des photos de stock pour les produits DJI Mic/Power.', 'declaration');
                return;
            }

            $wasAlreadySubmitted = $this->declaration->status !== DJICompensationDeclaration::STATUS_DRAFT;
            $this->declaration->status = DJICompensationDeclaration::STATUS_SUBMITTED;
            $this->declaration->save();

            // Reset pour permettre une nouvelle declaration
            $this->declaration = null;

            if ($wasAlreadySubmitted) {
                $this->success[] = $this->module->l('Votre declaration a ete mise a jour avec succes !', 'declaration');
            } else {
                $this->success[] = $this->module->l('Votre declaration a ete soumise avec succes !', 'declaration');
            }
        } else {
            $this->success[] = $this->module->l('Brouillon enregistre.', 'declaration');
        }

        $this->redirectWithNotifications($this->context->link->getModuleLink($this->module->name, 'declaration'));
    }

    protected function processAjaxAction()
    {
        header('Content-Type: application/json');

        $action = Tools::getValue('action');
        $token = Tools::getValue('token');

        if (Tools::getToken(false) !== $token) {
            die(json_encode(['success' => false, 'message' => 'Session expiree']));
        }

        if (!$this->declaration || !$this->declaration->isEditable()) {
            die(json_encode(['success' => false, 'message' => 'Declaration non modifiable']));
        }

        try {
            $s3Service = $this->module->getS3Service();
        } catch (Exception $e) {
            die(json_encode(['success' => false, 'message' => 'Erreur de configuration']));
        }

        switch ($action) {
            case 'deleteInvoice':
                $this->ajaxDeleteInvoice($s3Service);
                break;

            case 'deleteStockProof':
                $this->ajaxDeleteStockProof($s3Service);
                break;

            default:
                die(json_encode(['success' => false, 'message' => 'Action inconnue']));
        }
    }

    protected function ajaxDeleteInvoice($s3Service)
    {
        $idInvoice = (int) Tools::getValue('id_invoice');

        $invoice = DJICompensationInvoice::getByIdAndDeclaration($idInvoice, $this->declaration->id);
        if (!$invoice) {
            die(json_encode(['success' => false, 'message' => 'Facture non trouvee']));
        }

        if ($invoice->deleteWithS3($s3Service)) {
            $this->markDeclarationAsModified();
            die(json_encode([
                'success' => true,
                'message' => 'Facture supprimee',
                'count' => DJICompensationInvoice::countByDeclaration($this->declaration->id),
            ]));
        }

        die(json_encode(['success' => false, 'message' => 'Erreur lors de la suppression']));
    }

    protected function ajaxDeleteStockProof($s3Service)
    {
        $idStockProof = (int) Tools::getValue('id_stock_proof');

        $stockProof = DJICompensationStockProof::getByIdAndDeclaration($idStockProof, $this->declaration->id);
        if (!$stockProof) {
            die(json_encode(['success' => false, 'message' => 'Photo non trouvee']));
        }

        if ($stockProof->deleteWithS3($s3Service)) {
            $this->markDeclarationAsModified();
            die(json_encode([
                'success' => true,
                'message' => 'Photo supprimee',
                'count' => DJICompensationStockProof::countByDeclaration($this->declaration->id),
            ]));
        }

        die(json_encode(['success' => false, 'message' => 'Erreur lors de la suppression']));
    }

    protected function markDeclarationAsModified()
    {
        if ($this->declaration->status !== DJICompensationDeclaration::STATUS_DRAFT) {
            $this->declaration->status = DJICompensationDeclaration::STATUS_SUBMITTED;
            $this->declaration->save();
        }
    }

    protected function processXlsxUpload($s3Service)
    {
        $file = $_FILES['xlsx_file'];
        $maxSize = (int) Configuration::get('SNT_DJI_COMPENSATION_MAX_FILE_SIZE') * 1024 * 1024;

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($extension !== 'xlsx') {
            $this->errors[] = $this->module->l('Le fichier doit etre au format XLSX.', 'declaration');
            return false;
        }

        if ($file['size'] > $maxSize) {
            $this->errors[] = $this->module->l('Le fichier depasse la taille maximale.', 'declaration');
            return false;
        }

        $s3Key = DJICompensationS3Service::generateS3Key(
            $this->context->customer->id,
            $this->declaration->id,
            'xlsx',
            $file['name']
        );

        if (!$s3Service->upload($file['tmp_name'], $s3Key, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')) {
            $errorMsg = 'Echec upload S3 du fichier XLSX';
            $this->errors[] = $this->module->l('Erreur lors du televersement du fichier XLSX.', 'declaration');
            $this->notifyUploadError('xlsx', $errorMsg, $file['name']);
            return false;
        }

        $this->declaration->xlsx_s3_key = $s3Key;
        $this->declaration->xlsx_original_filename = $file['name'];
        if (!$this->declaration->save()) {
            $errorMsg = 'Echec sauvegarde declaration apres upload XLSX';
            $this->notifyUploadError('xlsx', $errorMsg, $file['name']);
        }

        return true;
    }

    protected function processInvoicesUpload($s3Service)
    {
        $files = $_FILES['invoices'];
        $maxSize = (int) Configuration::get('SNT_DJI_COMPENSATION_MAX_FILE_SIZE') * 1024 * 1024;
        $maxInvoices = (int) Configuration::get('SNT_DJI_COMPENSATION_MAX_INVOICES');
        $currentCount = DJICompensationInvoice::countByDeclaration($this->declaration->id);

        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK || empty($files['name'][$i])) {
                continue;
            }

            if ($currentCount >= $maxInvoices) {
                $this->errors[] = sprintf($this->module->l('Maximum %d factures.', 'declaration'), $maxInvoices);
                break;
            }

            $extension = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            if ($extension !== 'pdf') {
                $this->errors[] = $files['name'][$i] . ' : ' . $this->module->l('doit etre un PDF.', 'declaration');
                continue;
            }

            if ($files['size'][$i] > $maxSize) {
                $this->errors[] = $files['name'][$i] . ' : ' . $this->module->l('trop volumineux.', 'declaration');
                continue;
            }

            $uniqueName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $files['name'][$i]);
            $s3Key = DJICompensationS3Service::generateS3Key(
                $this->context->customer->id,
                $this->declaration->id,
                'invoice',
                $uniqueName
            );

            if ($s3Service->upload($files['tmp_name'][$i], $s3Key, 'application/pdf')) {
                $invoice = new DJICompensationInvoice();
                $invoice->id_declaration = $this->declaration->id;
                $invoice->s3_key = $s3Key;
                $invoice->original_filename = $files['name'][$i];
                $invoice->file_size = $files['size'][$i];
                if (!$invoice->save()) {
                    $errorMsg = 'Echec sauvegarde facture en base de donnees';
                    $this->notifyUploadError('invoice', $errorMsg, $files['name'][$i]);
                } else {
                    $currentCount++;
                }
            } else {
                $errorMsg = 'Echec upload S3 de la facture PDF';
                $this->errors[] = $files['name'][$i] . ' : ' . $this->module->l('erreur lors du televersement.', 'declaration');
                $this->notifyUploadError('invoice', $errorMsg, $files['name'][$i]);
            }
        }
    }

    protected function processStockProofsUpload($s3Service)
    {
        $files = $_FILES['stock_proofs'];
        $productTypes = Tools::getValue('stock_proof_types', []);
        $maxSize = (int) Configuration::get('SNT_DJI_COMPENSATION_MAX_FILE_SIZE') * 1024 * 1024;
        $maxStockProofs = (int) Configuration::get('SNT_DJI_COMPENSATION_MAX_STOCK_PROOFS');
        $currentCount = DJICompensationStockProof::countByDeclaration($this->declaration->id);
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK || empty($files['name'][$i])) {
                continue;
            }

            if ($currentCount >= $maxStockProofs) {
                $this->errors[] = sprintf($this->module->l('Maximum %d photos.', 'declaration'), $maxStockProofs);
                break;
            }

            $extension = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($extension, $allowedExtensions)) {
                $this->errors[] = $files['name'][$i] . ' : ' . $this->module->l('format non accepte.', 'declaration');
                continue;
            }

            if ($files['size'][$i] > $maxSize) {
                $this->errors[] = $files['name'][$i] . ' : ' . $this->module->l('trop volumineux.', 'declaration');
                continue;
            }

            $productType = $productTypes[$i] ?? DJICompensationStockProof::TYPE_MIC;
            if (!in_array($productType, [DJICompensationStockProof::TYPE_MIC, DJICompensationStockProof::TYPE_POWER])) {
                $productType = DJICompensationStockProof::TYPE_MIC;
            }

            $uniqueName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $files['name'][$i]);
            $s3Key = DJICompensationS3Service::generateS3Key(
                $this->context->customer->id,
                $this->declaration->id,
                'stock_proof',
                $uniqueName
            );

            $mimeType = mime_content_type($files['tmp_name'][$i]);
            if ($s3Service->upload($files['tmp_name'][$i], $s3Key, $mimeType)) {
                $stockProof = new DJICompensationStockProof();
                $stockProof->id_declaration = $this->declaration->id;
                $stockProof->product_type = $productType;
                $stockProof->s3_key = $s3Key;
                $stockProof->original_filename = $files['name'][$i];
                $stockProof->file_size = $files['size'][$i];
                if (!$stockProof->save()) {
                    $errorMsg = 'Echec sauvegarde photo de stock en base de donnees';
                    $this->notifyUploadError('stock_proof', $errorMsg, $files['name'][$i]);
                } else {
                    $currentCount++;
                }
            } else {
                $errorMsg = 'Echec upload S3 de la photo de stock';
                $this->errors[] = $files['name'][$i] . ' : ' . $this->module->l('erreur lors du televersement.', 'declaration');
                $this->notifyUploadError('stock_proof', $errorMsg, $files['name'][$i]);
            }
        }
    }

    public function getBreadcrumbLinks()
    {
        $breadcrumb = parent::getBreadcrumbLinks();

        $breadcrumb['links'][] = [
            'title' => $this->trans('My account', [], 'Shop.Theme.Customeraccount'),
            'url' => $this->context->link->getPageLink('my-account'),
        ];

        $breadcrumb['links'][] = [
            'title' => $this->module->l('Compensation DJI', 'declaration'),
            'url' => '',
        ];

        return $breadcrumb;
    }
}

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

class Snt_dji_compensationApiModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $ajax = true;

    public function init()
    {
        parent::init();
        header('Content-Type: application/json; charset=utf-8');
    }

    public function initContent()
    {
        // Verifier la cle API
        if (!$this->validateApiKey()) {
            $this->jsonResponse(['error' => 'Unauthorized', 'message' => 'Invalid or missing API key'], 401);
            return;
        }

        $action = Tools::getValue('action', 'customers');

        switch ($action) {
            case 'customers':
                $this->getCustomers();
                break;
            case 'customer':
                $this->getCustomer();
                break;
            case 'declarations':
                $this->getDeclarations();
                break;
            case 'stats':
                $this->getStats();
                break;
            case 'template':
                $this->getTemplate();
                break;
            default:
                $this->jsonResponse(['error' => 'Not Found', 'message' => 'Unknown action'], 404);
        }
    }

    /**
     * Valide la cle API
     */
    protected function validateApiKey()
    {
        $configuredKey = Configuration::get('SNT_DJI_COMPENSATION_API_KEY');
        if (empty($configuredKey)) {
            return false;
        }

        // Accepter la cle via header ou parametre GET
        $providedKey = null;

        // Header Authorization: Bearer <key>
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;
        if ($authHeader) {
            if (strpos($authHeader, 'Bearer ') === 0) {
                $providedKey = substr($authHeader, 7);
            } else {
                $providedKey = $authHeader;
            }
        }

        // Fallback sur parametre GET
        if (!$providedKey) {
            $providedKey = Tools::getValue('api_key');
        }

        return $providedKey && hash_equals($configuredKey, $providedKey);
    }

    /**
     * Retourne tous les clients ayant des declarations
     */
    protected function getCustomers()
    {
        $onlyWithDeclarations = (bool) Tools::getValue('only_with_declarations', true);
        $limit = (int) Tools::getValue('limit', 0);
        $offset = (int) Tools::getValue('offset', 0);

        $sql = new DbQuery();
        $sql->select('DISTINCT c.id_customer, c.firstname, c.lastname, c.email, c.company, c.siret, c.date_add as customer_since');
        $sql->from('customer', 'c');

        if ($onlyWithDeclarations) {
            $sql->innerJoin('dji_compensation_declaration', 'd', 'd.id_customer = c.id_customer');
        }

        $sql->where('c.deleted = 0');
        $sql->where('c.active = 1');
        $sql->orderBy('c.id_customer ASC');

        if ($limit > 0) {
            $sql->limit($limit, $offset);
        }

        $customers = Db::getInstance()->executeS($sql);

        // Ajouter le nombre de declarations par client
        foreach ($customers as &$customer) {
            $customer['id_customer'] = (int) $customer['id_customer'];
            $customer['declarations_count'] = (int) DJICompensationDeclaration::countByCustomer($customer['id_customer']);
            $customer['s3_path'] = 'declarations/' . $customer['id_customer'] . '/';
        }

        // Compter le total
        $sqlCount = new DbQuery();
        $sqlCount->select('COUNT(DISTINCT c.id_customer)');
        $sqlCount->from('customer', 'c');
        if ($onlyWithDeclarations) {
            $sqlCount->innerJoin('dji_compensation_declaration', 'd', 'd.id_customer = c.id_customer');
        }
        $sqlCount->where('c.deleted = 0');
        $sqlCount->where('c.active = 1');
        $total = (int) Db::getInstance()->getValue($sqlCount);

        $this->jsonResponse([
            'success' => true,
            'total' => $total,
            'count' => count($customers),
            'offset' => $offset,
            'customers' => $customers,
        ]);
    }

    /**
     * Retourne un client specifique par ID
     */
    protected function getCustomer()
    {
        $idCustomer = (int) Tools::getValue('id_customer');

        if (!$idCustomer) {
            $this->jsonResponse(['error' => 'Bad Request', 'message' => 'id_customer is required'], 400);
            return;
        }

        $customer = new Customer($idCustomer);
        if (!Validate::isLoadedObject($customer) || $customer->deleted) {
            $this->jsonResponse(['error' => 'Not Found', 'message' => 'Customer not found'], 404);
            return;
        }

        // Recuperer les declarations du client
        $declarations = DJICompensationDeclaration::getByCustomer($idCustomer);
        $formattedDeclarations = [];

        foreach ($declarations as $decl) {
            $declaration = new DJICompensationDeclaration($decl['id_declaration']);
            $formattedDeclarations[] = [
                'id_declaration' => (int) $decl['id_declaration'],
                'reference' => $decl['reference'],
                'status' => $decl['status'],
                'status_label' => $declaration->getStatusLabel(),
                'has_mic_power_products' => (bool) $decl['has_mic_power_products'],
                'xlsx_filename' => $decl['xlsx_original_filename'],
                's3_path' => 'declarations/' . $idCustomer . '/' . $decl['id_declaration'] . '/',
                'date_add' => $decl['date_add'],
                'date_upd' => $decl['date_upd'],
            ];
        }

        $this->jsonResponse([
            'success' => true,
            'customer' => [
                'id_customer' => (int) $customer->id,
                'firstname' => $customer->firstname,
                'lastname' => $customer->lastname,
                'email' => $customer->email,
                'company' => $customer->company,
                'siret' => $customer->siret,
                'customer_since' => $customer->date_add,
                's3_path' => 'declarations/' . $customer->id . '/',
                'declarations_count' => count($declarations),
                'declarations' => $formattedDeclarations,
            ],
        ]);
    }

    /**
     * Retourne toutes les declarations
     */
    protected function getDeclarations()
    {
        $status = Tools::getValue('status');
        $idCustomer = (int) Tools::getValue('id_customer', 0);
        $limit = (int) Tools::getValue('limit', 50);
        $offset = (int) Tools::getValue('offset', 0);

        $sql = new DbQuery();
        $sql->select('d.*, c.firstname, c.lastname, c.email, c.company');
        $sql->from('dji_compensation_declaration', 'd');
        $sql->leftJoin('customer', 'c', 'c.id_customer = d.id_customer');

        if ($status && in_array($status, array_keys(DJICompensationDeclaration::getStatuses()))) {
            $sql->where('d.status = "' . pSQL($status) . '"');
        }

        if ($idCustomer > 0) {
            $sql->where('d.id_customer = ' . $idCustomer);
        }

        $sql->orderBy('d.date_add DESC');
        $sql->limit($limit, $offset);

        $declarations = Db::getInstance()->executeS($sql);

        foreach ($declarations as &$decl) {
            $decl['id_declaration'] = (int) $decl['id_declaration'];
            $decl['id_customer'] = (int) $decl['id_customer'];
            $decl['has_mic_power_products'] = (bool) $decl['has_mic_power_products'];
            $decl['s3_path'] = 'declarations/' . $decl['id_customer'] . '/' . $decl['id_declaration'] . '/';

            $declaration = new DJICompensationDeclaration($decl['id_declaration']);
            $decl['status_label'] = $declaration->getStatusLabel();
            $decl['invoices_count'] = (int) DJICompensationInvoice::countByDeclaration($decl['id_declaration']);
            $decl['stock_proofs_count'] = (int) DJICompensationStockProof::countByDeclaration($decl['id_declaration']);
        }

        // Total
        $total = DJICompensationDeclaration::countAll($status);

        $this->jsonResponse([
            'success' => true,
            'total' => $total,
            'count' => count($declarations),
            'offset' => $offset,
            'declarations' => $declarations,
        ]);
    }

    /**
     * Retourne des statistiques
     */
    protected function getStats()
    {
        $stats = [
            'total_declarations' => DJICompensationDeclaration::countAll(),
            'by_status' => [],
            'total_customers' => 0,
        ];

        foreach (DJICompensationDeclaration::getStatuses() as $status => $label) {
            $stats['by_status'][$status] = [
                'label' => $label,
                'count' => DJICompensationDeclaration::countAll($status),
            ];
        }

        // Nombre de clients avec declarations
        $sql = new DbQuery();
        $sql->select('COUNT(DISTINCT id_customer)');
        $sql->from('dji_compensation_declaration');
        $stats['total_customers'] = (int) Db::getInstance()->getValue($sql);

        $this->jsonResponse([
            'success' => true,
            'stats' => $stats,
        ]);
    }

    /**
     * Retourne le contenu du fichier template XLSX des promotions
     */
    protected function getTemplate()
    {
        $module = Module::getInstanceByName('snt_dji_compensation');

        if (!$module->hasTemplate()) {
            $this->jsonResponse([
                'error' => 'Not Found',
                'message' => 'Aucun fichier template configure',
            ], 404);
            return;
        }

        $templatePath = $module->getXlsxTemplatePath();
        $filename = Configuration::get('SNT_DJI_COMPENSATION_TEMPLATE_FILE');

        // Parametres
        $sheetIndex = (int) Tools::getValue('sheet', 1);
        $sheetName = Tools::getValue('sheet_name', '');
        $headersRow = (int) Tools::getValue('headers_row', 1);
        $limit = (int) Tools::getValue('limit', 0);
        $offset = (int) Tools::getValue('offset', 0);

        try {
            require_once _PS_MODULE_DIR_ . 'snt_dji_compensation/classes/DJICompensationXlsxReader.php';

            $reader = new DJICompensationXlsxReader($templatePath);
            $sheets = $reader->getSheets();

            // Lire par nom ou par index
            if (!empty($sheetName)) {
                $result = $reader->readSheetByName($sheetName, $headersRow, $limit, $offset);
                $currentSheet = $sheetName;
            } else {
                $result = $reader->readSheet($sheetIndex, $headersRow, $limit, $offset);
                $currentSheet = isset($sheets[$sheetIndex - 1]) ? $sheets[$sheetIndex - 1]['name'] : 'Sheet' . $sheetIndex;
            }

            $this->jsonResponse([
                'success' => true,
                'filename' => $filename,
                'sheet' => $currentSheet,
                'available_sheets' => array_column($sheets, 'name'),
                'headers_row' => $headersRow,
                'total_rows' => $result['total_rows'],
                'returned_rows' => count($result['data']),
                'offset' => $offset,
                'headers' => $result['headers'],
                'data' => $result['data'],
            ]);
        } catch (Exception $e) {
            $this->jsonResponse([
                'error' => 'Error',
                'message' => 'Erreur lors de la lecture du fichier: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Envoie une reponse JSON
     */
    protected function jsonResponse($data, $httpCode = 200)
    {
        http_response_code($httpCode);
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

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
            case 'declarations-to-process':
                $this->getDeclarationsToProcess();
                break;
            case 'declaration-xlsx':
                $this->getDeclarationXlsx();
                break;
            case 'invoices-urls':
                $this->getInvoicesUrls();
                break;
            case 'update-status':
                $this->updateDeclarationStatus();
                break;
            case 'declarations-summary':
                $this->getDeclarationsSummary();
                break;
            case 'declarations-sn-summary':
                $this->getDeclarationsSnSummary();
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
     * Retourne les declarations a traiter (status=submitted)
     */
    protected function getDeclarationsToProcess()
    {
        $limit = (int) Tools::getValue('limit', 50);
        $offset = (int) Tools::getValue('offset', 0);

        $sql = new DbQuery();
        $sql->select('d.*, c.firstname, c.lastname, c.email, c.company');
        $sql->from('dji_compensation_declaration', 'd');
        $sql->leftJoin('customer', 'c', 'c.id_customer = d.id_customer');
        $sql->where('d.status = "submitted"');
        $sql->orderBy('d.date_add ASC');
        $sql->limit($limit, $offset);

        $declarations = Db::getInstance()->executeS($sql);

        foreach ($declarations as &$decl) {
            $decl['id_declaration'] = (int) $decl['id_declaration'];
            $decl['id_customer'] = (int) $decl['id_customer'];
            $decl['has_mic_power_products'] = (bool) $decl['has_mic_power_products'];
            $decl['invoices_count'] = (int) DJICompensationInvoice::countByDeclaration($decl['id_declaration']);
            $decl['stock_proofs_count'] = (int) DJICompensationStockProof::countByDeclaration($decl['id_declaration']);
        }

        $total = DJICompensationDeclaration::countAll('submitted');

        $this->jsonResponse([
            'success' => true,
            'total' => $total,
            'count' => count($declarations),
            'offset' => $offset,
            'declarations' => $declarations,
        ]);
    }

    /**
     * Retourne le contenu parse du XLSX d'une declaration (lignes declarees uniquement)
     */
    protected function getDeclarationXlsx()
    {
        $idDeclaration = (int) Tools::getValue('id_declaration');

        if (!$idDeclaration) {
            $this->jsonResponse(['error' => 'Bad Request', 'message' => 'id_declaration is required'], 400);
            return;
        }

        $declaration = new DJICompensationDeclaration($idDeclaration);
        if (!Validate::isLoadedObject($declaration)) {
            $this->jsonResponse(['error' => 'Not Found', 'message' => 'Declaration not found'], 404);
            return;
        }

        if (empty($declaration->xlsx_s3_key)) {
            $this->jsonResponse(['error' => 'Not Found', 'message' => 'No XLSX file for this declaration'], 404);
            return;
        }

        try {
            require_once _PS_MODULE_DIR_ . 'snt_dji_compensation/classes/DJICompensationS3Service.php';
            require_once _PS_MODULE_DIR_ . 'snt_dji_compensation/classes/DJICompensationXlsxReader.php';

            $s3 = new DJICompensationS3Service();
            $xlsxContent = $s3->get($declaration->xlsx_s3_key);

            if ($xlsxContent === false) {
                $this->jsonResponse(['error' => 'Error', 'message' => 'Unable to download XLSX from S3'], 500);
                return;
            }

            $tempFile = tempnam(sys_get_temp_dir(), 'dji_xlsx_');
            file_put_contents($tempFile, $xlsxContent);

            $reader = new DJICompensationXlsxReader($tempFile);
            $result = $reader->readSheet(1, 1);

            unlink($tempFile);

            $declaredLines = [];
            $aliases = $this->getColumnAliases();

            foreach ($result['data'] as $row) {
                $norm = $this->normalizeRow($row);
                $qtyRaw = $this->pickColumn($norm, $aliases['quantite']);
                $qty = $qtyRaw !== null ? trim((string) $qtyRaw) : '';
                if ($qty !== '' && $qty != 0) {
                    $declaredLines[] = [
                        'type_promotion' => $this->pickColumn($norm, $aliases['type_promotion']),
                        'reference_article' => $this->pickColumn($norm, $aliases['reference_article']),
                        'produit' => $this->pickColumn($norm, $aliases['produit']),
                        'ean' => $this->pickColumn($norm, $aliases['ean']),
                        'quantite_declaree' => (int) $qty,
                        'prix_vente_ttc' => $this->parseNumber($this->pickColumn($norm, $aliases['prix_vente_ttc'])),
                        'prix_promo_ttc' => $this->parseNumber($this->pickColumn($norm, $aliases['prix_promo_ttc'])),
                        'compensation_ht' => $this->parseNumber($this->pickColumn($norm, $aliases['compensation_ht'])),
                        'montant_avoir' => $this->parseNumber($this->pickColumn($norm, $aliases['montant_avoir'])),
                        'date_debut' => $this->excelDateToISO($this->pickColumn($norm, $aliases['date_debut'])),
                        'date_fin' => $this->excelDateToISO($this->pickColumn($norm, $aliases['date_fin'])),
                        'date_butoir' => $this->excelDateToISO($this->pickColumn($norm, $aliases['date_butoir'])),
                        'numero_serie' => $this->pickColumn($norm, $aliases['numero_serie']),
                        'reference_avoir' => $this->pickColumn($norm, $aliases['reference_avoir']),
                    ];
                }
            }

            $this->jsonResponse([
                'success' => true,
                'id_declaration' => $idDeclaration,
                'id_customer' => (int) $declaration->id_customer,
                'reference' => $declaration->reference,
                'total_rows' => $result['total_rows'],
                'declared_lines_count' => count($declaredLines),
                'declared_lines' => $declaredLines,
            ]);
        } catch (Exception $e) {
            $this->jsonResponse([
                'error' => 'Error',
                'message' => 'Error parsing XLSX: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retourne les URLs S3 pre-signees pour les factures d'une declaration
     */
    protected function getInvoicesUrls()
    {
        $idDeclaration = (int) Tools::getValue('id_declaration');
        $expiresIn = (int) Tools::getValue('expires_in', 3600);

        if (!$idDeclaration) {
            $this->jsonResponse(['error' => 'Bad Request', 'message' => 'id_declaration is required'], 400);
            return;
        }

        $declaration = new DJICompensationDeclaration($idDeclaration);
        if (!Validate::isLoadedObject($declaration)) {
            $this->jsonResponse(['error' => 'Not Found', 'message' => 'Declaration not found'], 404);
            return;
        }

        $invoices = DJICompensationInvoice::getByDeclaration($idDeclaration);

        if (empty($invoices)) {
            $this->jsonResponse([
                'success' => true,
                'id_declaration' => $idDeclaration,
                'invoices' => [],
            ]);
            return;
        }

        try {
            require_once _PS_MODULE_DIR_ . 'snt_dji_compensation/classes/DJICompensationS3Service.php';
            $s3 = new DJICompensationS3Service();

            $result = [];
            foreach ($invoices as $invoice) {
                $result[] = [
                    'id_invoice' => (int) $invoice['id_invoice'],
                    'original_filename' => $invoice['original_filename'],
                    's3_key' => $invoice['s3_key'],
                    'file_size' => (int) $invoice['file_size'],
                    'presigned_url' => $s3->getPresignedUrl($invoice['s3_key'], $expiresIn),
                    'expires_in' => $expiresIn,
                ];
            }

            $this->jsonResponse([
                'success' => true,
                'id_declaration' => $idDeclaration,
                'id_customer' => (int) $declaration->id_customer,
                'reference' => $declaration->reference,
                'invoices_count' => count($result),
                'invoices' => $result,
            ]);
        } catch (Exception $e) {
            $this->jsonResponse([
                'error' => 'Error',
                'message' => 'Error generating presigned URLs: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Met a jour le statut d'une declaration
     */
    protected function updateDeclarationStatus()
    {
        $idDeclaration = (int) Tools::getValue('id_declaration');
        $newStatus = Tools::getValue('status');
        $adminNotes = Tools::getValue('admin_notes', '');

        if (!$idDeclaration) {
            $this->jsonResponse(['error' => 'Bad Request', 'message' => 'id_declaration is required'], 400);
            return;
        }

        $validStatuses = array_keys(DJICompensationDeclaration::getStatuses());
        if (!in_array($newStatus, $validStatuses)) {
            $this->jsonResponse([
                'error' => 'Bad Request',
                'message' => 'Invalid status. Valid: ' . implode(', ', $validStatuses)
            ], 400);
            return;
        }

        $declaration = new DJICompensationDeclaration($idDeclaration);
        if (!Validate::isLoadedObject($declaration)) {
            $this->jsonResponse(['error' => 'Not Found', 'message' => 'Declaration not found'], 404);
            return;
        }

        $oldStatus = $declaration->status;
        $declaration->status = $newStatus;

        if (!empty($adminNotes)) {
            $declaration->admin_notes = $adminNotes;
        }

        try {
            if ($declaration->save()) {
                if ($oldStatus !== $newStatus) {
                    $labels = DJICompensationDeclaration::getStatuses();
                    $details = ($labels[$oldStatus] ?? $oldStatus) . ' -> ' . ($labels[$newStatus] ?? $newStatus);
                    DJICompensationHistory::log(
                        $idDeclaration,
                        DJICompensationHistory::ACTION_STATUS_CHANGED,
                        $details,
                        DJICompensationHistory::ACTOR_SYSTEM,
                        0,
                        'n8n / API'
                    );
                }
                $this->jsonResponse([
                    'success' => true,
                    'id_declaration' => $idDeclaration,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'message' => 'Status updated successfully'
                ]);
            } else {
                $this->jsonResponse([
                    'error' => 'Error',
                    'message' => 'Failed to update declaration'
                ], 500);
            }
        } catch (Exception $e) {
            $this->jsonResponse([
                'error' => 'Error',
                'message' => 'Exception: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Declarations eligibles a un export, avec leur client.
     *
     * Partage par les deux resumes (feuille promotions et feuille SN) : les
     * deux lisent le meme fichier XLSX, seule la feuille exploitee differe.
     *
     * @param string $status Filtre de statut ; vide = statuts par defaut
     * @return array
     */
    protected function getSummaryDeclarations($status, $limit = 0)
    {
        // Statuts remontes quand aucun filtre n'est demande.
        $defaultStatuses = ['submitted', 'processing', 'validated'];

        // Statuts interrogeables explicitement. "registered" en fait partie sans
        // etre dans les statuts par defaut : il sert a rejouer l'export des
        // declarations deja traitees (reprise d'historique dans declarations_export)
        // sans modifier le comportement du workflow nominal.
        $queryableStatuses = array_merge($defaultStatuses, ['registered']);

        if ($status && in_array($status, $queryableStatuses)) {
            $statusFilter = [pSQL($status)];
        } else {
            $statusFilter = $defaultStatuses;
        }

        $sql = new DbQuery();
        $sql->select('d.id_declaration, d.id_customer, d.reference, d.status, d.xlsx_s3_key, d.date_add');
        $sql->select('c.firstname, c.lastname, c.email, c.company, c.siret');
        // Date reelle de soumission = premier passage en "submitted" dans l'historique
        $sql->select('(SELECT MIN(h.date_add) FROM `' . _DB_PREFIX_ . 'dji_compensation_history` h
            WHERE h.id_declaration = d.id_declaration AND h.action = "' . pSQL(DJICompensationHistory::ACTION_SUBMITTED) . '") AS submitted_at');
        $sql->from('dji_compensation_declaration', 'd');
        $sql->leftJoin('customer', 'c', 'c.id_customer = d.id_customer');
        $sql->where('d.status IN ("' . implode('","', $statusFilter) . '")');
        $sql->where('d.xlsx_s3_key IS NOT NULL AND d.xlsx_s3_key != ""');
        $sql->orderBy('d.date_add ASC');

        if ($limit > 0) {
            $sql->limit((int) $limit);
        }

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Prepare un appel de synthese : taille du lot et temps d'execution.
     *
     * Chaque declaration coute un telechargement S3 et une lecture XLSX. Apres
     * une interruption des flux (vacances, panne n8n), le stock a rattraper peut
     * depasser max_execution_time en un seul appel. Le parametre ?limit=N fait
     * traiter les plus anciennes d'abord ; les suivantes passent aux runs
     * suivants, puisque les declarations traitees changent de statut.
     *
     * @return int Taille du lot, 0 = tout
     */
    protected function prepareSummaryBatch()
    {
        @set_time_limit(300);

        $limit = (int) Tools::getValue('limit', 0);
        return max(0, min($limit, 200));
    }

    /**
     * Nombre de declarations restant a traiter apres ce lot.
     *
     * @param string $status
     * @param int    $limit
     * @param int    $batchSize Declarations effectivement renvoyees
     * @return int
     */
    protected function countRemainingSummary($status, $limit, $batchSize)
    {
        if ($limit <= 0 || $batchSize < $limit) {
            return 0;
        }
        return max(0, count($this->getSummaryDeclarations($status)) - $batchSize);
    }

    /**
     * Champs d'identification communs a toutes les lignes exportees.
     *
     * @param array $decl Ligne renvoyee par getSummaryDeclarations()
     * @return array
     */
    protected function buildDeclarationContext(array $decl)
    {
        return [
            'id_declaration' => (int) $decl['id_declaration'],
            'declaration_reference' => $decl['reference'],
            'declaration_status' => $decl['status'],
            'declaration_date' => !empty($decl['submitted_at']) ? $decl['submitted_at'] : $decl['date_add'],
            'id_customer' => (int) $decl['id_customer'],
            'customer_name' => trim($decl['firstname'] . ' ' . $decl['lastname']),
            'customer_email' => $decl['email'],
            'customer_company' => $decl['company'],
            'customer_siret' => $decl['siret'],
        ];
    }

    /**
     * Telecharge le XLSX d'une declaration depuis S3 et ouvre un lecteur.
     *
     * Le fichier temporaire est a supprimer par l'appelant : le lecteur garde
     * le ZIP ouvert tant qu'il vit.
     *
     * @param DJICompensationS3Service $s3
     * @param array                    $decl
     * @param string|null              $tempFile Renseigne en sortie
     * @return DJICompensationXlsxReader
     * @throws Exception
     */
    protected function openDeclarationXlsx($s3, array $decl, &$tempFile)
    {
        $xlsxContent = $s3->get($decl['xlsx_s3_key']);
        if ($xlsxContent === false) {
            throw new Exception('S3 download failed');
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'dji_xlsx_');
        file_put_contents($tempFile, $xlsxContent);

        return new DJICompensationXlsxReader($tempFile);
    }

    /**
     * Retourne un resume des declarations groupees par promotion et client
     * Pour export Google Sheets
     */
    protected function getDeclarationsSummary()
    {
        $status = Tools::getValue('status', '');
        $limit = $this->prepareSummaryBatch();
        $declarations = $this->getSummaryDeclarations($status, $limit);

        if (empty($declarations)) {
            $this->jsonResponse([
                'success' => true,
                'total_declarations' => 0,
                'total_lines' => 0,
                'remaining_declarations' => 0,
                'processed_declarations' => [],
                'errors' => [],
                'lines' => [],
            ]);
            return;
        }

        require_once _PS_MODULE_DIR_ . 'snt_dji_compensation/classes/DJICompensationS3Service.php';
        require_once _PS_MODULE_DIR_ . 'snt_dji_compensation/classes/DJICompensationXlsxReader.php';

        $s3 = new DJICompensationS3Service();
        $allLines = [];
        $errors = [];
        // Declarations examinees sans erreur, y compris celles sans aucune ligne
        // sur la feuille 1 (declaration uniquement SN, quantites vides). Le flux
        // les fait toutes avancer : sinon elles resteraient en "submitted",
        // n'atteindraient jamais le flux SN et occuperaient le lot a chaque run.
        $processed = [];

        foreach ($declarations as $decl) {
            try {
                $xlsxContent = $s3->get($decl['xlsx_s3_key']);
                if ($xlsxContent === false) {
                    $errors[] = ['id_declaration' => $decl['id_declaration'], 'error' => 'S3 download failed'];
                    continue;
                }

                $tempFile = tempnam(sys_get_temp_dir(), 'dji_xlsx_');
                file_put_contents($tempFile, $xlsxContent);

                $reader = new DJICompensationXlsxReader($tempFile);
                $result = $reader->readSheet(1, 1);
                unlink($tempFile);

                $aliases = $this->getColumnAliases();

                foreach ($result['data'] as $row) {
                    $norm = $this->normalizeRow($row);
                    $qtyRaw = $this->pickColumn($norm, $aliases['quantite']);
                    $qty = $qtyRaw !== null ? trim((string) $qtyRaw) : '';
                    if ($qty !== '' && $qty != 0) {
                        $compensationHt = $this->parseNumber($this->pickColumn($norm, $aliases['compensation_ht']));
                        $qtyInt = (int) $qty;
                        $totalCompensation = ($compensationHt !== null && $qtyInt > 0)
                            ? round($compensationHt * $qtyInt, 2)
                            : null;

                        $allLines[] = array_merge($this->buildDeclarationContext($decl), [
                            'type_promotion' => $this->pickColumn($norm, $aliases['type_promotion']),
                            'reference_article' => $this->pickColumn($norm, $aliases['reference_article']),
                            'produit' => $this->pickColumn($norm, $aliases['produit']),
                            'ean' => $this->pickColumn($norm, $aliases['ean']),
                            'quantite_declaree' => $qtyInt,
                            'prix_vente_ttc' => $this->parseNumber($this->pickColumn($norm, $aliases['prix_vente_ttc'])),
                            'prix_promo_ttc' => $this->parseNumber($this->pickColumn($norm, $aliases['prix_promo_ttc'])),
                            'compensation_ht' => $compensationHt,
                            'total_compensation_ht' => $totalCompensation,
                            'date_debut' => $this->excelDateToISO($this->pickColumn($norm, $aliases['date_debut'])),
                            'date_fin' => $this->excelDateToISO($this->pickColumn($norm, $aliases['date_fin'])),
                            'date_butoir' => $this->excelDateToISO($this->pickColumn($norm, $aliases['date_butoir'])),
                            'numero_serie' => $this->pickColumn($norm, $aliases['numero_serie']),
                            'reference_avoir' => $this->pickColumn($norm, $aliases['reference_avoir']),
                        ]);
                    }
                }

                $processed[] = (int) $decl['id_declaration'];
            } catch (Exception $e) {
                // Pas de $processed : retentee au prochain passage.
                $errors[] = ['id_declaration' => $decl['id_declaration'], 'error' => $e->getMessage()];
            }
        }

        $this->jsonResponse([
            'success' => true,
            'total_declarations' => count($declarations),
            'total_lines' => count($allLines),
            'remaining_declarations' => $this->countRemainingSummary($status, $limit, count($declarations)),
            'processed_declarations' => $processed,
            'errors' => $errors,
            'lines' => $allLines,
        ]);
    }

    /**
     * Resume des lignes de numeros de serie declarees.
     *
     * Pendant de declarations-summary, mais sur la feuille "Matrice declaration
     * SN" du meme fichier client : une ligne par numero de serie, la ou la
     * premiere feuille raisonne en quantites.
     *
     * Une declaration sans feuille SN, ou avec une feuille SN vide, ne remonte
     * simplement aucune ligne : les deux flux d'export sont independants.
     */
    protected function getDeclarationsSnSummary()
    {
        $status = Tools::getValue('status', '');
        $limit = $this->prepareSummaryBatch();
        $declarations = $this->getSummaryDeclarations($status, $limit);

        if (empty($declarations)) {
            $this->jsonResponse([
                'success' => true,
                'total_declarations' => 0,
                'total_lines' => 0,
                'remaining_declarations' => 0,
                'processed_declarations' => [],
                'declarations_without_sn_sheet' => [],
                'errors' => [],
                'lines' => [],
            ]);
            return;
        }

        require_once _PS_MODULE_DIR_ . 'snt_dji_compensation/classes/DJICompensationS3Service.php';
        require_once _PS_MODULE_DIR_ . 'snt_dji_compensation/classes/DJICompensationXlsxReader.php';

        $s3 = new DJICompensationS3Service();
        $aliases = $this->getSnColumnAliases();
        $allLines = [];
        $errors = [];
        $withoutSnSheet = [];
        // Declarations effectivement examinees, y compris celles sans ligne SN.
        // C'est cette liste que le flux n8n fait passer au statut suivant : une
        // declaration sans numero de serie doit avancer comme les autres, sans
        // quoi elle resterait indefiniment a relire.
        $processed = [];

        foreach ($declarations as $decl) {
            $tempFile = null;
            try {
                $reader = $this->openDeclarationXlsx($s3, $decl, $tempFile);
                $result = $this->readSnSheet($reader);

                // Le lecteur garde le ZIP ouvert : le liberer avant d'effacer.
                unset($reader);
                if ($tempFile) {
                    @unlink($tempFile);
                    $tempFile = null;
                }

                if ($result === null) {
                    // Template anterieur a la feuille SN, ou feuille renommee
                    // au-dela de ce qu'on sait reconnaitre. Ce n'est pas une
                    // erreur : on le signale sans polluer "errors".
                    $withoutSnSheet[] = (int) $decl['id_declaration'];
                    $processed[] = (int) $decl['id_declaration'];
                    continue;
                }

                $context = $this->buildDeclarationContext($decl);

                foreach ($result['data'] as $row) {
                    $norm = $this->normalizeRow($row);

                    // Le numero de serie fait la ligne. Il ecarte du meme coup
                    // les lignes de saisie vides (qui portent une formule de
                    // total a 0) et la ligne TOTAL en pied de tableau.
                    $serial = $this->pickColumn($norm, $aliases['numero_serie']);
                    $serial = $serial !== null ? trim((string) $serial) : '';
                    if ($serial === '') {
                        continue;
                    }

                    $qtyRaw = $this->pickColumn($norm, $aliases['quantite']);
                    $qty = $qtyRaw !== null ? trim((string) $qtyRaw) : '';
                    // Une ligne SN vaut une unite : c'est la regle du template
                    // ("1 ligne par numero de serie et produit").
                    $qtyInt = is_numeric($qty) ? (int) $qty : 1;

                    $compensationHt = $this->parseNumber($this->pickColumn($norm, $aliases['compensation_ht']));

                    // Le total du fichier vient d'une formule : on prefere le
                    // recalculer, et on retombe sur la valeur du fichier si la
                    // compensation unitaire manque.
                    $totalDeclared = $this->parseNumber($this->pickColumn($norm, $aliases['total']));
                    if ($compensationHt !== null) {
                        $totalCompensation = round($compensationHt * max($qtyInt, 1), 2);
                    } else {
                        $totalCompensation = $totalDeclared;
                    }

                    $allLines[] = array_merge($context, [
                        'type_declare' => $this->pickColumn($norm, $aliases['type_declare']),
                        'reference_article' => $this->pickColumn($norm, $aliases['reference_article']),
                        'produit' => $this->pickColumn($norm, $aliases['produit']),
                        'ean' => $this->pickColumn($norm, $aliases['ean']),
                        'numero_serie' => $serial,
                        'quantite_declaree' => $qtyInt,
                        'prix_vente_ttc' => $this->parseNumber($this->pickColumn($norm, $aliases['prix_vente_ttc'])),
                        'nouveau_prix_ttc' => $this->parseNumber($this->pickColumn($norm, $aliases['nouveau_prix_ttc'])),
                        'difference' => $this->parseNumber($this->pickColumn($norm, $aliases['difference'])),
                        'compensation_ht' => $compensationHt,
                        'total_compensation_ht' => $totalCompensation,
                        'date_debut' => $this->excelDateToISO($this->pickColumn($norm, $aliases['date_debut'])),
                        'date_fin' => $this->excelDateToISO($this->pickColumn($norm, $aliases['date_fin'])),
                    ]);
                }

                $processed[] = (int) $decl['id_declaration'];
            } catch (Exception $e) {
                // Pas de $processed : la declaration sera retentee au prochain
                // passage plutot que de changer de statut sur un echec.
                $errors[] = ['id_declaration' => (int) $decl['id_declaration'], 'error' => $e->getMessage()];
            }

            if ($tempFile) {
                @unlink($tempFile);
            }
        }

        $this->jsonResponse([
            'success' => true,
            'total_declarations' => count($declarations),
            'total_lines' => count($allLines),
            'remaining_declarations' => $this->countRemainingSummary($status, $limit, count($declarations)),
            'processed_declarations' => $processed,
            'declarations_without_sn_sheet' => $withoutSnSheet,
            'errors' => $errors,
            'lines' => $allLines,
        ]);
    }

    /**
     * Alias possibles pour chaque champ, afin de tolerer les differentes
     * versions de template (noms de colonnes qui ont evolue dans le temps).
     * Le premier alias trouve dans la ligne est utilise.
     */
    private function getColumnAliases()
    {
        return [
            // Renommee en septembre 2026 ; l'en-tete porte la liste des valeurs
            // possibles sur plusieurs lignes, d'ou la correspondance par prefixe.
            'type_promotion' => ["Type d'opération", 'Type de promotion'],
            'reference_article' => ['Référence produit - STS'],
            'produit' => ['Produit'],
            'ean' => ['EAN Produit'],
            'quantite' => ['Quantités déclarées pour compensation', 'Nombre de pièces déclarées'],
            'prix_vente_ttc' => ['Prix de vente TTC'],
            'prix_promo_ttc' => ['Prix Promo TTC'],
            'compensation_ht' => ['Compensation €HT'],
            'montant_avoir' => ["Montant de l'avoir à recevoir"],
            'date_debut' => ['Date de début'],
            'date_fin' => ['Date de fin'],
            'date_butoir' => ['Date butoir de déclaration'],
            'numero_serie' => ['Numéro de série produit (SN) Gammes : Mic & Power', 'Numéro de série'],
            'reference_avoir' => ['Référence AVOIR revendeur'],
        ];
    }

    /**
     * Alias des colonnes de la feuille "Matrice declaration SN".
     *
     * Jusqu'a septembre 2026 ses en-tetes etaient plus courts que ceux de la
     * feuille promotions ("Reference article", "Nouveau prix"...). Depuis, ils
     * reprennent ceux de la feuille 1 : les deux generations sont acceptees,
     * la plus recente en premier.
     */
    private function getSnColumnAliases()
    {
        return [
            // Type d'operation saisi par le revendeur ("Promotion avec numero de
            // serie"...). L'en-tete s'appelait "Revendeur" avant septembre 2026.
            // On remonte la valeur brute sans lui preter de sens.
            'type_declare' => ["Type d'opération", 'Revendeur'],
            'produit' => ['Produit'],
            'ean' => ['EAN Produit', 'EAN'],
            'reference_article' => ['Référence produit - STS', 'Référence article'],
            'date_debut' => ['Date de début'],
            'date_fin' => ['Date de fin'],
            'prix_vente_ttc' => ['Prix de vente TTC', 'Prix de vente'],
            'nouveau_prix_ttc' => ['Prix Promo TTC', 'Nouveau prix'],
            'difference' => ['Baisse de prix', 'Différence'],
            'compensation_ht' => ['Compensation €HT', 'Compensation'],
            'quantite' => ['Quantité'],
            'total' => ['Total'],
            // En-tetes sur plusieurs lignes ("Numero SN - Baisses de prix...",
            // "Quantite = 1 par ligne") : la correspondance par prefixe de
            // pickColumn() s'en charge.
            'numero_serie' => ['Numéro SN', 'Numéro de série'],
        ];
    }

    /**
     * Localise et lit la feuille des numeros de serie d'un fichier client.
     *
     * Ni le nom de la feuille ni la position de ses en-tetes ne sont figes :
     * le nom a change quatre fois selon les versions du template (en dernier
     * "Numeros de Serie (SN) declares", septembre 2026), et les
     * en-tetes sont precedes d'un bloc de consignes. On reconnait donc l'une
     * et les autres a leur contenu.
     *
     * @param DJICompensationXlsxReader $reader
     * @return array|null Resultat de readSheet(), null si la feuille est absente
     */
    private function readSnSheet($reader)
    {
        $sheet = $reader->findSheetByAliases([
            'numeros de serie',
            'numero de serie',
            'matrice declaration sn',
            'numeros sn',
            'numero sn',
            'matrice baisses de prix',
            'matrice',
        ]);

        if (!$sheet) {
            return null;
        }

        $headerRow = $reader->findHeaderRow($sheet['index'], [
            'numero sn',
            'reference produit',
            'reference article',
            'produit',
            'quantite',
            'compensation',
            'date de debut',
        ]);

        if (!$headerRow) {
            return null;
        }

        return $reader->readSheet($sheet['index'], $headerRow);
    }

    /**
     * Normalise un nom d'en-tete : retours ligne/tabulations -> espace,
     * espaces multiples -> simple, trim, minuscules.
     */
    private function normalizeHeader($header)
    {
        if ($header === null) {
            return '';
        }
        $header = str_replace(["\r\n", "\n", "\r", "\t"], ' ', (string) $header);
        // Excel remplace volontiers l'apostrophe droite par une typographique.
        $header = str_replace(["\u{2019}", "\u{2018}"], "'", $header);
        $header = preg_replace('/\s+/u', ' ', $header);
        $header = trim($header);
        return function_exists('mb_strtolower') ? mb_strtolower($header, 'UTF-8') : strtolower($header);
    }

    /**
     * Reindexe une ligne (header => valeur) sur les cles normalisees.
     */
    private function normalizeRow(array $row)
    {
        $map = [];
        foreach ($row as $key => $value) {
            $map[$this->normalizeHeader($key)] = $value;
        }
        return $map;
    }

    /**
     * Recupere la valeur d'une colonne a partir d'une liste d'alias.
     * 1) correspondance exacte (normalisee), 2) l'en-tete reel commence par l'alias.
     *
     * Un simple "contient" ne suffit pas : l'en-tete "Type d'operation" liste
     * ses valeurs ("... Promotion avec numero de serie ...") et capterait
     * l'alias "Numero de serie" de la feuille promotions.
     */
    private function pickColumn(array $normalizedRow, array $aliases)
    {
        // Correspondance exacte normalisee
        foreach ($aliases as $alias) {
            $normAlias = $this->normalizeHeader($alias);
            if ($normAlias !== '' && array_key_exists($normAlias, $normalizedRow)) {
                return $normalizedRow[$normAlias];
            }
        }
        // Correspondance par prefixe : l'en-tete reel commence par l'alias
        foreach ($aliases as $alias) {
            $normAlias = $this->normalizeHeader($alias);
            if ($normAlias === '') {
                continue;
            }
            foreach ($normalizedRow as $key => $value) {
                if ($key !== '' && strpos($key, $normAlias) === 0) {
                    return $value;
                }
            }
        }
        return null;
    }

    /**
     * Convertit une date Excel en format ISO
     */
    private function excelDateToISO($excelDate)
    {
        if (!$excelDate || !is_numeric($excelDate)) {
            return null;
        }
        $date = new DateTime();
        $date->setTimestamp(($excelDate - 25569) * 86400);
        return $date->format('Y-m-d');
    }

    /**
     * Parse un nombre depuis une valeur XLSX
     */
    private function parseNumber($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $num = floatval(str_replace([',', ' '], ['.', ''], $value));
        return $num;
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

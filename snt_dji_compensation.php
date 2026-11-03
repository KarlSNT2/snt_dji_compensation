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

require_once __DIR__ . '/classes/DJICompensationDeclaration.php';
require_once __DIR__ . '/classes/DJICompensationInvoice.php';
require_once __DIR__ . '/classes/DJICompensationStockProof.php';
require_once __DIR__ . '/classes/DJICompensationHistory.php';
require_once __DIR__ . '/classes/DJICompensationMailService.php';
require_once __DIR__ . '/classes/DJICompensationTemplateDownload.php';

class Snt_dji_compensation extends Module
{
    public function __construct()
    {
        $this->name = 'snt_dji_compensation';
        $this->tab = 'administration';
        $this->version = '1.4.0';
        $this->author = 'SNT2';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('SNT Compensation DJI');
        $this->description = $this->l('Module de declaration de compensation DJI pour les clients B2B');

        $this->confirmUninstall = $this->l('Supprimer ce module supprimera toutes les donnees associees.');

        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => '8.99.99'];
    }

    public function install()
    {
        include dirname(__FILE__) . '/sql/install.php';

        Configuration::updateValue('SNT_DJI_COMPENSATION_LIVE_MODE', false);
        Configuration::updateValue('SNT_DJI_COMPENSATION_TEMPLATE_FILE', '');
        Configuration::updateValue('SNT_DJI_COMPENSATION_S3_REGION', 'fr-par');
        Configuration::updateValue('SNT_DJI_COMPENSATION_S3_BUCKET', '');
        Configuration::updateValue('SNT_DJI_COMPENSATION_S3_ACCESS_KEY', '');
        Configuration::updateValue('SNT_DJI_COMPENSATION_S3_SECRET_KEY', '');
        Configuration::updateValue('SNT_DJI_COMPENSATION_MAX_FILE_SIZE', 10);
        Configuration::updateValue('SNT_DJI_COMPENSATION_MAX_INVOICES', 50);
        Configuration::updateValue('SNT_DJI_COMPENSATION_MAX_STOCK_PROOFS', 20);
        Configuration::updateValue('SNT_DJI_COMPENSATION_ADMIN_EMAIL', Configuration::get('PS_SHOP_EMAIL'));
        Configuration::updateValue('SNT_DJI_COMPENSATION_NOTIFY_ERRORS', true);
        Configuration::updateValue('SNT_DJI_COMPENSATION_API_KEY', $this->generateApiKey());
        Configuration::updateValue('SNT_DJI_COMPENSATION_N8N_PROMO_WEBHOOK', '');

        return parent::install()
            && $this->registerHook('header')
            && $this->registerHook('displayBackOfficeHeader')
            && $this->registerHook('displayCustomerAccount')
            && $this->registerHook('moduleRoutes')
            && $this->installTab();
    }

    public function uninstall()
    {
        include dirname(__FILE__) . '/sql/uninstall.php';

        Configuration::deleteByName('SNT_DJI_COMPENSATION_LIVE_MODE');
        Configuration::deleteByName('SNT_DJI_COMPENSATION_TEMPLATE_FILE');
        Configuration::deleteByName('SNT_DJI_COMPENSATION_S3_REGION');
        Configuration::deleteByName('SNT_DJI_COMPENSATION_S3_BUCKET');
        Configuration::deleteByName('SNT_DJI_COMPENSATION_S3_ACCESS_KEY');
        Configuration::deleteByName('SNT_DJI_COMPENSATION_S3_SECRET_KEY');
        Configuration::deleteByName('SNT_DJI_COMPENSATION_MAX_FILE_SIZE');
        Configuration::deleteByName('SNT_DJI_COMPENSATION_MAX_INVOICES');
        Configuration::deleteByName('SNT_DJI_COMPENSATION_MAX_STOCK_PROOFS');
        Configuration::deleteByName('SNT_DJI_COMPENSATION_ADMIN_EMAIL');
        Configuration::deleteByName('SNT_DJI_COMPENSATION_NOTIFY_ERRORS');
        Configuration::deleteByName('SNT_DJI_COMPENSATION_API_KEY');
        Configuration::deleteByName('SNT_DJI_COMPENSATION_N8N_PROMO_WEBHOOK');

        return parent::uninstall() && $this->uninstallTab();
    }

    protected function installTab()
    {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminDJICompensation';
        $tab->name = [];

        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'DJI Compensation';
        }

        $tab->id_parent = (int) Tab::getIdFromClassName('AdminParentCustomer');
        $tab->module = $this->name;

        return $tab->add();
    }

    protected function uninstallTab()
    {
        $idTab = (int) Tab::getIdFromClassName('AdminDJICompensation');
        if ($idTab) {
            $tab = new Tab($idTab);
            return $tab->delete();
        }
        return true;
    }

    public function getContent()
    {
        $output = '';

        // Upload du template XLSX
        if (Tools::isSubmit('submitTemplate') && isset($_FILES['template_file'])) {
            $output .= $this->processTemplateUpload();
        }

        // Restaurer un ancien template
        if (Tools::isSubmit('restoreTemplate')) {
            $output .= $this->processRestoreTemplate();
        }

        // Configuration S3
        if (Tools::isSubmit('submitS3Config')) {
            $this->processS3Config();
            $output .= $this->displayConfirmation($this->l('Configuration S3 mise a jour'));
        }

        // Configuration generale
        if (Tools::isSubmit('submitGeneralConfig')) {
            $this->processGeneralConfig();
            $output .= $this->displayConfirmation($this->l('Configuration generale mise a jour'));
        }

        // Test connexion S3
        if (Tools::isSubmit('testS3Connection')) {
            $output .= $this->testS3Connection();
        }

        // Configuration API
        if (Tools::isSubmit('submitApiConfig')) {
            $output .= $this->processApiConfig();
        }

        // Declencher la synchro des promotions sans changer de template
        if (Tools::isSubmit('syncPromotionsNow')) {
            $output .= $this->processSyncPromotionsNow();
        }

        // Regenerer cle API
        if (Tools::isSubmit('regenerateApiKey')) {
            Configuration::updateValue('SNT_DJI_COMPENSATION_API_KEY', $this->generateApiKey());
            $output .= $this->displayConfirmation($this->l('Cle API regeneree'));
        }

        $this->context->smarty->assign([
            'module_dir' => $this->_path,
            'declarations_link' => $this->context->link->getAdminLink('AdminDJICompensation'),
            'template_file' => Configuration::get('SNT_DJI_COMPENSATION_TEMPLATE_FILE'),
            'has_template' => $this->hasTemplate(),
            'template_history' => $this->getTemplateHistory(),
            'configure_link' => $this->context->link->getAdminLink('AdminModules', true, [], [
                'configure' => $this->name,
                'tab_module' => $this->tab,
                'module_name' => $this->name,
            ]),
        ]);

        $output .= $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');
        $output .= $this->renderTemplateForm();
        $output .= $this->renderGeneralConfigForm();
        $output .= $this->renderS3ConfigForm();
        $output .= $this->renderApiConfigForm();

        return $output;
    }

    protected function renderTemplateForm()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitTemplate';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $currentFile = Configuration::get('SNT_DJI_COMPENSATION_TEMPLATE_FILE');
        $fileInfo = $currentFile ? '<br><strong>Fichier actuel :</strong> ' . $currentFile : '';

        return $helper->generateForm([[
            'form' => [
                'legend' => [
                    'title' => $this->l('Template XLSX DJI'),
                    'icon' => 'icon-file-excel-o',
                ],
                'input' => [
                    [
                        'type' => 'file',
                        'label' => $this->l('Fichier template'),
                        'name' => 'template_file',
                        'desc' => $this->l('Uploadez le fichier XLSX des promotions DJI que les clients devront remplir.') . $fileInfo,
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Enregistrer le template'),
                ],
            ],
        ]]);
    }

    protected function renderGeneralConfigForm()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitGeneralConfig';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = [
            'fields_value' => [
                'SNT_DJI_COMPENSATION_LIVE_MODE' => Configuration::get('SNT_DJI_COMPENSATION_LIVE_MODE'),
                'SNT_DJI_COMPENSATION_MAX_FILE_SIZE' => Configuration::get('SNT_DJI_COMPENSATION_MAX_FILE_SIZE'),
                'SNT_DJI_COMPENSATION_MAX_INVOICES' => Configuration::get('SNT_DJI_COMPENSATION_MAX_INVOICES'),
                'SNT_DJI_COMPENSATION_MAX_STOCK_PROOFS' => Configuration::get('SNT_DJI_COMPENSATION_MAX_STOCK_PROOFS'),
                'SNT_DJI_COMPENSATION_NOTIFY_ERRORS' => Configuration::get('SNT_DJI_COMPENSATION_NOTIFY_ERRORS'),
                'SNT_DJI_COMPENSATION_ADMIN_EMAIL' => Configuration::get('SNT_DJI_COMPENSATION_ADMIN_EMAIL'),
            ],
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([[
            'form' => [
                'legend' => [
                    'title' => $this->l('Configuration generale'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Mode actif'),
                        'name' => 'SNT_DJI_COMPENSATION_LIVE_MODE',
                        'is_bool' => true,
                        'desc' => $this->l('Activer le module pour les clients'),
                        'values' => [
                            ['id' => 'active_on', 'value' => 1, 'label' => $this->l('Oui')],
                            ['id' => 'active_off', 'value' => 0, 'label' => $this->l('Non')],
                        ],
                    ],
                    [
                        'col' => 2,
                        'type' => 'text',
                        'name' => 'SNT_DJI_COMPENSATION_MAX_FILE_SIZE',
                        'label' => $this->l('Taille max. fichier (Mo)'),
                        'suffix' => 'Mo',
                    ],
                    [
                        'col' => 2,
                        'type' => 'text',
                        'name' => 'SNT_DJI_COMPENSATION_MAX_INVOICES',
                        'label' => $this->l('Nombre max. factures'),
                    ],
                    [
                        'col' => 2,
                        'type' => 'text',
                        'name' => 'SNT_DJI_COMPENSATION_MAX_STOCK_PROOFS',
                        'label' => $this->l('Nombre max. photos stock'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Notifier les erreurs d\'upload'),
                        'name' => 'SNT_DJI_COMPENSATION_NOTIFY_ERRORS',
                        'is_bool' => true,
                        'desc' => $this->l('Envoyer un email en cas d\'erreur d\'upload de fichier ou S3'),
                        'values' => [
                            ['id' => 'notify_on', 'value' => 1, 'label' => $this->l('Oui')],
                            ['id' => 'notify_off', 'value' => 0, 'label' => $this->l('Non')],
                        ],
                    ],
                    [
                        'col' => 4,
                        'type' => 'text',
                        'name' => 'SNT_DJI_COMPENSATION_ADMIN_EMAIL',
                        'label' => $this->l('Email de notification'),
                        'desc' => $this->l('Adresse email pour recevoir les notifications d\'erreur'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Enregistrer'),
                ],
            ],
        ]]);
    }

    protected function renderS3ConfigForm()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitS3Config';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = [
            'fields_value' => [
                'SNT_DJI_COMPENSATION_S3_REGION' => Configuration::get('SNT_DJI_COMPENSATION_S3_REGION'),
                'SNT_DJI_COMPENSATION_S3_BUCKET' => Configuration::get('SNT_DJI_COMPENSATION_S3_BUCKET'),
                'SNT_DJI_COMPENSATION_S3_ACCESS_KEY' => Configuration::get('SNT_DJI_COMPENSATION_S3_ACCESS_KEY'),
                'SNT_DJI_COMPENSATION_S3_SECRET_KEY' => Configuration::get('SNT_DJI_COMPENSATION_S3_SECRET_KEY'),
            ],
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([[
            'form' => [
                'legend' => [
                    'title' => $this->l('Configuration Scaleway S3'),
                    'icon' => 'icon-cloud-upload',
                ],
                'input' => [
                    [
                        'col' => 3,
                        'type' => 'text',
                        'name' => 'SNT_DJI_COMPENSATION_S3_REGION',
                        'label' => $this->l('Region'),
                        'desc' => $this->l('Ex: fr-par, nl-ams'),
                    ],
                    [
                        'col' => 4,
                        'type' => 'text',
                        'name' => 'SNT_DJI_COMPENSATION_S3_BUCKET',
                        'label' => $this->l('Nom du bucket'),
                    ],
                    [
                        'col' => 6,
                        'type' => 'text',
                        'name' => 'SNT_DJI_COMPENSATION_S3_ACCESS_KEY',
                        'label' => $this->l('Access Key'),
                    ],
                    [
                        'col' => 6,
                        'type' => 'password',
                        'name' => 'SNT_DJI_COMPENSATION_S3_SECRET_KEY',
                        'label' => $this->l('Secret Key'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Enregistrer'),
                ],
                'buttons' => [
                    [
                        'type' => 'submit',
                        'title' => $this->l('Tester la connexion'),
                        'icon' => 'process-icon-refresh',
                        'name' => 'testS3Connection',
                        'class' => 'btn btn-default pull-right',
                    ],
                ],
            ],
        ]]);
    }

    protected function renderApiConfigForm()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitApiConfig';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $apiKey = Configuration::get('SNT_DJI_COMPENSATION_API_KEY');
        $apiBaseUrl = $this->context->link->getModuleLink($this->name, 'api', ['action' => 'customers']);

        $helper->tpl_vars = [
            'fields_value' => [
                'SNT_DJI_COMPENSATION_API_KEY' => $apiKey,
                'SNT_DJI_COMPENSATION_N8N_PROMO_WEBHOOK' => Configuration::get('SNT_DJI_COMPENSATION_N8N_PROMO_WEBHOOK'),
            ],
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        $apiInfo = '<div class="alert alert-info">';
        $apiInfo .= '<strong>' . $this->l('Endpoints disponibles :') . '</strong><br>';
        $apiInfo .= '<code>GET /api/dji-compensation/customers</code> - ' . $this->l('Liste des clients') . '<br>';
        $apiInfo .= '<code>GET /api/dji-compensation/customer?id_customer=ID</code> - ' . $this->l('Detail d\'un client') . '<br>';
        $apiInfo .= '<code>GET /api/dji-compensation/declarations</code> - ' . $this->l('Liste des declarations') . '<br>';
        $apiInfo .= '<code>GET /api/dji-compensation/stats</code> - ' . $this->l('Statistiques') . '<br><br>';
        $apiInfo .= '<strong>' . $this->l('Authentification :') . '</strong><br>';
        $apiInfo .= $this->l('Header') . ': <code>Authorization: Bearer VOTRE_CLE_API</code><br>';
        $apiInfo .= $this->l('Ou header') . ': <code>X-Api-Key: VOTRE_CLE_API</code><br>';
        $apiInfo .= $this->l('Ou parametre') . ': <code>?api_key=VOTRE_CLE_API</code>';
        $apiInfo .= '</div>';

        return $helper->generateForm([[
            'form' => [
                'legend' => [
                    'title' => $this->l('Configuration API'),
                    'icon' => 'icon-plug',
                ],
                'description' => $apiInfo,
                'input' => [
                    [
                        'col' => 8,
                        'type' => 'text',
                        'name' => 'SNT_DJI_COMPENSATION_API_KEY',
                        'label' => $this->l('Cle API'),
                        'desc' => $this->l('Cle secrete pour acceder a l\'API. Gardez-la confidentielle.'),
                        'readonly' => true,
                    ],
                    [
                        'col' => 8,
                        'type' => 'text',
                        'name' => 'SNT_DJI_COMPENSATION_N8N_PROMO_WEBHOOK',
                        'label' => $this->l('Webhook n8n - synchro promotions'),
                        'desc' => $this->l('URL de production du webhook du flux "Insert Actual Promotions", appelee a chaque upload ou restauration du template avec la cle API ci-dessus dans le header X-Api-Key. Laisser vide pour ne compter que sur le cron n8n.'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Enregistrer'),
                ],
                'buttons' => [
                    [
                        'type' => 'submit',
                        'title' => $this->l('Synchroniser les promotions maintenant'),
                        'icon' => 'process-icon-refresh',
                        'name' => 'syncPromotionsNow',
                        'class' => 'btn btn-default pull-right',
                    ],
                    [
                        'type' => 'submit',
                        'title' => $this->l('Regenerer la cle'),
                        'icon' => 'process-icon-refresh',
                        'name' => 'regenerateApiKey',
                        'class' => 'btn btn-warning pull-right',
                    ],
                ],
            ],
        ]]);
    }

    protected function processApiConfig()
    {
        // La cle API est en lecture seule : seul le webhook est modifiable.
        $webhook = trim((string) Tools::getValue('SNT_DJI_COMPENSATION_N8N_PROMO_WEBHOOK'));
        if ($webhook !== '' && !preg_match('#^https?://#i', $webhook)) {
            return $this->displayError($this->l('L\'URL du webhook doit commencer par http:// ou https://.'));
        }

        Configuration::updateValue('SNT_DJI_COMPENSATION_N8N_PROMO_WEBHOOK', $webhook);

        return $this->displayConfirmation($this->l('Configuration API mise a jour'));
    }

    /**
     * Appelle le webhook de synchro avec le template actif, sans le modifier.
     * Sert a tester la chaine module -> n8n, et a forcer une resynchro.
     */
    protected function processSyncPromotionsNow()
    {
        if (trim((string) Configuration::get('SNT_DJI_COMPENSATION_N8N_PROMO_WEBHOOK')) === '') {
            return $this->displayError($this->l('Aucune URL de webhook n8n configuree.'));
        }

        $active = $this->getActiveTemplate();
        if (!$active) {
            return $this->displayError($this->l('Aucun template actif.'));
        }

        $warning = $this->notifyPromotionsSync($active['id_template'], $active['filename']);
        if ($warning !== '') {
            return $warning;
        }

        return $this->displayConfirmation(sprintf(
            $this->l('Synchronisation declenchee pour le template actif (%s). Resultat visible dans les executions n8n.'),
            $active['filename']
        ));
    }

    /**
     * Previent n8n que le template actif a change, pour qu'il resynchronise
     * promotions_dji sans attendre son cron.
     *
     * Best effort : le template est deja enregistre quand on arrive ici, un
     * echec ne doit donc rien annuler. On se contente d'un avertissement, le
     * cron quotidien du flux rattrapera.
     *
     * @param int|null $idTemplate
     * @param string   $filename
     * @return string Avertissement a afficher ; vide si l'appel a reussi ou si aucun webhook n'est configure
     */
    protected function notifyPromotionsSync($idTemplate, $filename)
    {
        $url = trim((string) Configuration::get('SNT_DJI_COMPENSATION_N8N_PROMO_WEBHOOK'));
        if ($url === '') {
            return '';
        }

        $payload = json_encode([
            'event' => 'template_activated',
            'id_template' => $idTemplate ? (int) $idTemplate : null,
            'filename' => $filename,
            'date' => date('c'),
        ]);
        $headers = [
            'Content-Type: application/json',
            'X-Api-Key: ' . Configuration::get('SNT_DJI_COMPENSATION_API_KEY'),
        ];

        $status = 0;
        $error = '';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                // Le webhook n8n repond des reception : ces delais ne couvrent
                // que l'aller-retour, pas l'import lui-meme.
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 5,
            ]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
        } else {
            $context = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $payload,
                'timeout' => 5,
                'ignore_errors' => true,
            ]]);
            $result = @file_get_contents($url, false, $context);
            if ($result === false) {
                $error = 'connexion impossible';
            } elseif (isset($http_response_header[0]) && preg_match('#\s(\d{3})(\s|$)#', $http_response_header[0], $m)) {
                $status = (int) $m[1];
            }
        }

        if ($status >= 200 && $status < 300) {
            return '';
        }

        $detail = $error !== '' ? $error : 'HTTP ' . $status;
        PrestaShopLogger::addLog('[snt_dji_compensation] Webhook synchro promotions en echec : ' . $detail, 2);

        return $this->displayWarning(sprintf(
            $this->l('Template enregistre, mais la synchronisation n8n des promotions n\'a pas pu etre declenchee (%s). Elle se fera au prochain passage du cron.'),
            $detail
        ));
    }

    protected function processTemplateUpload()
    {
        $file = $_FILES['template_file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return $this->displayError($this->l('Erreur lors de l\'upload du fichier.'));
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($extension !== 'xlsx') {
            return $this->displayError($this->l('Le fichier doit etre au format XLSX.'));
        }

        $uploadDir = _PS_MODULE_DIR_ . $this->name . '/uploads/templates/';
        $filename = 'template_dji_' . date('Y-m-d_His') . '.xlsx';

        // Desactiver l'ancien template dans l'historique (sans supprimer le fichier)
        Db::getInstance()->update('dji_compensation_template_history', ['is_active' => 0], 'is_active = 1');

        if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
            Configuration::updateValue('SNT_DJI_COMPENSATION_TEMPLATE_FILE', $filename);

            // Ajouter le nouveau template a l'historique
            $idEmployee = $this->context->employee ? (int) $this->context->employee->id : null;
            Db::getInstance()->insert('dji_compensation_template_history', [
                'filename' => pSQL($filename),
                'original_filename' => pSQL($file['name']),
                'file_size' => (int) $file['size'],
                'is_active' => 1,
                'id_employee' => $idEmployee,
                'date_add' => date('Y-m-d H:i:s'),
            ]);
            $idTemplate = (int) Db::getInstance()->Insert_ID();

            return $this->displayConfirmation($this->l('Template XLSX mis a jour avec succes.'))
                . $this->notifyPromotionsSync($idTemplate, $filename);
        }

        return $this->displayError($this->l('Erreur lors de l\'enregistrement du fichier.'));
    }

    protected function processGeneralConfig()
    {
        Configuration::updateValue('SNT_DJI_COMPENSATION_LIVE_MODE', Tools::getValue('SNT_DJI_COMPENSATION_LIVE_MODE'));
        Configuration::updateValue('SNT_DJI_COMPENSATION_MAX_FILE_SIZE', (int) Tools::getValue('SNT_DJI_COMPENSATION_MAX_FILE_SIZE'));
        Configuration::updateValue('SNT_DJI_COMPENSATION_MAX_INVOICES', (int) Tools::getValue('SNT_DJI_COMPENSATION_MAX_INVOICES'));
        Configuration::updateValue('SNT_DJI_COMPENSATION_MAX_STOCK_PROOFS', (int) Tools::getValue('SNT_DJI_COMPENSATION_MAX_STOCK_PROOFS'));
        Configuration::updateValue('SNT_DJI_COMPENSATION_NOTIFY_ERRORS', Tools::getValue('SNT_DJI_COMPENSATION_NOTIFY_ERRORS'));
        Configuration::updateValue('SNT_DJI_COMPENSATION_ADMIN_EMAIL', Tools::getValue('SNT_DJI_COMPENSATION_ADMIN_EMAIL'));
    }

    protected function processS3Config()
    {
        Configuration::updateValue('SNT_DJI_COMPENSATION_S3_REGION', Tools::getValue('SNT_DJI_COMPENSATION_S3_REGION'));
        Configuration::updateValue('SNT_DJI_COMPENSATION_S3_BUCKET', Tools::getValue('SNT_DJI_COMPENSATION_S3_BUCKET'));
        Configuration::updateValue('SNT_DJI_COMPENSATION_S3_ACCESS_KEY', Tools::getValue('SNT_DJI_COMPENSATION_S3_ACCESS_KEY'));

        $secretKey = Tools::getValue('SNT_DJI_COMPENSATION_S3_SECRET_KEY');
        if (!empty($secretKey)) {
            Configuration::updateValue('SNT_DJI_COMPENSATION_S3_SECRET_KEY', $secretKey);
        }
    }

    protected function testS3Connection()
    {
        try {
            require_once __DIR__ . '/classes/DJICompensationS3Service.php';
            $s3 = new DJICompensationS3Service();

            if ($s3->testConnection()) {
                return $this->displayConfirmation($this->l('Connexion S3 reussie !'));
            }
            return $this->displayError($this->l('Impossible de se connecter au bucket S3.'));
        } catch (Exception $e) {
            return $this->displayError($this->l('Erreur S3 : ') . $e->getMessage());
        }
    }

    public function hookModuleRoutes()
    {
        return [
            'module-snt_dji_compensation-declaration' => [
                'controller' => 'declaration',
                'rule' => 'mon-compte/compensation-dji',
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => 'snt_dji_compensation',
                ],
            ],
            'module-snt_dji_compensation-download' => [
                'controller' => 'download',
                'rule' => 'mon-compte/compensation-dji/template',
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => 'snt_dji_compensation',
                ],
            ],
            'module-snt_dji_compensation-api' => [
                'controller' => 'api',
                'rule' => 'api/dji-compensation/{action}',
                'keywords' => [
                    'action' => ['regexp' => '[a-z]+', 'param' => 'action'],
                ],
                'params' => [
                    'fc' => 'module',
                    'module' => 'snt_dji_compensation',
                ],
            ],
        ];
    }

    /**
     * Genere une cle API aleatoire
     */
    protected function generateApiKey()
    {
        return bin2hex(random_bytes(32));
    }

    public function hookDisplayCustomerAccount()
    {
        if (!Configuration::get('SNT_DJI_COMPENSATION_LIVE_MODE')) {
            return '';
        }

        if (!$this->hasTemplate()) {
            return '';
        }

        $this->context->smarty->assign([
            'compensation_link' => $this->context->link->getModuleLink($this->name, 'declaration'),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/customer_account.tpl');
    }

    public function hookHeader()
    {
        if (Tools::getValue('module') === $this->name) {
            $this->context->controller->addCSS($this->_path . 'views/css/front.css');
            $this->context->controller->addJS($this->_path . 'views/js/front.js');
        }
    }

    public function hookDisplayBackOfficeHeader()
    {
        if (Tools::getValue('configure') == $this->name || Tools::getValue('controller') == 'AdminDJICompensation') {
            $this->context->controller->addCSS($this->_path . 'views/css/back.css');
            $this->context->controller->addJS($this->_path . 'views/js/back.js');
        }
    }

    public function getS3Service()
    {
        require_once __DIR__ . '/classes/DJICompensationS3Service.php';
        return new DJICompensationS3Service();
    }

    public function isLive()
    {
        return (bool) Configuration::get('SNT_DJI_COMPENSATION_LIVE_MODE');
    }

    public function hasTemplate()
    {
        $filename = Configuration::get('SNT_DJI_COMPENSATION_TEMPLATE_FILE');
        if (empty($filename)) {
            return false;
        }
        return file_exists(_PS_MODULE_DIR_ . $this->name . '/uploads/templates/' . $filename);
    }

    public function getXlsxTemplatePath()
    {
        $filename = Configuration::get('SNT_DJI_COMPENSATION_TEMPLATE_FILE');
        return _PS_MODULE_DIR_ . $this->name . '/uploads/templates/' . $filename;
    }

    /**
     * Ligne d'historique du template actuellement actif.
     *
     * La comparaison se fait sur le nom de fichier plutot que sur is_active :
     * c'est SNT_DJI_COMPENSATION_TEMPLATE_FILE qui fait foi pour le fichier
     * reellement servi aux clients.
     *
     * @return array|null null si le template actif n'a pas de ligne
     *                    d'historique (depose avant sa mise en place)
     */
    public function getActiveTemplate()
    {
        $filename = Configuration::get('SNT_DJI_COMPENSATION_TEMPLATE_FILE');
        if (empty($filename)) {
            return null;
        }

        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'dji_compensation_template_history`
             WHERE filename = "' . pSQL($filename) . '"
             ORDER BY date_add DESC'
        );

        if (!$row) {
            // Pas d'historique : on renvoie tout de meme le nom de fichier pour
            // que la comparaison reste possible, sans date de mise en ligne.
            return ['id_template' => null, 'filename' => $filename, 'date_add' => null];
        }

        return $row;
    }

    /**
     * Recupere l'historique des templates
     */
    public function getTemplateHistory()
    {
        $sql = 'SELECT th.*, e.firstname, e.lastname
                FROM `' . _DB_PREFIX_ . 'dji_compensation_template_history` th
                LEFT JOIN `' . _DB_PREFIX_ . 'employee` e ON th.id_employee = e.id_employee
                ORDER BY th.date_add DESC
                LIMIT 20';

        $history = Db::getInstance()->executeS($sql);
        if (!$history) {
            return [];
        }

        $uploadDir = _PS_MODULE_DIR_ . $this->name . '/uploads/templates/';
        foreach ($history as &$item) {
            $item['file_exists'] = file_exists($uploadDir . $item['filename']);
            $item['file_size_formatted'] = $this->formatFileSize($item['file_size']);
            $item['employee_name'] = $item['firstname'] && $item['lastname']
                ? $item['firstname'] . ' ' . $item['lastname']
                : $this->l('Systeme');
        }

        return $history;
    }

    /**
     * Restaure un ancien template
     */
    protected function processRestoreTemplate()
    {
        $idTemplate = (int) Tools::getValue('id_template');
        if (!$idTemplate) {
            return $this->displayError($this->l('Template non specifie.'));
        }

        $template = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'dji_compensation_template_history`
             WHERE id_template = ' . $idTemplate
        );

        if (!$template) {
            return $this->displayError($this->l('Template non trouve.'));
        }

        $uploadDir = _PS_MODULE_DIR_ . $this->name . '/uploads/templates/';
        if (!file_exists($uploadDir . $template['filename'])) {
            return $this->displayError($this->l('Le fichier template n\'existe plus sur le serveur.'));
        }

        // Desactiver tous les templates
        Db::getInstance()->update('dji_compensation_template_history', ['is_active' => 0], '1');

        // Activer le template selectionne
        Db::getInstance()->update('dji_compensation_template_history', ['is_active' => 1], 'id_template = ' . $idTemplate);

        // Mettre a jour la configuration
        Configuration::updateValue('SNT_DJI_COMPENSATION_TEMPLATE_FILE', $template['filename']);

        return $this->displayConfirmation(sprintf(
            $this->l('Template "%s" restaure avec succes.'),
            $template['original_filename']
        ))
            . $this->notifyPromotionsSync($idTemplate, $template['filename']);
    }

    /**
     * Formate une taille de fichier
     */
    protected function formatFileSize($bytes)
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' Mo';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' Ko';
        }
        return $bytes . ' octets';
    }
}

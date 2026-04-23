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

class DJICompensationMailService
{
    const TEMPLATE_UPLOAD_ERROR = 'upload_error';

    /**
     * Envoie un email de notification d'erreur d'upload
     *
     * @param int $idCustomer ID du client
     * @param string $errorType Type d'erreur (xlsx, invoice, stock_proof, s3)
     * @param string $errorMessage Message d'erreur detaille
     * @param string|null $filename Nom du fichier concerne
     * @param int|null $idDeclaration ID de la declaration
     * @return bool
     */
    public static function sendUploadErrorNotification(
        $idCustomer,
        $errorType,
        $errorMessage,
        $filename = null,
        $idDeclaration = null
    ) {
        $adminEmail = Configuration::get('SNT_DJI_COMPENSATION_ADMIN_EMAIL');
        if (empty($adminEmail)) {
            $adminEmail = Configuration::get('PS_SHOP_EMAIL');
        }

        if (empty($adminEmail)) {
            PrestaShopLogger::addLog(
                'DJI Compensation: Impossible d\'envoyer l\'email d\'erreur - aucune adresse configuree',
                2,
                null,
                'DJICompensationMailService'
            );
            return false;
        }

        $customer = new Customer($idCustomer);
        $customerName = $customer->firstname . ' ' . $customer->lastname;
        $customerEmail = $customer->email;
        $customerCompany = $customer->company ?: '-';

        $errorTypeLabels = [
            'xlsx' => 'Fichier XLSX',
            'invoice' => 'Facture PDF',
            'stock_proof' => 'Photo de stock',
            's3' => 'Stockage S3',
            'declaration' => 'Declaration',
        ];

        $errorTypeLabel = $errorTypeLabels[$errorType] ?? $errorType;

        $templateVars = [
            '{shop_name}' => Configuration::get('PS_SHOP_NAME'),
            '{error_type}' => $errorTypeLabel,
            '{error_message}' => $errorMessage,
            '{customer_name}' => $customerName,
            '{customer_email}' => $customerEmail,
            '{customer_company}' => $customerCompany,
            '{customer_id}' => $idCustomer,
            '{declaration_id}' => $idDeclaration ?: '-',
            '{filename}' => $filename ?: '-',
            '{date}' => date('d/m/Y H:i:s'),
        ];

        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $modulePath = _PS_MODULE_DIR_ . 'snt_dji_compensation/';

        // Verifier si les templates existent
        $templatePath = $modulePath . 'mails/';
        if (!file_exists($templatePath . Language::getIsoById($idLang) . '/' . self::TEMPLATE_UPLOAD_ERROR . '.html')) {
            // Fallback sur le template francais
            if (!file_exists($templatePath . 'fr/' . self::TEMPLATE_UPLOAD_ERROR . '.html')) {
                // Envoyer un email simple sans template
                return self::sendSimpleErrorEmail($adminEmail, $templateVars);
            }
        }

        try {
            return Mail::Send(
                $idLang,
                self::TEMPLATE_UPLOAD_ERROR,
                sprintf('[%s] Erreur d\'upload - Compensation DJI', $errorTypeLabel),
                $templateVars,
                $adminEmail,
                null,
                null,
                null,
                null,
                null,
                $modulePath . 'mails/',
                false,
                null
            );
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'DJI Compensation Mail Error: ' . $e->getMessage(),
                3,
                null,
                'DJICompensationMailService'
            );
            return self::sendSimpleErrorEmail($adminEmail, $templateVars);
        }
    }

    /**
     * Envoie un email simple sans template en cas de fallback
     *
     * @param string $to Adresse destinataire
     * @param array $vars Variables du template
     * @return bool
     */
    private static function sendSimpleErrorEmail($to, $vars)
    {
        $subject = sprintf(
            '[%s] Erreur d\'upload - Compensation DJI - %s',
            $vars['{error_type}'],
            $vars['{shop_name}']
        );

        $body = sprintf(
            "Une erreur d'upload s'est produite sur le module Compensation DJI.\n\n" .
            "Type d'erreur : %s\n" .
            "Message : %s\n\n" .
            "Client : %s (%s)\n" .
            "Societe : %s\n" .
            "ID Client : %s\n" .
            "ID Declaration : %s\n" .
            "Fichier : %s\n" .
            "Date : %s\n",
            $vars['{error_type}'],
            $vars['{error_message}'],
            $vars['{customer_name}'],
            $vars['{customer_email}'],
            $vars['{customer_company}'],
            $vars['{customer_id}'],
            $vars['{declaration_id}'],
            $vars['{filename}'],
            $vars['{date}']
        );

        try {
            return Mail::Send(
                (int) Configuration::get('PS_LANG_DEFAULT'),
                'contact',
                $subject,
                [
                    '{message}' => nl2br($body),
                    '{email}' => $vars['{customer_email}'],
                ],
                $to
            );
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'DJI Compensation Simple Mail Error: ' . $e->getMessage(),
                3,
                null,
                'DJICompensationMailService'
            );
            return false;
        }
    }

    /**
     * Log une erreur d'upload
     *
     * @param string $errorType Type d'erreur
     * @param string $errorMessage Message d'erreur
     * @param int|null $idCustomer ID du client
     * @param int|null $idDeclaration ID de la declaration
     */
    public static function logUploadError($errorType, $errorMessage, $idCustomer = null, $idDeclaration = null)
    {
        $logMessage = sprintf(
            'Upload Error [%s]: %s | Customer: %d | Declaration: %s',
            $errorType,
            $errorMessage,
            $idCustomer ?: 0,
            $idDeclaration ?: 'N/A'
        );

        PrestaShopLogger::addLog(
            $logMessage,
            3,
            null,
            'DJICompensation',
            $idDeclaration ?: 0
        );
    }
}

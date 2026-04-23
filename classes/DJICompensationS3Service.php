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

class DJICompensationS3Service
{
    private $s3Key;
    private $s3Secret;
    private $s3Bucket;
    private $s3Region;
    private $s3StorageType;

    public function __construct()
    {
        $this->s3Key = Configuration::get('SNT_DJI_COMPENSATION_S3_ACCESS_KEY');
        $this->s3Secret = Configuration::get('SNT_DJI_COMPENSATION_S3_SECRET_KEY');
        $this->s3Bucket = Configuration::get('SNT_DJI_COMPENSATION_S3_BUCKET');
        $this->s3Region = Configuration::get('SNT_DJI_COMPENSATION_S3_REGION') ?: 'fr-par';
        $this->s3StorageType = 'STANDARD';

        if (empty($this->s3Key) || empty($this->s3Secret) || empty($this->s3Bucket)) {
            throw new Exception('Configuration S3 incomplete');
        }
    }

    /**
     * Upload un fichier vers S3
     *
     * @param string $filePath Chemin local du fichier
     * @param string $s3Key Cle S3 (chemin dans le bucket)
     * @param string $contentType Type MIME du fichier
     * @return bool
     */
    public function upload($filePath, $s3Key, $contentType = 'application/octet-stream')
    {
        $date = gmdate('D, d M Y H:i:s T');
        $acl = 'x-amz-acl:private';
        $storageType = 'x-amz-storage-class:' . $this->s3StorageType;

        $stringToSign = "PUT\n\n{$contentType}\n{$date}\n{$acl}\n{$storageType}\n/{$this->s3Bucket}/{$s3Key}";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->s3Secret, true));

        $fileHandle = fopen($filePath, 'r');
        if (!$fileHandle) {
            return false;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->getEndpoint($s3Key));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_PUT, 1);
        curl_setopt($ch, CURLOPT_INFILE, $fileHandle);
        curl_setopt($ch, CURLOPT_INFILESIZE, filesize($filePath));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Host: {$this->s3Bucket}.s3.{$this->s3Region}.scw.cloud",
            "Date: {$date}",
            "Content-Type: {$contentType}",
            $storageType,
            $acl,
            "Authorization: AWS {$this->s3Key}:{$signature}",
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fileHandle);

        if ($httpCode < 200 || $httpCode >= 300) {
            PrestaShopLogger::addLog(
                'DJI Compensation S3 Upload Error: HTTP ' . $httpCode . ' - ' . $result,
                3,
                null,
                'DJICompensationS3Service'
            );
            return false;
        }

        return true;
    }

    /**
     * Telecharge un fichier depuis S3
     *
     * @param string $s3Key Cle S3
     * @return string|false Contenu du fichier ou false
     */
    public function get($s3Key)
    {
        $date = gmdate('D, d M Y H:i:s T');

        $stringToSign = "GET\n\n\n{$date}\n/{$this->s3Bucket}/{$s3Key}";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->s3Secret, true));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->getEndpoint($s3Key));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Host: {$this->s3Bucket}.s3.{$this->s3Region}.scw.cloud",
            "Date: {$date}",
            "Authorization: AWS {$this->s3Key}:{$signature}",
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return $result;
        }

        return false;
    }

    /**
     * Supprime un fichier de S3
     *
     * @param string $s3Key Cle S3
     * @return bool
     */
    public function delete($s3Key)
    {
        $date = gmdate('D, d M Y H:i:s T');

        $stringToSign = "DELETE\n\n\n{$date}\n/{$this->s3Bucket}/{$s3Key}";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->s3Secret, true));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->getEndpoint($s3Key));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Host: {$this->s3Bucket}.s3.{$this->s3Region}.scw.cloud",
            "Date: {$date}",
            "Authorization: AWS {$this->s3Key}:{$signature}",
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode == 204 || $httpCode == 200);
    }

    /**
     * Supprime plusieurs fichiers de S3
     *
     * @param array $s3Keys Liste des cles S3
     * @return array Resultats par fichier
     */
    public function deleteMultiple(array $s3Keys)
    {
        $results = [];

        foreach ($s3Keys as $s3Key) {
            $results[] = [
                'key' => $s3Key,
                'deleted' => $this->delete($s3Key),
            ];
        }

        return $results;
    }

    /**
     * Genere une URL pre-signee pour acces temporaire
     *
     * @param string $s3Key Cle S3
     * @param int $expiresIn Duree de validite en secondes (defaut: 1 heure)
     * @return string
     */
    public function getPresignedUrl($s3Key, $expiresIn = 3600)
    {
        $expires = time() + $expiresIn;

        // Signature V2 simple sans parametres de reponse (plus fiable)
        $stringToSign = "GET\n\n\n{$expires}\n/{$this->s3Bucket}/{$s3Key}";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->s3Secret, true));

        return $this->getEndpoint($s3Key)
            . "?AWSAccessKeyId=" . urlencode($this->s3Key)
            . "&Expires=" . $expires
            . "&Signature=" . urlencode($signature);
    }

    /**
     * Verifie si un fichier existe
     *
     * @param string $s3Key Cle S3
     * @return bool
     */
    public function exists($s3Key)
    {
        $date = gmdate('D, d M Y H:i:s T');

        $stringToSign = "HEAD\n\n\n{$date}\n/{$this->s3Bucket}/{$s3Key}";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->s3Secret, true));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->getEndpoint($s3Key));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Host: {$this->s3Bucket}.s3.{$this->s3Region}.scw.cloud",
            "Date: {$date}",
            "Authorization: AWS {$this->s3Key}:{$signature}",
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode == 200);
    }

    /**
     * Liste les fichiers d'un prefixe (dossier)
     *
     * @param string $prefix Prefixe S3
     * @param int $maxKeys Nombre max de resultats (0 = tous)
     * @return array
     */
    public function listFiles($prefix = '', $maxKeys = 1000)
    {
        $date = gmdate('D, d M Y H:i:s T');
        $requestMaxKeys = ($maxKeys == 0) ? 1000 : $maxKeys;

        $queryParams = "?max-keys={$requestMaxKeys}";
        if (!empty($prefix)) {
            $queryParams .= "&prefix=" . urlencode($prefix);
        }

        $stringToSign = "GET\n\n\n{$date}\n/{$this->s3Bucket}/";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->s3Secret, true));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://{$this->s3Bucket}.s3.{$this->s3Region}.scw.cloud/{$queryParams}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Host: {$this->s3Bucket}.s3.{$this->s3Region}.scw.cloud",
            "Date: {$date}",
            "Authorization: AWS {$this->s3Key}:{$signature}",
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $xml = simplexml_load_string($result);
            $json = json_encode($xml);
            $response = json_decode($json, true);

            $files = [];
            if (isset($response['Contents'])) {
                $contents = $response['Contents'];
                // Gerer le cas ou Contents est un objet simple (1 seul fichier)
                if (isset($contents['Key'])) {
                    $contents = [$contents];
                }
                foreach ($contents as $item) {
                    $files[] = [
                        'key' => $item['Key'],
                        'size' => (int) $item['Size'],
                        'last_modified' => $item['LastModified'],
                    ];
                }
            }

            return $files;
        }

        return [];
    }

    /**
     * Teste la connexion S3
     *
     * @return bool
     */
    public function testConnection()
    {
        $date = gmdate('D, d M Y H:i:s T');

        $stringToSign = "GET\n\n\n{$date}\n/{$this->s3Bucket}/";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->s3Secret, true));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://{$this->s3Bucket}.s3.{$this->s3Region}.scw.cloud/?max-keys=1");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Host: {$this->s3Bucket}.s3.{$this->s3Region}.scw.cloud",
            "Date: {$date}",
            "Authorization: AWS {$this->s3Key}:{$signature}",
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode >= 200 && $httpCode < 300);
    }

    /**
     * Genere la cle S3 pour un fichier de declaration
     *
     * @param int $idCustomer
     * @param int $idDeclaration
     * @param string $type Type de fichier (xlsx, invoice, stock_proof)
     * @param string $filename Nom du fichier
     * @return string
     */
    public static function generateS3Key($idCustomer, $idDeclaration, $type, $filename)
    {
        // Structure: declarations/{customer}/{declaration}/
        $basePath = sprintf('declarations/%d/%d', $idCustomer, $idDeclaration);

        switch ($type) {
            case 'xlsx':
                return $basePath . '/declaration.xlsx';
            case 'invoice':
                return $basePath . '/invoices/' . $filename;
            case 'stock_proof':
                return $basePath . '/stock_proofs/' . $filename;
            default:
                return $basePath . '/' . $filename;
        }
    }

    /**
     * Retourne l'URL de l'endpoint S3
     *
     * @param string $s3Key Cle S3
     * @return string
     */
    private function getEndpoint($s3Key)
    {
        return "https://{$this->s3Bucket}.s3.{$this->s3Region}.scw.cloud/{$s3Key}";
    }

    /**
     * Retourne le nom du bucket
     *
     * @return string
     */
    public function getBucket()
    {
        return $this->s3Bucket;
    }
}

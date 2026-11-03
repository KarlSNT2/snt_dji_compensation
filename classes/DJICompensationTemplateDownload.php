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

/**
 * Journal des telechargements du template de declaration par les clients.
 *
 * Le template est remplace de temps en temps par l'administrateur : un client
 * qui a telecharge la veille peut donc deposer un fichier dont les promotions
 * ne sont plus d'actualite. On garde la trace de la version emportee par chaque
 * client pour pouvoir l'avertir au moment du depot.
 *
 * Table en journal (une ligne par telechargement) : la version courante d'un
 * client est la ligne la plus recente. Cela donne aussi a l'equipe la date a
 * laquelle le client a pris son fichier, utile en cas de litige.
 */
class DJICompensationTemplateDownload extends ObjectModel
{
    public $id_download;
    public $id_customer;
    public $id_template;
    public $filename;
    public $date_add;

    public static $definition = [
        'table' => 'dji_compensation_template_download',
        'primary' => 'id_download',
        'fields' => [
            'id_customer' => [
                'type' => self::TYPE_INT,
                'validate' => 'isUnsignedId',
                'required' => true,
            ],
            'id_template' => [
                'type' => self::TYPE_INT,
                'validate' => 'isUnsignedId',
            ],
            'filename' => [
                'type' => self::TYPE_STRING,
                'validate' => 'isGenericName',
                'required' => true,
                'size' => 255,
            ],
            'date_add' => [
                'type' => self::TYPE_DATE,
                'validate' => 'isDate',
            ],
        ],
    ];

    /**
     * Enregistre un telechargement.
     *
     * @param int         $idCustomer
     * @param int|null    $idTemplate Ligne de dji_compensation_template_history,
     *                                null si le template actif n'y figure pas
     *                                (fichier depose avant la mise en place de
     *                                l'historique).
     * @param string      $filename   Nom du fichier sur le serveur, conserve pour
     *                                pouvoir comparer meme sans id_template.
     * @return bool
     */
    public static function log($idCustomer, $idTemplate, $filename)
    {
        if (!$idCustomer || empty($filename)) {
            return false;
        }

        // 3e argument a true : sans lui, Db::insert() ecrirait la chaine vide
        // plutot que NULL pour un id_template inconnu.
        return (bool) Db::getInstance()->insert(
            self::$definition['table'],
            [
                'id_customer' => (int) $idCustomer,
                'id_template' => $idTemplate ? (int) $idTemplate : null,
                'filename' => pSQL($filename),
                'date_add' => date('Y-m-d H:i:s'),
            ],
            true
        );
    }

    /**
     * Dernier telechargement d'un client, ou false s'il n'a jamais telecharge.
     *
     * @param int $idCustomer
     * @return array|false
     */
    public static function getLastByCustomer($idCustomer)
    {
        if (!$idCustomer) {
            return false;
        }

        $sql = new DbQuery();
        $sql->select('*');
        $sql->from(self::$definition['table']);
        $sql->where('id_customer = ' . (int) $idCustomer);
        $sql->orderBy('date_add DESC, id_download DESC');

        return Db::getInstance()->getRow($sql);
    }

    /**
     * Compare le dernier telechargement d'un client au template actif.
     *
     * Renvoie un tableau decrivant la situation plutot qu'un simple booleen :
     * le front a besoin des dates pour formuler l'avertissement.
     *
     * La comparaison porte sur le nom de fichier et non sur id_template : il
     * est toujours renseigne des deux cotes, la ou id_template manque pour les
     * templates anterieurs a l'historique.
     *
     * @param int        $idCustomer
     * @param array|null $activeTemplate Ligne de dji_compensation_template_history
     *                                   du template actif, ou null si inconnu.
     * @return array {
     *     @type bool        is_outdated    Un avertissement doit etre affiche
     *     @type string|null downloaded_at  Date du telechargement du client
     *     @type string|null updated_at     Date de mise en ligne du template actif
     * }
     */
    public static function checkAgainstActive($idCustomer, $activeTemplate)
    {
        $result = [
            'is_outdated' => false,
            'downloaded_at' => null,
            'updated_at' => null,
        ];

        // Sans template actif identifiable, on ne peut rien affirmer.
        if (empty($activeTemplate['filename'])) {
            return $result;
        }

        $last = self::getLastByCustomer($idCustomer);

        // Client qui n'a jamais telecharge depuis le site : il a pu recevoir le
        // fichier autrement. On se tait plutot que d'alerter a tort.
        if (!$last) {
            return $result;
        }

        $result['downloaded_at'] = $last['date_add'];
        $result['updated_at'] = isset($activeTemplate['date_add']) ? $activeTemplate['date_add'] : null;
        $result['is_outdated'] = ($last['filename'] !== $activeTemplate['filename']);

        return $result;
    }
}

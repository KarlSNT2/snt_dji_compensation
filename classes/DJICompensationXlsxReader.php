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
 * Lecteur XLSX natif sans dependance externe
 * Un fichier XLSX est un ZIP contenant des fichiers XML
 */
class DJICompensationXlsxReader
{
    private $filePath;
    private $zip;
    private $sharedStrings = [];
    private $sheets = [];

    /**
     * @param string $filePath Chemin vers le fichier XLSX
     */
    public function __construct($filePath)
    {
        if (!file_exists($filePath)) {
            throw new Exception('Fichier XLSX introuvable: ' . $filePath);
        }

        $this->filePath = $filePath;
        $this->zip = new ZipArchive();

        if ($this->zip->open($filePath) !== true) {
            throw new Exception('Impossible d\'ouvrir le fichier XLSX');
        }

        $this->loadSharedStrings();
        $this->loadSheetNames();
    }

    /**
     * Ferme le fichier ZIP
     */
    public function __destruct()
    {
        if ($this->zip) {
            $this->zip->close();
        }
    }

    /**
     * Charge les chaines partagees (textes)
     */
    private function loadSharedStrings()
    {
        $content = $this->zip->getFromName('xl/sharedStrings.xml');
        if (!$content) {
            return;
        }

        $xml = simplexml_load_string($content);
        if (!$xml) {
            return;
        }

        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $this->sharedStrings[] = (string) $si->t;
            } elseif (isset($si->r)) {
                // Texte avec formatage multiple
                $text = '';
                foreach ($si->r as $r) {
                    $text .= (string) $r->t;
                }
                $this->sharedStrings[] = $text;
            }
        }
    }

    /**
     * Charge les noms des feuilles
     */
    private function loadSheetNames()
    {
        $content = $this->zip->getFromName('xl/workbook.xml');
        if (!$content) {
            return;
        }

        $xml = simplexml_load_string($content);
        if (!$xml) {
            return;
        }

        $index = 1;
        foreach ($xml->sheets->sheet as $sheet) {
            $this->sheets[] = [
                'index' => $index,
                'name' => (string) $sheet['name'],
            ];
            $index++;
        }
    }

    /**
     * Retourne la liste des feuilles
     */
    public function getSheets()
    {
        return $this->sheets;
    }

    /**
     * Lit une feuille et retourne les donnees
     *
     * @param int $sheetIndex Index de la feuille (1-based)
     * @param int $headersRow Ligne des en-tetes (1-based)
     * @param int $limit Nombre max de lignes (0 = toutes)
     * @param int $offset Decalage
     * @return array
     */
    public function readSheet($sheetIndex = 1, $headersRow = 1, $limit = 0, $offset = 0)
    {
        $sheetFile = 'xl/worksheets/sheet' . $sheetIndex . '.xml';
        $content = $this->zip->getFromName($sheetFile);

        if (!$content) {
            throw new Exception('Feuille introuvable: ' . $sheetIndex);
        }

        $xml = simplexml_load_string($content);
        if (!$xml) {
            throw new Exception('Impossible de parser la feuille');
        }

        // Extraire toutes les lignes
        $rows = [];
        $maxCol = 0;

        foreach ($xml->sheetData->row as $row) {
            $rowIndex = (int) $row['r'];
            $rowData = [];

            foreach ($row->c as $cell) {
                $cellRef = (string) $cell['r'];
                $colIndex = $this->columnToIndex($cellRef);
                $maxCol = max($maxCol, $colIndex);

                $value = $this->getCellValue($cell);
                $rowData[$colIndex] = $value;
            }

            $rows[$rowIndex] = $rowData;
        }

        // Extraire les headers
        if (!isset($rows[$headersRow])) {
            throw new Exception('Ligne d\'en-tetes introuvable: ' . $headersRow);
        }

        $headers = [];
        for ($i = 0; $i <= $maxCol; $i++) {
            $headers[$i] = isset($rows[$headersRow][$i]) ? $rows[$headersRow][$i] : 'Column' . ($i + 1);
        }

        // Construire les donnees avec headers
        $data = [];
        $dataRowIndex = 0;
        $skipped = 0;

        foreach ($rows as $rowIndex => $rowData) {
            // Ignorer la ligne des headers
            if ($rowIndex <= $headersRow) {
                continue;
            }

            // Gerer l'offset
            if ($skipped < $offset) {
                $skipped++;
                continue;
            }

            // Gerer la limite
            if ($limit > 0 && $dataRowIndex >= $limit) {
                break;
            }

            // Construire l'objet avec les headers comme cles
            $item = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $header = $headers[$i];
                $value = isset($rowData[$i]) ? $rowData[$i] : '';
                $item[$header] = $value;
            }

            $data[] = $item;
            $dataRowIndex++;
        }

        return [
            'headers' => array_values($headers),
            'data' => $data,
            'total_rows' => count($rows) - 1, // Exclure la ligne des headers
        ];
    }

    /**
     * Cherche une feuille dont le nom contient l'un des fragments donnes.
     *
     * Le nom de la feuille SN a change au fil des versions du template
     * ("Matrice Baisses de prix", "Numeros SN ", "Matrice declaration SN") et
     * un client peut deposer un fichier telecharge il y a plusieurs mois.
     *
     * @param array $needles Fragments recherches, compares sans casse ni accents
     * @return array|null La feuille trouvee (index, name), null sinon
     */
    public function findSheetByAliases(array $needles)
    {
        foreach ($needles as $needle) {
            $normNeedle = $this->normalizeLabel($needle);
            if ($normNeedle === '') {
                continue;
            }
            foreach ($this->sheets as $sheet) {
                if (strpos($this->normalizeLabel($sheet['name']), $normNeedle) !== false) {
                    return $sheet;
                }
            }
        }

        return null;
    }

    /**
     * Cherche la ligne d'en-tetes d'une feuille : celle qui contient le plus
     * de libelles attendus.
     *
     * La feuille SN fait preceder ses en-tetes d'un bloc de consignes, ils ne
     * sont donc pas en ligne 1. Plutot que de figer un numero de ligne qui
     * bougera a la prochaine version du template, on la reconnait a son contenu.
     *
     * @param int   $sheetIndex
     * @param array $needles    Fragments de libelles attendus
     * @param int   $minMatches Nombre minimum de fragments pour valider la ligne
     * @param int   $maxScan    Nombre de lignes explorees depuis le haut
     * @return int|null Index 1-based de la ligne, null si aucune ne convient
     */
    public function findHeaderRow($sheetIndex, array $needles, $minMatches = 3, $maxScan = 15)
    {
        $sheetFile = 'xl/worksheets/sheet' . (int) $sheetIndex . '.xml';
        $content = $this->zip->getFromName($sheetFile);
        if (!$content) {
            return null;
        }

        $xml = simplexml_load_string($content);
        if (!$xml) {
            return null;
        }

        $normNeedles = [];
        foreach ($needles as $needle) {
            $norm = $this->normalizeLabel($needle);
            if ($norm !== '') {
                $normNeedles[] = $norm;
            }
        }

        if (empty($normNeedles)) {
            return null;
        }

        $bestRow = null;
        $bestScore = 0;

        foreach ($xml->sheetData->row as $row) {
            $rowIndex = (int) $row['r'];
            if ($rowIndex > $maxScan) {
                break;
            }

            // Un en-tete est un assemblage de libelles courts : on concatene la
            // ligne et on compte les fragments presents.
            $haystack = '';
            foreach ($row->c as $cell) {
                $haystack .= ' ' . $this->normalizeLabel($this->getCellValue($cell));
            }

            $score = 0;
            foreach ($normNeedles as $norm) {
                if (strpos($haystack, $norm) !== false) {
                    $score++;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRow = $rowIndex;
            }
        }

        return $bestScore >= $minMatches ? $bestRow : null;
    }

    /**
     * Normalise un libelle pour comparaison : accents retires, espaces et
     * retours ligne reduits, minuscules.
     */
    private function normalizeLabel($label)
    {
        if (is_bool($label) || $label === null) {
            return '';
        }

        // \s couvre espaces, tabulations et retours ligne : les en-tetes du
        // template en contiennent (libelles sur plusieurs lignes).
        $label = preg_replace('/\s+/u', ' ', $label);
        $label = trim($label);

        if ($label === '') {
            return '';
        }

        $label = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);

        // Translitteration manuelle : iconv depend de la locale et Transliterator
        // n'est pas garanti sur tous les hebergements.
        $accents = [
            'a' => ['à', 'á', 'â', 'ã', 'ä', 'å'],
            'c' => ['ç'],
            'e' => ['è', 'é', 'ê', 'ë'],
            'i' => ['ì', 'í', 'î', 'ï'],
            'n' => ['ñ'],
            'o' => ['ò', 'ó', 'ô', 'õ', 'ö'],
            'u' => ['ù', 'ú', 'û', 'ü'],
            'y' => ['ý', 'ÿ'],
        ];
        foreach ($accents as $plain => $variants) {
            $label = str_replace($variants, $plain, $label);
        }

        return $label;
    }

    /**
     * Lit une feuille par son nom
     *
     * @param string $sheetName Nom de la feuille
     * @param int $headersRow Ligne des en-tetes
     * @param int $limit Nombre max de lignes
     * @param int $offset Decalage
     * @return array
     */
    public function readSheetByName($sheetName, $headersRow = 1, $limit = 0, $offset = 0)
    {
        foreach ($this->sheets as $sheet) {
            if (strcasecmp($sheet['name'], $sheetName) === 0) {
                return $this->readSheet($sheet['index'], $headersRow, $limit, $offset);
            }
        }

        throw new Exception('Feuille introuvable: ' . $sheetName);
    }

    /**
     * Extrait la valeur d'une cellule
     */
    private function getCellValue($cell)
    {
        $type = (string) $cell['t'];
        $value = (string) $cell->v;

        switch ($type) {
            case 's': // Shared string
                $index = (int) $value;
                return isset($this->sharedStrings[$index]) ? $this->sharedStrings[$index] : '';

            case 'b': // Boolean
                return $value === '1' ? true : false;

            case 'e': // Error
                return '#ERROR';

            case 'str': // Formula string
            case 'inlineStr': // Inline string
                if (isset($cell->is->t)) {
                    return (string) $cell->is->t;
                }
                return $value;

            default: // Number or other
                // Verifier si c'est une date (heuristique simple)
                if (is_numeric($value) && $value > 25569 && $value < 50000) {
                    // Pourrait etre une date Excel, mais on retourne la valeur brute
                    // pour eviter les erreurs de conversion
                }
                return $value;
        }
    }

    /**
     * Convertit une reference de colonne (A, B, AA, etc.) en index numerique
     */
    private function columnToIndex($cellRef)
    {
        preg_match('/^([A-Z]+)/', $cellRef, $matches);
        if (!isset($matches[1])) {
            return 0;
        }

        $col = $matches[1];
        $index = 0;
        $length = strlen($col);

        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($col[$i]) - ord('A') + 1);
        }

        return $index - 1; // 0-based
    }
}

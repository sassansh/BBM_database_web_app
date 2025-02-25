<?php

require_once('vendor/autoload.php');
require_once ('credentials_controller.php');
require_once ('my_autoloader.php');

use airmoi\FileMaker\FileMaker;
use airmoi\FileMaker\FileMakerException;
use airmoi\FileMaker\Object\Field;
use airmoi\FileMaker\Object\Layout;
use airmoi\FileMaker\Object\Result;

/**
 * Class DatabaseSearch
 * Represents a database the client is currently searching in.
 */
class DatabaseSearch {

    /**
     * A list of all the valid Database names!
     * @var array|string[]
     */
    static array $ValidNames = ['algae', 'avian', 'bryophytes', 'entomology', 'fish',
    'fossil', 'fungi', 'herpetology', 'lichen', 'mammal', 'mi', 'miw', 'vwsp'];

    /**
     * The FileMaker instance connected to this database
     * @var FileMaker
     */
    private FileMaker $fileMaker;

    /**
     * The name of this database
     * @var string 
     */
    private string $name;

    private ?Layout $search_layout;
    private ?Layout $result_layout;
    private ?Layout $detail_layout;

    /**
     * @throws FileMakerException
     */
    function __construct($fileMaker, $database) {
        $this->fileMaker = $fileMaker;
        $this->name = $database;

        $this->setLayouts();
    }

    /**
     * Creates a object from the database name
     * @param string $databaseName
     * @return DatabaseSearch|false
     * @throws FileMakerException
     */
    public static function fromDatabaseName(string $databaseName): bool|DatabaseSearch
    {
        list($FM_FILE, $FM_HOST, $FM_USER, $FM_PASS) = getDBCredentials($databaseName);

        if (!$FM_PASS or !$FM_FILE or !$FM_HOST or !$FM_USER) {
            return false;
        }

        $fileMaker = new FileMaker($FM_FILE, $FM_HOST, $FM_USER, $FM_PASS);

        return new self($fileMaker, $databaseName);
    }

    public function getFileMaker(): FileMaker
    {
        return $this->fileMaker;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSearchLayout(): Layout
    {
        return $this->search_layout;
    }

    public function getResultLayout(): Layout
    {
        return $this->result_layout;
    }

    public function getDetailLayout(): Layout
    {
        return $this->detail_layout;
    }

    /**
     * @return bool True if this database has images available for users to see
     */
    public function hasImages(): bool {
        $databases_with_images = ['fish', 'avian', 'herpetology', 'mammal', 'vwsp', 'bryophytes',
            'fungi', 'lichen', 'algae'];
        return in_array($this->name, $databases_with_images);
    }

    /**
     * @return string The name of this database in a clean and readable format
     */
    public function getCleanName(): string {
        return match ($this->name) {
            "mi" => "Dry Marine Invertebrate",
            "miw" => "Wet Marine Invertebrate",
            "vwsp" => "Vascular",
            default => ucfirst($this->name)
        };
    }

    /**
     * Searches all available layouts from FMP and sets the correct Search and
     * Result layout for this DatabaseSearch.
     *
     * All databases have a search, results and details layout. However, the MIW and MI databases have two of each
     * one for MI and one for MIW on the same database. Therefore the extra if statement.
     * @throws FileMakerException
     */
    private function setLayouts() {
        # list of layout names!
        $availableLayouts = $this->fileMaker->listLayouts();

        foreach ($availableLayouts as $layoutName){
            if ($this->name === 'mi' or $this->name === 'miw') {
                if ($layoutName == 'search-'.strtoupper($this->name)) {
                    $this->search_layout = $this->fileMaker->getLayout($layoutName);
                } else if ($layoutName == 'results-'.strtoupper($this->name)) {
                    $this->result_layout = $this->fileMaker->getLayout($layoutName);
                } else if ($layoutName == 'details-'.strtoupper($this->name)) {
                    $this->detail_layout = $this->fileMaker->getLayout($layoutName);
                }
            } else {
                if (str_contains($layoutName, 'search')) {
                    $this->search_layout = $this->fileMaker->getLayout($layoutName);
                } else if (str_contains($layoutName, 'results')) {
                    $this->result_layout = $this->fileMaker->getLayout($layoutName);
                } else if (str_contains($layoutName, 'details')) {
                    $this->detail_layout = $this->fileMaker->getLayout($layoutName);
                }
            }
        }
    }

    /**
     * Will clean out the field to now show the ignored fields.
     * @return Field[]
     */
    public function getSearchFields(): array {
        # TODO move this to database
        $ignoreValues = ['SortNum' => '', 'Accession Numerical' => '', 'Imaged' => '', 'IIFRNo' => '',
            'Photographs::photoFileName' => '', 'Event::eventDate' => '', 'card01' => '', 'Has Image' => '',
            'imaged' => ''];

        // If algae database, add Class to ignore
        if ($this->name === 'algae') {
            $ignoreValues['Class'] = '';
        }
        return array_diff_key($this->search_layout->getFields(), $ignoreValues);
    }

    /**
     * Will query the FileMakerPro API for a result item. The query takes query fields,
     * and also deals with:
     * - data pagination with the pageNumber field
     * - sorting the data with the sortType and sortQuery
     *
     * @param int $maxResponseAmount amount of responses to query for
     * @param string[] $getFields query fields to use, must be not empty
     * @param string $logicalOperator on of 'or' or 'and'
     * @param string|null $sortQuery
     * @param int $pageNumber used to calculate a multiplier of maxResponseAmount for pagination
     * @param string|null $sortType one of ascend or descend
     * @return Result Result or Error (Error if no entries for query too)
     * @throws FileMakerException
     */
    public function queryForResults(int $maxResponseAmount, array $getFields, string $logicalOperator, ?string $sortQuery,
                             int $pageNumber, ?string $sortType): Result
    {

        // Find on all inputs with values
        $findCommand = $this->fileMaker->newFindCommand($this->search_layout->getName());

        $findCommand->setLogicalOperator(operator: strtolower($logicalOperator) == 'or' ? FileMaker::FIND_OR : FileMaker::FIND_AND);

        /**
         * TODO Fix the Fossils collection, searching is not working! Not even in deployed app.
         */

        # handle all regular search fields
        foreach ($getFields as $fieldName => $fieldValue) {

            if ($this->name == 'vwsp' && str_contains($fieldName, 'Family')) {
                $layoutField = 'Family_Genus list::Family';
            } else {
                $layoutField = str_replace("_", " ", $fieldName);
            }


            # handle image field
            if ($fieldName == 'hasImage') {
                $layoutField = 'Photographs::photoFileName' or 'Imaged'; # TODO fix this!

                $findCommand->addFindCriterion(
                    fieldName: $layoutField,
                    value: $this->name == 'entomology' ? 'Photographed' : '*'
                );
            }
            # handle accession number 'ID' field
            else if ($fieldName == $this->getIDFieldName()) {
                $findCommand->addFindCriterion(
                    fieldName: $this->getIDFieldName(is_numeric($fieldValue)),
                    value: $fieldValue
                );
            }

            # all other fields just go in as they come
            else {
                $findCommand->addFindCriterion($layoutField, $fieldValue);
            }
        }

        # handle the sort property
        if (isset($sortQuery) and $sortQuery != '') {

            # preliminary sort query, will only change in certain situations
            $sortBy = $sortQuery;

            # accession number sort is different for databases, handle it here
            if (Specimen::mapFieldName($sortQuery) === 'Accession Number') {
                if ($this->name == 'vwsp' or $this->name == 'bryophytes' or
                    $this->name == 'fungi' or $this->name == 'lichen' or $this->name == 'algae') {
                    $sortBy = 'Accession Numerical';
                }
                else {
                    $sortBy = 'sortNum';
                }
            }

            if($this->name == 'fish') {
                $sortBy = 'accessionNo';
            }

            # handles the order of the sort
            $findCommand->addSortRule(fieldName:  str_replace('+', ' ', $sortBy), precedence: 1,
                order: $sortType === 'Descend' ? FileMaker::SORT_DESCEND : FileMaker::SORT_ASCEND);
        }

        # handle different table pages
        if (isset($pageNumber)) {
            $findCommand->setRange(skip: ($pageNumber - 1) * $maxResponseAmount, max: $maxResponseAmount);
        }

        return $findCommand->execute();
    }

    /**
     * Query FM over all taxon values with same value with an 'OR' operator.
     * @param string $searchText
     * @param int $maxResponseAmount
     * @param int $pageNumber
     * @return Result
     * @throws FileMakerException
     */
    public function queryTaxonSearch(string $searchText, ?string $sortQuery, ?string $sortType,
                                     int $maxResponseAmount = 50, int $pageNumber = 1): Result
    {
        $findCommand = $this->fileMaker->newFindCommand($this->search_layout->getName());
        $findCommand->setLogicalOperator(operator: FileMaker::FIND_OR);

        # TODO move this to a database and UI to change options
        $taxonFields = match ($this->name) {
            "avian", "herpetology", "mammal" => array('Taxon::order', 'Taxon::family', 'Taxon::phylum', 'Taxon::genus', 'Taxon::class', 'Taxon::specificEpithet', 'Taxon::infraspecificEpithet'),
            "entomology" => array('Phylum', 'Class', 'Order', 'Family', 'Genus', 'Species', 'Subspecies'),
            "algae" => array('Phylum', 'Class', 'Genus', 'Species', 'Subspecies'),
            "bryophytes"  => array('Family', 'Genus', 'Species', 'Subspecies'),
            "fungi", "lichen"   => array('Genus', 'Species'),
            "vwsp" => array('Family_Genus list::Family', 'Genus', 'Species', 'Subspecies'),
            "fish" => array('Class', 'Order', 'Family', 'Subfamily', 'nomenNoun', 'specificEpithet'),
            "miw" => array('Phylum', 'Class', 'Family', 'Genus', 'Species'),
            "mi" => array('Phylum', 'Class', 'Family', 'Genus', 'Specific epithet'),
            "fossil" => array('Phylum', 'Class', 'Family', 'Genus', 'Kingdom', 'Subphylum', 'Superclass', 'Subclass', 'Order', 'Suborder', 'Species', 'Common Name'),
        };

        $searchFieldNames = $this->search_layout->listFields();

        foreach ($taxonFields as $fieldName) {

            # check to make sure the field name is valid in the search layout
            # if a wrong field name is used a (Table not found) error is thrown by FMP
            if (in_array($fieldName, $searchFieldNames)) {
                $findCommand->addFindCriterion(
                    fieldName: $fieldName, value: $searchText
                );
            }

        }

        # separate the search text into words for Genus + Species Search
        $words = explode(' ', $searchText);

        # Perform new find command for Genus + Species Search if 2 words are entered
        if (count($words) == 2 && strlen($words[0]) > 0 && strlen($words[1]) > 0){
            $findCommand = $this->fileMaker->newFindCommand($this->search_layout->getName());
            $findCommand->setLogicalOperator(operator: FileMaker::FIND_AND);

            // Entomology, Algae, Bryophytes, Fungi, Lichen, VWSP, MIW, Fossil
            if (in_array('Genus', $searchFieldNames)) {
                $findCommand->addFindCriterion(
                    fieldName: 'Genus', value: $words[0]
                );
            }

            if (in_array('Species', $searchFieldNames)) {
                $findCommand->addFindCriterion(
                    fieldName: 'Species', value: $words[1]
                );
            }

            // Fish: nomenNoun (Genus) + specificEpithet (Species)
            if (in_array('nomenNoun', $searchFieldNames)) {
                $findCommand->addFindCriterion(
                    fieldName: 'nomenNoun', value: $words[0]
                );
            }

            if (in_array('specificEpithet', $searchFieldNames)) {
                $findCommand->addFindCriterion(
                    fieldName: 'specificEpithet', value: $words[1]
                );
            }

            // MI
            if (in_array('Specific epithet', $searchFieldNames)) {
                $findCommand->addFindCriterion(
                    fieldName: 'Specific epithet', value: $words[1]
                );
            }


            // Avian, Herpetology, Mammal
            if (in_array('Taxon::genus', $searchFieldNames)) {
                $findCommand->addFindCriterion(
                    fieldName: 'Taxon::genus', value: $words[0]
                );
            }

            if (in_array('Taxon::specificEpithet', $searchFieldNames)) {
                $findCommand->addFindCriterion(
                    fieldName: 'Taxon::specificEpithet', value: $words[1]
                );
            }

        }


        # handle the sort property
        if (isset($sortQuery) and $sortQuery != '') {

            # preliminary sort query, will only change in certain situations
            $sortBy = $sortQuery;

            # accession number sort is different for databases, handle it here
            if (Specimen::mapFieldName($sortQuery) === 'Accession Number') {
                if ($this->name == 'vwsp' or $this->name == 'bryophytes' or
                    $this->name == 'fungi' or $this->name == 'lichen' or $this->name == 'algae') {
                    $sortBy = 'Accession Numerical';
                }
                else {
                    $sortBy = 'sortNum';
                }
            }

            if($this->name == 'fish') {
                $sortBy = 'accessionNo';
            }

            # handles the order of the sort
            $findCommand->addSortRule(fieldName:  str_replace('+', ' ', $sortBy), precedence: 1,
                order: $sortType === 'Descend' ? FileMaker::SORT_DESCEND : FileMaker::SORT_ASCEND);
        } else {
            if($this->name == 'entomology') {
                $sortBy = 'SEM #';
                $findCommand->addSortRule(fieldName:  str_replace('+', ' ', $sortBy), precedence: 1,
                    order: FileMaker::SORT_ASCEND);
            }

        }

        $findCommand->setRange(skip: ($pageNumber - 1) * $maxResponseAmount, max: $maxResponseAmount);

        return $findCommand->execute();
    }

    /**
     * Returns the ID or accession number field name, it is different for different databases.
     * Optional $isNumeric field will change the name used for some databases.
     * @param bool $isNumeric
     * @return string
     */
    public function getIDFieldName(bool $isNumeric = false): string
    {
        return match ($this->name) {
            'vwsp', 'bryophytes', 'fungi', 'lichen', 'algae' => $isNumeric ? 'Accession Numerical' : 'Accession Number',
            'avian', 'herpetology', 'mammal' => $isNumeric ? 'SortNum' : 'catalogNumber',
            'mi', 'miw' => $isNumeric ? 'SortNum' : 'Accession No',
            'fish' => 'ID',
            'entomology' => 'SEM #',
            'fossil' => 'Catalogue Number'
        };
    }
}
<?php namespace Modules\CupChart\Services\Importazione;



/*
 * CLASSE PER GESTIRE LA FASE DI UPLOADI UN FILE.
 * EVENTUALMENTE DA FARE COME PROVIDER E FACADE IN FUTURO.
 * PER ORA SERVIZIO AL VOLO COME SINGLETON.
 */

use App\Models\Importazione;
use App\Models\ImportazioneTabella;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use stringEncode\Exception;

class SplitStrictTablesService
{

    use SpreadsheetTrait;

    protected $importazione;
    protected $objectReader;
    protected $dataFile;

    protected $sheets = [];

    public function __construct(Importazione $importazione)
    {
        $this->importazione = $importazione;
//        if (!$this->importazione->fileExists()) {
//            throw new \Exception($importazione->getAbsoluteStorageFilename() . " File di origine non trovato.");
//        }
        $this->dataFile = storage_path($importazione->getAbsoluteStorageFilename());
        $this->setObjectReader();
    }

    /**
     * @return array
     */
    public function getSheets(): array
    {
        return $this->sheets;
    }


    protected function setObjectReader()
    {

        $inputFileType = IOFactory::identify($this->dataFile);
        $this->objectReader = IOFactory::createReader($inputFileType);

        try {
            //Carico il foglio indicato nella cofnigurazione
            //Se non presente carico il foglio 0;
            $this->sheets = $this->objectReader->listWorksheetInfo($this->dataFile);
            foreach ($this->sheets as $key => $sheet) {
                $this->sheets[$key]['lastColumnIndex'] = Coordinate::columnIndexFromString($this->sheets[$key]['lastColumnLetter']);
            }
            $this->setSheetsToImportazioneData();
        } catch (\Exception $e) {
            $msg = 'Problemi ad aprire il file: non sembra un file salvato correttamente come file excel. Provare ad aprirlo con Excel e salvarlo nuovamente.<br/>';
            $msg .= $e->getMessage();
            throw new \Exception($msg);
        }
    }

    public function getObjectReader()
    {
        return $this->objectReader;
    }

    public function setSheetsToImportazioneData()
    {

        $importazioneData = $this->importazione->data;
        $sheetsData = [];
        $i = 1;
        foreach ($this->sheets as $sheetInfo) {
            $sheetName = Arr::get($sheetInfo, 'worksheetName', $i);
            unset($sheetInfo['worksheetName']);
            $sheetsData[$sheetName] = $sheetInfo;
            $i++;
        }
        $importazioneData['sheets'] = $sheetsData;
        $this->importazione->data = $importazioneData;
        $this->importazione->save();

        ImportazioneTabella::where('importazione_id', $this->importazione->getKey())
            ->delete();


    }

    public function getTablesFromSheet($sheetName, $sheetInfo)
    {

        $this->objectReader->setLoadSheetsOnly([$sheetName]);

        $spreadSheet = $this->objectReader->load($this->dataFile);
        $spreadSheet->setActiveSheetIndexByName($sheetName);

        $currentSheet = $spreadSheet->getActiveSheet();

        $nTables = 0;

        $maxColumnIndex = Arr::get($sheetInfo, 'lastColumnIndex', 0);
        $totalRows = Arr::get($sheetInfo, 'totalRows', 0);
        $previousFinalRow = 0;
        Log::info('getTablesFromSheet', ['sheetInfo' => $sheetInfo]);
        for ($i = 1; $i <= Arr::get($sheetInfo, 'totalColumns', 0); $i++) {

            $column = Coordinate::stringFromColumnIndex($i);

            for ($row = 1; $row <= $totalRows; $row++) {

                $coordinate = $column . $row;
                $cell = $currentSheet->getCell($coordinate);
                $dataType = $cell->getDataType();
                $rawValue = $cell->getValue();
                $formattedValue = $cell->getFormattedValue();
                Log::info('getTablesFromSheet', ['coordinate' => $coordinate, 'rawValue' => $rawValue, 'formattedValue' => $formattedValue, 'dataType' => $dataType]);
                if (in_array($cell->getDataType(), [
                    DataType::TYPE_STRING,
                    DataType::TYPE_INLINE,
                ], true) && Str::startsWith($rawValue, 'MD:')) {
                    Log::info('TROVATO MD');
                    //FORMATO RICHIESTO:
                    //
                    //  MD:<NROWS>R:<NCOLUMNS>C
                    //
                    $metadata = explode(':', $rawValue);
                    if (count($metadata) !== 3 || Arr::get($metadata, 0) !== 'MD') {
                        continue;
                    }

                    $headersRows = $metadata[1];
                    if (!Str::endsWith($headersRows, 'R')) {
                        continue;
                    }
                    $headersRows = intval(substr($headersRows, 0, -1));
                    $headersColumns = $metadata[2];
                    if (!Str::endsWith($headersColumns, 'C')) {
                        continue;
                    }

                    //HO TROVATO UNA TABELLA
                    $nTables++;

                    $headersColumns = intval(substr($headersColumns, 0, -1));

                    $initColumn = $column;
                    $initDataColumn = Coordinate::stringFromColumnIndex($i + $headersColumns);
                    $initRow = $row + 1;
                    $initDataRow = $initRow + $headersRows;

                    $finalColumn = $this->getFinalHeaderColumn($currentSheet, $initDataColumn, $initRow, $headersRows,
                        $maxColumnIndex);

                    //C'E' QUALCHE CASINO
                    if ($finalColumn === false) {
                        continue;
                    }

                    $finalColumnIndex = Coordinate::columnIndexFromString($finalColumn);


                    $finalRow = $this->getFinalDataRow($currentSheet, $initRow, $initColumn, $finalColumn,
                        $totalRows);
                    //C'E' QUALCHE CASINO
                    Log::info("TABELLA TROVATA::: " . $coordinate . ' - FINAL COLUMN::: ' . $finalColumn . ' - FINAL ROW::: ' . $finalRow);
                    if ($finalRow === false) {
                        continue;
                    }


                    $title = $this->guessTableTitle($currentSheet, $column, $row, $finalColumn, $previousFinalRow,
                        $nTables);
                    $previousFinalRow = $finalRow;


                    $headers = $this->getTableHeaders($currentSheet, $initColumn, $initRow, $headersColumns,
                        $headersRows, $finalColumnIndex, $finalRow);

                    $extra = $this->guessTableExtra($currentSheet, $column, $row, $finalColumn, $previousFinalRow,
                        $nTables);

                    //In STRICT MODE DEVONO ESSERE PARI SIA LE RIGHE CHE LE COLONNE DELLE INTESTAZIONI
                    // (NOME_SERIE:VALORI)
                    if ($headersRows % 2 !== 0
//                        || $headersColumns % 2 !== 0
                        )
                    {
                        continue;
                    }

                    try {
                        $series = $this->getTableSeries($headers);
                    } catch (\Exception $e) {
                        Log::info($e->getMessage() . " " . $e->getFile() . ":" . $e->getLine());
                        //C'E' QUALCHE CASINO E LE SERIE NON SONO STRICTS
                        continue;
                    }

                    $tableMetadata = [
                        'init' => $initColumn . $initRow,
                        'initData' => $initDataColumn . $initDataRow,
                        'end' => $finalColumn . $finalRow,
                        'headers' => $headers,
                        'series' => $series,
                        'inferredSeries' => $series,
                        'extra' => $extra
                    ];


                    $tableData = [
                        'importazione_id' => $this->importazione->getKey(),
                        'progressivo' => $nTables,
                        'nome' => $title,
                        'sheetname' => $sheetName,
                        'metadata' => json_encode($tableMetadata,true), //$tableMetadata,
                        'elastic_id' => $this->importazione->getKey() . '_' . $sheetName . '_' . $nTables,
                    ];
                    ImportazioneTabella::create($tableData);
                }

                //PER BLOCCARE ALLA PRIMA TABELLA
//                if ($nTables == 1) {
//                    break(2);
//                }


            }


        }

        return $nTables;
    }

    protected function guessTableExtra(
        Worksheet $sheet,
       $metadataColumn,
       $metadataRow,
       $finalColumn,
       $previousFinalRow,
       $nTables)
    {
        // check che la riga successiva alla fine tabella deve essere vuota, poi cerco una delle parole chiavi
        // che mi aspetto finchè non trovo una riga vuota
        $row = $previousFinalRow+1;
        $letter = $metadataColumn;
        $coordinate = $letter . $row;
        $sheet->getCell($coordinate);
        $extra = [
            'note' => [],
            'tipo_valore' => 'valore',
            'suffisso' => '',
            'prefisso' => '',
            'decimali' => 2,
            'filtri_top' => [],
            'filtri_left' => [],
            'grafico' => '',
            'tipo' => 'integer'
        ];
        if ($this->getCalcValue($sheet->getCell($coordinate))) {
            // non c'e' la riga vuota
            return $extra;
        }
        $row+=1;
        $finito = false;
        while (!$finito) {
            $letter = $metadataColumn;
            //$coordinate = $letter.$row;
            $key = trim(strtolower($this->getCalcValue($sheet->getCell($metadataColumn.$row))));
            if (!$key) {
                $finito = true;
                continue;
            }
            switch ($key) {
                case 'note':
                    do {
                        $letter++;
                        //$coordinate = $letter.$row;
                        $val = $this->getCalcValue($sheet->getCell($letter.$row));
                        if ($val)
                            $extra['note'][] = $val;
                    } while($val);
                    break;
                case 'filtri_top':
                case 'filtri_left':
                    do {
                        $letter++;
                        //$coordinate = $letter.$row;
                        $val = $this->getCalcValue($sheet->getCell($letter.$row));
                        Log::info('filtri_left ' . $val);
                        if ($val)
                            $extra[$key][] = $val;
                    } while($val);
                    break;
                case 'tipo_valore':
                case 'tipo':
                case 'suffisso':
                case 'prefisso':
                case 'decimali':
                    // TODO aggiungere eventuale formato nella colonna b
                    $letter++;
                    //$coordinate = $letter.$row;
                    $val = $this->getCalcValue($sheet->getCell($letter.$row));
                    $extra[$key] = $val;
                    break;
                case 'grafico':
                    $letter++;
                    $val = $sheet->getCell($letter.$row)->getValue();
                    $extra[$key] = $val;
                    break;
                default:
                    Log::notice("$key - key non trovata ");
                    $letter++;
                    //$coordinate = $letter.$row;
                    $val = $this->getCalcValue($sheet->getCell($letter.$row));
                    $extra[$key] = $val;
                    break;
            }
            $row++;
        }
        return $extra;
    }

    protected function guessTableTitle(
        Worksheet $sheet,
        $metadataColumn,
        $metadataRow,
        $finalColumn,
        $previousFinalRow,
        $nTables
    )
    {

        $title = 'Tabella ' . $nTables;
        $metadataColumnIndex = Coordinate::columnIndexFromString($metadataColumn);
        $finalColumnIndex = Coordinate::columnIndexFromString($finalColumn);
        $minRow = max(1, $previousFinalRow);

        $trovato = false;

        for ($row = ($metadataRow - 1); $row >= $minRow; $row--) {

            $metadataColumn = Coordinate::stringFromColumnIndex($metadataColumnIndex);
            $coordinate = $metadataColumn . $row;
            $cell = $sheet->getCell($coordinate);
            if (!in_array($cell->getDataType(), [
                DataType::TYPE_STRING,
                DataType::TYPE_INLINE,
            ], true)) {
                continue;
            }
            if ($cell->isMergeRangeValueCell()) {
                $mergeRange = $cell->getMergeRange();
                $finalMergeCoordinate = (explode(':', $mergeRange))[1];
                list($finalMergeColumn, $finalMergeRow) = Coordinate::coordinateFromString($finalMergeCoordinate);
                $finalMergeColumnIndex = Coordinate::columnIndexFromString($finalMergeColumn);
                if ($finalMergeColumnIndex == $finalColumnIndex) {
                    $trovato = true;
                    break;
                }
                if ($finalMergeColumnIndex < $finalColumnIndex) {
                    continue;
                }

            } else {
                $finalMergeColumnIndex = $metadataColumnIndex;
            }

            for ($nextColumnIndex = $finalMergeColumnIndex + 1; $nextColumnIndex <= $finalColumnIndex; $nextColumnIndex++) {
                $nextColumn = Coordinate::stringFromColumnIndex($nextColumnIndex);
                $coordinate = $nextColumn . $row;
                $nextCell = $sheet->getCell($coordinate);
                $value = $nextCell->getValue();
                if (empty($value)) {
                    continue;
                }
            }
            if ($nextColumnIndex > $finalColumnIndex) {
                $trovato = true;
                break;
            }
        }

        if ($trovato) {
            $title = $cell->getFormattedValue();
        }

        return $title;
    }


    protected function getTableHeaders(
        Worksheet $sheet,
        $initColumn,
        $initRow,
        $headersColumns,
        $headersRows,
        $maxColumnIndex,
        $finalRow
    )
    {

        $initColumnIndex = Coordinate::columnIndexFromString($initColumn);
        $initColumnIndexTop = $initColumnIndex + $headersColumns;

        $mergeCellsFromSheet = $sheet->getMergeCells();
        Log::info("MERGE CELLS FROM SHEET::: " . print_r($mergeCellsFromSheet,true));
        $mergeCells = [];
        foreach ($mergeCellsFromSheet as $mergeCell) {
            list($key, $value) = explode(':', $mergeCell);
            $mergeCells[$key] = $value;
        }

        $headers = [];

        //VERTICE
        $cornerHeaders = [];

        for ($row = $initRow; $row < ($initRow + $headersRows); $row++) {
            $cornerHeaders[$row] = [];

            for ($columnIndex = $initColumnIndex; $columnIndex < $initColumnIndexTop; $columnIndex++) {
                $headerData = [];

                $column = Coordinate::stringFromColumnIndex($columnIndex);

                $coordinate = $column . $row;
                $cell = $sheet->getCell($coordinate);

                $this->setCellValues($headerData, $cell);
                $this->setCellSpans($headerData, $coordinate, $cell, $columnIndex, $row, $mergeCells);

                $cornerHeaders[$row][$columnIndex] = $headerData;
            }
        }
//        $cornerCoordinate = $initColumn . $initRow;
//
//        $cornerCell = $sheet->getCell($cornerCoordinate);
//        $this->setCellValues($cornerHeaders, $cornerCell);
//        $this->setCellSpans($cornerHeaders, $cornerCoordinate, $cornerCell, $initColumnIndex, $initRow, $mergeCells);

        $headers['corner'] = $cornerHeaders;


        //PRIMA PASSATA SULLE RIGHE DI INTESTAZIONE SOPRA LA TABELLA
        $topHeaders = [];
        for ($row = $initRow; $row < ($initRow + $headersRows); $row++) {

            $topHeaders[$row] = [];

            for ($columnIndex = $initColumnIndexTop; $columnIndex <= $maxColumnIndex; $columnIndex++) {

                $headerData = [];

                $column = Coordinate::stringFromColumnIndex($columnIndex);

                $coordinate = $column . $row;
                $cell = $sheet->getCell($coordinate);

                $this->setCellValues($headerData, $cell);
                $this->setCellSpans($headerData, $coordinate, $cell, $columnIndex, $row, $mergeCells);

                $topHeaders[$row][$columnIndex] = $headerData;

            }

        }

        $headers['top'] = $topHeaders;


        //SECONDA PASSATA SULLE COLONNE DI INTESTAZIONE A SINISTRA DELLA TABELLA
        $leftHeaders = [];

        $initRowLeft = $initRow + $headersRows;

        $rowForName = $initRowLeft - 1;

        $guessedNames = [];

        for ($columnIndex = $initColumnIndex; $columnIndex < ($initColumnIndex + $headersColumns); $columnIndex++) {
            $column = Coordinate::stringFromColumnIndex($columnIndex);
            $coordinateForName = $column . $rowForName;
            $cell = $sheet->getCell($coordinateForName);
            $guessedNames[$columnIndex] = $cell->getValue();

        }
        for ($row = $initRowLeft; $row <= $finalRow; $row++) {
            $leftHeaders[$row] = [];


            for ($columnIndex = $initColumnIndex; $columnIndex < ($initColumnIndex + $headersColumns); $columnIndex++) {

                $headerData = [];

                $column = Coordinate::stringFromColumnIndex($columnIndex);

                $coordinate = $column . $row;
                $cell = $sheet->getCell($coordinate);

                $this->setCellValues($headerData, $cell);
                $this->setCellSpans($headerData, $coordinate, $cell, $columnIndex, $row, $mergeCells);

                $leftHeaders[$row][$columnIndex] = $headerData;

            }

        }

        $headers['left'] = $leftHeaders;
        $headers['left_guessed_names'] = $guessedNames;

        return $headers;

    }


    protected function setCellValues(&$headerData, $cell)
    {
        $fVal = $cell->getFormattedValue();
        $fVal = is_string($fVal)?trim($fVal," \t\n\r\0\x0B'"):$fVal;
        $val = $this->getCalcValue($cell);
        $val = is_string($val)?trim($val, " \t\n\r\0\x0B'"):$val;
        $headerData['fVal'] =$fVal;
        $headerData['val'] = $val;
    }

    protected function setCellSpans(&$headerData, $coordinate, $cell, $columnIndex, $row, $mergeCells = [])
    {
        if (array_key_exists($coordinate, $mergeCells)) {
            $finalCoordinate = $mergeCells[$coordinate];
            list($finalMergeColumn, $finalMergeRow) = Coordinate::coordinateFromString($finalCoordinate);
            $headerData['colspan'] = Coordinate::columnIndexFromString($finalMergeColumn) - $columnIndex + 1;
            $headerData['rowspan'] = intval($finalMergeRow) - $row + 1;

        } elseif ($cell->isInMergeRange()) {
//                    continue;
        } else {
            $headerData['colspan'] = 1;
            $headerData['rowspan'] = 1;
        }
    }

    protected function getTableSeries($headers)
    {

        $topSeries = $this->guessSeriesFromTopHeaders(Arr::get($headers, 'top'));
        $leftSeries = $this->guessSeriesFromLeftHeaders(Arr::get($headers, 'left'));

        $topSeriesStrict = $this->reduceToStrictSeries($topSeries, 'top');
        $leftSeriesStrict = $this->addNameToLeftStrictSeries($leftSeries,Arr::get($headers, 'left_guessed_names'));

        $series = [
            'top' => $topSeriesStrict,
            'left' => $leftSeriesStrict,
        ];
        return $series;
    }

    protected function reduceToStrictSeries($series, $type)
    {

        switch ($type) {
            case 'top':
                $valuesLabel = 'valuesPerColumn';
                break;
            case 'left':
                $valuesLabel = 'valuesPerRow';
                break;
            default:
                throw new \Exception("Il tipo deve essere top o left");

        }
        $nSeries = count($series);

        $strictSeries = [];

        $previousDistinctValues = [];
        $nPreviousDistinctValues = 0;
//        for ($i = 0; $i < $nSeries; $i = $i + 2) {
//
//            $names = $i;
//            $values = $i + 1;

        for ($i = $nSeries - 1; $i > 0; $i = $i - 2) {

            $names = $i - 1;
            $values = $i;
            $namesSeries = $series[$names];
            $valuesSeries = $series[$values];

            $nameValues = array_map('trim',array_map('strtolower',array_values($namesSeries['values'])));
            Log::info("SERIES::: " . print_r($series,true));
            Log::info("NAMES SERIES::: " . print_r($namesSeries,true));
            Log::info("VALUES SERIES::: " . print_r($valuesSeries,true));
            Log::info("NAME VALUES::: " . print_r($nameValues,true));
            if (count($nameValues) !== 1) {
                throw new \Exception("Le serie con i nomi devono avere un unico valore distinto\n" . print_r($nameValues,true));
            }

            $distinctValues = array_values($valuesSeries['values']);
            $columnValues = $valuesSeries[$valuesLabel];

            $nDistinctValues = count($distinctValues);
            $realDistinctValueToCheck = 0;
            $distinctValueToCheck = 0;
            foreach ($columnValues as $columnValue) {

                if ($nPreviousDistinctValues <= 0)
                    break;

                $currDistinctValue = $distinctValues[$realDistinctValueToCheck];
                if ($columnValue != $currDistinctValue) {
                    throw new \Exception($valuesSeries['name'] . "::: $columnValue --- $currDistinctValue --- Le serie con i valori devono essere raggruppate allo stesso modo rispetto alla serie superiore");
                }

                $distinctValueToCheck++;
                $realDistinctValueToCheck =
                    intval(floor($distinctValueToCheck / $nPreviousDistinctValues));
                Log::info("REALNEXT::: $realDistinctValueToCheck - $distinctValueToCheck - $nPreviousDistinctValues");
                if ($realDistinctValueToCheck == $nDistinctValues) {
                    $distinctValueToCheck = 0;
                    $realDistinctValueToCheck = 0;
                }

            }

            if ($distinctValueToCheck > 0) {
                throw new \Exception($valuesSeries['name'] . "::: $distinctValueToCheck --- Le serie con i valori devono essere raggruppate allo stesso modo rispetto alla serie superiore");
            }

            $valuesSeries['name'] = $nameValues[0];
            $strictSeries[] = $valuesSeries;

            $previousDistinctValues = $distinctValues;
            $nPreviousDistinctValues = $nPreviousDistinctValues > 0
                ? count($distinctValues) * $nPreviousDistinctValues
                : count($distinctValues);

        }

        return $strictSeries;
    }

    protected function addNameToLeftStrictSeries($series,$leftHeadersGuessedNames)
    {


        $namedSeries = [];
        $guessedNames = array_values($leftHeadersGuessedNames);
        $i = 0;
        foreach ($series as $serie) {

            $guessedName = Arr::get($guessedNames,$i);
            $serie['name'] = $guessedName ?: $serie['name'];
            $serie['name'] = strtolower(trim($serie['name']));


            $namedSeries[] = $serie;
            $i++;
        }

        return $namedSeries;
    }

    protected function guessSeriesFromTopHeaders($headers)
    {

        $topSeries = [];

        $nSerie = 1;
        Log::info("HEADERS::: " . print_r($headers,true));
        foreach ($headers as $row => $columnVals) {
            $topSerie = [
                'type' => 'top',
                'name' => 'serie_top_' . $nSerie,
                'skip' => false,
                'values' => [],
                'valuesPerColumn' => [],
            ];
            $toBeSpanned = 0;
            $lastValue = null;
            foreach ($columnVals as $columnIndex => $columnVal) {
                if ($toBeSpanned > 0) {
                    $topSerie['valuesPerColumn'][$columnIndex] = $lastValue;
                    $toBeSpanned--;
                    continue;
                }

                $colSpan = intval(Arr::get($columnVal, 'colspan', 1));
                if ($colSpan > 1) {
                    $toBeSpanned = $colSpan - 1;
                }

                $lastValue = Arr::get($columnVal, 'fVal');
                $topSerie['values'][$lastValue] = $lastValue;
                $topSerie['valuesPerColumn'][$columnIndex] = $lastValue;

            }
            $topSeries[] = $topSerie;
            $nSerie++;
        }

        return $topSeries;

    }

    protected function guessSeriesFromLeftHeaders($headers)
    {

        $leftSeries = [];

        $rows = array_keys($headers);
        $firstRow = Arr::get($rows, 0, -1);
        $columnIndexes = array_keys(Arr::get($headers, $firstRow, []));

        $flatHeaders = [];

        foreach ($headers as $columnVals) {
            $flatHeaders[] = ['row' => $columnVals];
        }
        $translatedHeaders = [];
        foreach ($columnIndexes as $columnIndex) {
            $manipulatedHeaders = Arr::pluck($flatHeaders, 'row.' . $columnIndex);
            $translatedHeaders[$columnIndex] = array_combine($rows, $manipulatedHeaders);
        }


        $nSerie = 1;
        foreach ($translatedHeaders as $col => $rowVals) {
            $leftSerie = [
                'type' => 'left',
                'name' => 'serie_left_' . $nSerie,
                'skip' => false,
                'values' => [],
                'valuesPerRow' => [],
            ];
            $toBeSpanned = 0;
            $lastValue = null;
            foreach ($rowVals as $rowIndex => $rowVal) {
                if ($toBeSpanned > 0) {
                    $leftSerie['valuesPerRow'][$rowIndex] = $lastValue;
                    $toBeSpanned--;
                    continue;
                }

                $colSpan = intval(Arr::get($rowVal, 'rowspan', 1));
                if ($colSpan > 1) {
                    $toBeSpanned = $colSpan - 1;
                }

                $lastValue = trim(Arr::get($rowVal, 'fVal'));
                $leftSerie['values'][$lastValue] = $lastValue;
                $leftSerie['valuesPerRow'][$rowIndex] = $lastValue;

            }
            $leftSeries[] = $leftSerie;
            $nSerie++;
        }

        return $leftSeries;

    }

    protected function getFinalHeaderColumn($sheet, $initDataColumn, $initRow, $headersRows, $maxColumnIndex)
    {
        $trovato = false;
        $column = $initDataColumn;
        $i = Coordinate::columnIndexFromString($column);
        $maxRow = $initRow + $headersRows;
        while (!$trovato && $i <= $maxColumnIndex) {

            $hasValue = false;
            for ($row = $initRow; $row <= $maxRow; $row++) {

                $coordinate = $column . $row;
                $cell = $sheet->getCell($coordinate);
                $rawValue = $cell->getValue();
                if (!is_null($rawValue) && !empty(trim($rawValue))) {
                    $hasValue = true;
                    break;
                }
            }
            if (!$hasValue) {
                $trovato = true;
            }

            if (!$trovato) {
                $i++;
                $column = Coordinate::stringFromColumnIndex($i);
            }
        }
        return Coordinate::stringFromColumnIndex($i - 1);
    }

    protected function getFinalDataRow($sheet, $startRow, $startColumn, $endColumn, $maxRowIndex)
    {
        $trovato = false;
        $row = $startRow;
        $startColumnIndex = Coordinate::columnIndexFromString($startColumn);
        $endColumnIndex = Coordinate::columnIndexFromString($endColumn);
        Log::info("SEARCH FINAL ROW::: " . $row . ' --- ' . $maxRowIndex);
        while (!$trovato && $row <= $maxRowIndex) {

            $hasValue = false;
            for ($columnIndex = $startColumnIndex; $columnIndex <= $endColumnIndex; $columnIndex++) {
                $column = Coordinate::stringFromColumnIndex($columnIndex);
                $coordinate = $column . $row;
                $cell = $sheet->getCell($coordinate);
                $rawValue = $cell->getValue();
                if (!is_null($rawValue)) {
                    if (!empty(trim($rawValue)) || $cell->getDataType() == 'n') {
                        $hasValue = true;
                        break;
                    }
                }
            }
            if (!$hasValue) {
                $trovato = true;
            }

            if (!$trovato) {
                $row++;
            }
        }
        return $row - 1;

    }

//        if (is_int($sheetName)) {
//            $this->fileSheetName = $sheetNames[$sheetName];
//        } else {
//            if (!in_array($sheetName, $sheetNames)) {
//                throw new \Exception('Il foglio ' . $sheetName . ' &egrave; inesistente nel file excel caricato.');
//            }
//            $this->fileSheetName = $sheetName;
//        }
//
//        $this->objectReader->setLoadSheetsOnly([$this->fileSheetName]);


}

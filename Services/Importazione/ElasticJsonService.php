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

class ElasticJsonService
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
//            throw new \Exception("File di origine non trovato.");
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


        try {
            $inputFileType = IOFactory::identify($this->dataFile);
            $this->objectReader = IOFactory::createReader($inputFileType);
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

    public function getTableJson($sheetName,$titolo,$metadata = [])
    {
        $inferredSeries = Arr::get($metadata,'inferredSeries');
        if (is_null($inferredSeries)) {
            throw new \Exception("serie mancanti - Foglio: ".$sheetName." - Tabella: ".$titolo);
        }

        $topSeries = Arr::get($inferredSeries,'top',[]);
        $leftSeries = Arr::get($inferredSeries,'left',[]);

        $initCoordinate = Arr::get($metadata,'initData');
        $endCoordinate = Arr::get($metadata,'end');
        [$initDataColumn,$initDataRow] = Coordinate::coordinateFromString($initCoordinate);
        [$endDataColumn,$endDataRow] = Coordinate::coordinateFromString($endCoordinate);
        $initDataColumnIndex = Coordinate::columnIndexFromString($initDataColumn);
        $endDataColumnIndex = Coordinate::columnIndexFromString($endDataColumn);
        $totalColumns = $endDataColumnIndex - $initDataColumnIndex + 1;
        $totalRows = $endDataRow - $initDataRow + 1;

        $series = [

        ];
        $nSerie = 1;
        foreach ($topSeries as $serieKey => $topSerie) {
            $serieName = Arr::get($topSerie,'name');
            $serieName = $serieName ?: 'serie_'.$nSerie;
            $topSeries[$serieKey]['realName'] = $serieName;
            $series[$serieName]['values'] = array_unique(Arr::get($topSerie,'values',[]));
            $series[$serieName]['type'] = 'top';
            $nSerie++;
        }

        foreach ($leftSeries as $serieKey => $leftSerie) {
            $serieName = Arr::get($leftSerie,'name');
            $serieName = $serieName ?: 'serie_'.$nSerie;
            $leftSeries[$serieKey]['realName'] = $serieName;
            $series[$serieName]['values'] = array_unique(Arr::get($leftSerie,'values',[]));
            $series[$serieName]['type'] = 'left';
            $nSerie++;
        }

        $json = [
            'sheetname' => $sheetName,
            'titolo' => $titolo,
            'columns' => $totalColumns,
            'rows' => $totalRows,
            'series' => $series,
            'extra' => Arr::get($metadata,'extra',[])
        ];

        $this->objectReader->setLoadSheetsOnly([$sheetName]);

        $spreadSheet = $this->objectReader->load($this->dataFile);
        $spreadSheet->setActiveSheetIndexByName($sheetName);

        $currentSheet = $spreadSheet->getActiveSheet();
        $points = [];
        for ($i = $initDataColumnIndex; $i <= $endDataColumnIndex; $i++) {

            $column = Coordinate::stringFromColumnIndex($i);

            for ($row = $initDataRow; $row <= $endDataRow; $row++) {
                $coordinate = $column.$row;
                $cell = $currentSheet->getCell($coordinate);
                $dataType = $cell->getDataType();
                $rawValue = $this->getCalcValue($cell);
                $point = [
                    'id' => $i.'_'.$row,
                    'value' => $rawValue,
                ];


                foreach ($topSeries as $topSerie) {
                    $serieValue = Arr::get($topSerie['valuesPerColumn'],$i,'Non trovato');
                    $point[Arr::get($topSerie,'realName')] = $serieValue;
                }

                foreach ($leftSeries as $leftSerie) {
                    $serieValue = Arr::get($leftSerie['valuesPerRow'],$row,'Non trovato');
                    $point[Arr::get($leftSerie,'realName')] = $serieValue;
                }

                $points[] = $point;
            }
        }

        $spreadSheet->disconnectWorksheets();
//        unset($objPHPExcel);

        $json['values'] = $points;
        $encodingOptions = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_PRETTY_PRINT;
        return json_encode($json,$encodingOptions);



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
                $rawValue = $this->getCalcValue($cell);
                $formattedValue = $cell->getFormattedValue();
                Log::info('getTablesFromSheet', ['coordinate' => $coordinate, 'rawValue' => $rawValue, 'formattedValue' => $formattedValue]);
                if (in_array($cell->getDataType(), [
                    DataType::TYPE_STRING,
                    DataType::TYPE_INLINE,
                ], true) && Str::startsWith($rawValue, 'MD:')) {

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


                    $finalRow = $this->getFinalDataRow($currentSheet, $initRow, $initDataColumn, $finalColumn,
                        $totalRows);
                    //C'E' QUALCHE CASINO
                    if ($finalRow === false) {
                        continue;
                    }

                    $title = $this->guessTableTitle($currentSheet, $column, $row, $finalColumn, $previousFinalRow,
                        $nTables);
                    $previousFinalRow = $finalRow;


                    $headers = $this->getTableHeaders($currentSheet, $initColumn, $initRow, $headersColumns,
                        $headersRows, $finalColumnIndex, $finalRow);


                    $series = $this->getTableSeries($headers);

                    $tableMetadata = [
                        'init' => $initColumn . $initRow,
                        'initData' => $initDataColumn . $initDataRow,
                        'end' => $finalColumn . $finalRow,
                        'headers' => $headers,
                        'series' => $series,
                        'inferredSeries' => $series,
                    ];


                    $tableData = [
                        'importazione_id' => $this->importazione->getKey(),
                        'progressivo' => $nTables,
                        'nome' => $title,
                        'sheetname' => $sheetName,
                        'metadata' => $tableMetadata,
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

    protected function guessTableTitle(
        Worksheet $sheet,
        $metadataColumn,
        $metadataRow,
        $finalColumn,
        $previousFinalRow,
        $nTables
    ) {

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
                $value = $this->getCalcValue($nextCell);
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
    ) {

        $initColumnIndex = Coordinate::columnIndexFromString($initColumn);
        $initColumnIndexTop = $initColumnIndex + $headersColumns;

        $mergeCellsFromSheet = $sheet->getMergeCells();

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

        return $headers;

    }


    protected function setCellValues(&$headerData, $cell)
    {
        $headerData['fVal'] = $cell->getFormattedValue();
        $headerData['val'] = $this->getCalcValue($cell);
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
        $series = [
            'top' => $this->guessSeriesFromTopHeaders(Arr::get($headers, 'top')),
            'left' => $this->guessSeriesFromLeftHeaders(Arr::get($headers, 'left'))
        ];
        return $series;
    }

    protected function guessSeriesFromTopHeaders($headers)
    {

        $topSeries = [];

        foreach ($headers as $row => $columnVals) {
            $topSerie = [
                'type' => 'top',
                'name' => null,
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

                $lastValue = Arr::get($columnVal, 'val');
                $topSerie['values'][] = $lastValue;
                $topSerie['valuesPerColumn'][$columnIndex] = $lastValue;

            }
            $topSeries[] = $topSerie;
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


        foreach ($translatedHeaders as $col => $rowVals) {
            $leftSerie = [
                'type' => 'left',
                'name' => null,
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

                $lastValue = Arr::get($rowVal, 'val');
                $leftSerie['values'][] = $lastValue;
                $leftSerie['valuesPerRow'][$rowIndex] = $lastValue;

            }
            $leftSeries[] = $leftSerie;
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
                $rawValue = $this->getCalcValue($cell);
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
        while (!$trovato && $row <= $maxRowIndex) {

            $hasValue = false;
            for ($columnIndex = $startColumnIndex; $columnIndex <= $endColumnIndex; $columnIndex++) {
                $column = Coordinate::stringFromColumnIndex($columnIndex);
                $coordinate = $column . $row;
                $cell = $sheet->getCell($coordinate);
                $rawValue =$this->getCalcValue($cell);
                if (!is_null($rawValue)) {
                    if (!empty(trim($rawValue)) || $cell->getDataType() == 'n') {
                        $hasValue = true;
                        break;
                    }
                }
                if (!$hasValue) {
                    $trovato = true;
                }
            }

            if (!$trovato) {
                $row++;
            }
        }
        if ($trovato) {
            return $row - 1;
        }
        return false;

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

<?php namespace Modules\CupChart\Services\Importazione;

/*
 * CLASSE PER GESTIRE LA FASE DI UPLOADI UN FILE.
 * EVENTUALMENTE DA FARE COME PROVIDER E FACADE IN FUTURO.
 * PER ORA SERVIZIO AL VOLO COME SINGLETON.
 */

use App\Models\Importazione;
use App\Models\ImportazioneTabella;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InferenzaTablesService
{

    protected $importazione;
    protected $objectReader;
    protected $dataFile;

    protected $sheets = [];

    public function __construct(Importazione $importazione)
    {
        $this->importazione = $importazione;
        if (!$this->importazione->fileExists()) {
            throw new \Exception("File di origine non trovato.");
        }
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
        for ($i = 1; $i <= Arr::get($sheetInfo, 'totalColumns', 0); $i++) {

            $column = Coordinate::stringFromColumnIndex($i);

            for ($row = 1; $row <= $totalRows; $row++) {

                $coordinate = $column . $row;
                $cell = $currentSheet->getCell($coordinate);
                $dataType = $cell->getDataType();
                $rawValue = $cell->getValue();
                $formattedValue = $cell->getFormattedValue();
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

                    $finalColumnIndex = Coordinate::columnIndexFromString($finalColumn);

                    //C'E' QUALCHE CASINO
                    if ($finalColumn === false) {
                        continue;
                    }

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

                    $tableMetadata = [
                        'init' => $initColumn . $initRow,
                        'initData' => $initDataColumn . $initDataRow,
                        'end' => $finalColumn . $finalRow,
                        'headers' => $headers,
                    ];


                    $tableData = [
                        'importazione_id' => $this->importazione->getKey(),
                        'progressivo' => $nTables,
                        'nome' => $title,
                        'sheetname' => $sheetName,
                        'metadata' => $tableMetadata,

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

            for ($columnIndex = $initColumnIndex;$columnIndex < $initColumnIndexTop; $columnIndex++) {
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
        $headerData['val'] = $cell->getvalue();
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
        if ($trovato) {
            return Coordinate::stringFromColumnIndex($i - 1);
        }
        return false;
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
                $rawValue = $cell->getValue();
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

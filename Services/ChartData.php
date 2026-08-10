<?php namespace Modules\CupChart\Services;
/**
 * Created by PhpStorm.
 * User: pier
 * Date: 30/01/17
 * Time: 11:20
 */


//use Elasticsearch\ClientBuilder;
use App\Models\CupGeoComune;
use App\Models\CupGeoNazione;
use App\Models\CupGeoProvincia;
use App\Models\CupGeoRegione;
use Elasticsearch\ClientBuilder;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class ChartData
{

    protected $client = null;
    protected $data = null;
    protected $params = [];
    protected $filtersContext = [];
    protected $filters = [];
    protected $seriesContext = [];
    protected $series = [];
    protected $multidimensionale = true;

    protected $mapOptions = [];

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function getData($type, $params)
    {
        $this->params = $params;
        try {
            switch ($type) {
                case 'map':
                    $this->multidimensionale = false;
                    return $this->_mapData();
                case 'chart':
                case 'pie':
                    return $this->_chartData();
                case 'table':
                    return $this->_tableData();
                default:
                    throw new \Exception($type . ' type non gestito');
            }
        } catch (\Exception $e) {
            Log::error($e->getTraceAsString());
            throw new \Exception($e->getMessage() . " " . $e->getFile() . ":" . $e->getLine());

        }

    }

    /**
     * prende i dati per la rappresentazione a tabella. In questa modalità i filtri vengono ignorati,
     * viene sempre presa tutta la tabella.
     * @return array
     */
    protected function _tableData()
    {
        $result = [];
        //$this->_setFilters();
        //$this->_setSeries();
        // prendo sempre tutti i filtri


        //valori colonne e il loro offset all'interno del vettore values
        $series = $this->_getKeys($this->data['series']);
        $topSeries = $series['top'];
        $leftSeries = $series['left'];
        // prendo sempre tutti i filtri

        foreach ($topSeries as $key => $top) {
            $this->series[$key] = array_keys($top['values']);
        }
        foreach ($leftSeries as $key => $left) {
            $this->filters[$key] = array_keys($left['values']);
        }

        $cartesian = $this->_getSeries($topSeries,true);
        $cartesianAll = $this->_getSeries($topSeries, true);

        //print_r($cartesian);
//        print_r($cartesianAll);
//        die();

//        $rowKeys = $leftSeries[array_keys($series['left'])[0]]['values'];
//        $rowIndexKeys = array_keys($rowKeys);
        $values = [];
        $separtoreLeft = config('cupparis-chart.separatore_left');
        $separtoreTop = config('cupparis-chart.separatore_top');
        $maxValue = 0;
        $minValue = 0;
        $isInt = true;
        $extra = Arr::get($this->data, 'extra', []);
        foreach ($this->data['values'] as $item) {

            if (!$this->_matchFilter($item))
                continue;
//            print_r($cartesian);
//            die();

            foreach ($cartesian as $subKeys) {
                if (!$this->_matchSerieInValues($subKeys,$item)) {
                    continue;
                }

                $subKey = implode($separtoreTop, $subKeys);
                if (!Arr::exists($values, $subKey)) {
                    $values[$subKey] = [];
                }
                $tipo = 'number';
                if (Arr::exists($extra,'tipo')) {
                    $tipo = Arr::get($extra,'tipo','number');
                }
                if ($tipo == 'string') {
                    $stringValue = $item['value'];
//                    $isInt |= $this->isInt($floatValue);
//                    $maxValue = $maxValue < $floatValue ? $floatValue : $maxValue;
//                    $minValue = $minValue > $floatValue ? $floatValue : $minValue;
                    $rowLabel = [];
                    // TODO riformulare in base ai filtri sulle left...
                    foreach ($leftSeries as $key => $lserie) {
                        $rowLabel[] = $item[$key];
                        //$rowLabel .= ($rowLabel?" " . $item[$key]:$item[$key]);
                    }
                    $rowLabel = implode($separtoreLeft, $rowLabel);
                    //$rowLabel = 'bo';

                    if (!Arr::exists($values[$subKey], $rowLabel)) {
                        $values[$subKey][$rowLabel] = [
                            'label' => $subKey,
                            'total' => ''
                        ];
                    }
                    $values[$subKey][$rowLabel]['total'] .= $stringValue;
                } else {
                    $floatValue = floatval($item['value']);
                    $isInt |= $this->isInt($floatValue);
                    $maxValue = $maxValue < $floatValue ? $floatValue : $maxValue;
                    $minValue = $minValue > $floatValue ? $floatValue : $minValue;
                    $rowLabel = [];
                    // TODO riformulare in base ai filtri sulle left...
                    foreach ($leftSeries as $key => $lserie) {
                        $rowLabel[] = $item[$key];
                        //$rowLabel .= ($rowLabel?" " . $item[$key]:$item[$key]);
                    }
                    $rowLabel = implode($separtoreLeft, $rowLabel);
                    //$rowLabel = 'bo';

                    if (!Arr::exists($values[$subKey], $rowLabel)) {
                        $values[$subKey][$rowLabel] = [
                            'label' => $subKey,
                            'total' => 0
                        ];
                    }
                    $values[$subKey][$rowLabel]['total'] += $floatValue;
                }



            }
        }


        $ll = $series['left'][array_keys($series['left'])[0]];
        $result['measureName'] = Arr::get($ll, 'label', '');
        $result['description'] = $this->data['titolo'];
        $result['values'] = $values;
        $result['context'] = $this->filtersContext;
        $result['seriesContext'] = $this->seriesContext;
        $result['leftSeries'] = $leftSeries;
        $result['topSeries'] = $topSeries;
        $result['separatoreLeft'] = $separtoreLeft;
        $result['separatoreTop'] = $separtoreTop;
        $result['extra'] = Arr::get($this->data, 'extra', []);
        //$result['extra']['tipo'] = $isInt ? 'integer' : 'float';
        $result['min'] = $minValue < 0 ? $minValue : 0;
        // TODO questo potrebbe essere configurabile
        //$result['max'] = Arr::get($result['extra'],'tipo_valore',null) == 'percentuale'?100:$maxValue;
        $result['max'] = $maxValue;
        return $result;
    }


    protected function _chartData()
    {
        $result = [];
        $this->_setFilters();
        $this->_setSeries();

        //valori colonne e il loro offset all'interno del vettore values
        $series = $this->_getKeys($this->data['series']);
        $topSeries = $series['top'];
        $leftSeries = $series['left'];


        $cartesian = $this->_getSeries($topSeries);
        $cartesianAll = $this->_getSeries($topSeries, true);


        //print_r($cartesian);
//        print_r($cartesianAll);
//        die();

//        $rowKeys = $leftSeries[array_keys($series['left'])[0]]['values'];
//        $rowIndexKeys = array_keys($rowKeys);
        $values = [];
        $separtoreLeft = config('cupparis-chart.separatore_left');
        $maxValue = 0;
        $minValue = 0;
        $isInt = true;

        foreach ($this->data['values'] as $item) {

            if (!$this->_matchFilter($item))
                continue;
//            print_r($cartesian);
//            die();

            foreach ($cartesian as $subKeys) {
                if (!$this->_matchSerieInValues($subKeys, $item)) {
                    continue;
                }

                $subKey = implode(' ', $subKeys);
                if (!Arr::exists($values, $subKey)) {
                    $values[$subKey] = [];
                }

                $floatValue = floatval($item['value']);
                $isInt |= $this->isInt($floatValue);
                $maxValue = $maxValue < $floatValue ? $floatValue : $maxValue;
                $minValue = $minValue > $floatValue ? $floatValue : $minValue;
                $rowLabel = [];
                // TODO riformulare in base ai filtri sulle left...
                foreach ($leftSeries as $key => $lserie) {
                    $rowLabel[] = $item[$key];
                    //$rowLabel .= ($rowLabel?" " . $item[$key]:$item[$key]);
                }
                $rowLabel = implode($separtoreLeft, $rowLabel);
                //$rowLabel = 'bo';

                if (!Arr::exists($values[$subKey], $rowLabel)) {
                    $values[$subKey][$rowLabel] = [
                        'label' => $subKey,
                        'total' => 0
                    ];
                }
                $values[$subKey][$rowLabel]['total'] += $floatValue;


            }
        }

        //print_r($series);
        $ll = $series['left'][array_keys($series['left'])[0]];
        $result['measureName'] = Arr::get($ll, 'label', '');
        $result['description'] = $this->data['titolo'];
        $result['values'] = $values;
        $result['filtersContext'] = $this->filtersContext;
        $result['seriesContext'] = $this->seriesContext;
        $result['leftSeries'] = $leftSeries;
        $result['topSeries'] = $topSeries;
        $result['separatoreLeft'] = $separtoreLeft;
        $result['extra'] = Arr::get($this->data, 'extra', []);
        //$result['extra']['tipo'] = $isInt ? 'integer' : 'float';
        $result['min'] = $minValue < 0 ? $minValue : 0;
        // TODO questo potrebbe essere configurabile
        //$result['max'] = Arr::get($result['extra'],'tipo_valore',null) == 'percentuale'?100:$maxValue;
        $result['max'] = $maxValue;
        $result['currentFilters'] = $this->filters;
        $result['currentSeries'] = $this->series;
        return $result;
    }

    protected function _mapData()
    {
        $result = [];
        $this->_setFilters();
        $this->_setSeries(true);
        $seriesValues = [];
        $isInt = true;
        $series = $this->_getKeys($this->data['series']);
        $topSeries = $series['top'];
        $leftSeries = $series['left'];
        $cartesian = $this->_getSeries($topSeries);
        $cartesianAll = $this->_getSeries($topSeries, true);

        $rowKeys = $leftSeries[array_keys($series['left'])[0]]['values'];
        $rowIndexKeys = array_keys($rowKeys);
        //$mode = '';
        //$mapKey = '';
        //$mapIstat = [];
        $this->_setMapOptions($leftSeries);

//        if (array_key_exists('comune', $leftSeries)) {
//            $mode = 'comuni';
//            $mapKey = 'comune';
//            $mapIstat = $this->_comuniIstat($leftSeries[$mapKey]['values']);
//        } else if (array_key_exists('regione', $leftSeries)) {
//            $mode = 'regioni';
//            $mapKey = 'regione';
//            $mapIstat = $this->_regioniIstat($leftSeries[$mapKey]['values']);
//        } else if (array_key_exists('nazione', $leftSeries)) {
//            $mode = 'nazioni';
//            $mapKey = 'nazione';
//            $mapIstat = $this->_nazioniIstat($leftSeries[$mapKey]['values']);
//        } else if (array_key_exists('provincia', $leftSeries)) {
//            $mode = 'province';
//            $mapKey = 'provincia';
//            $mapIstat = $this->_provinceIstat($leftSeries[$mapKey]['values']);
//        }
//        print_r($leftSeries);
//        die('mapkey'.$mapKey);
        $mode = $this->mapOptions['mode'];
        $mapKey = $this->mapOptions['mapKey'];
        $mapIstat = $this->mapOptions['mapIstat'];
        //print_r($this->mapOptions);
        $values = [];

        foreach ($this->data['values'] as $item) {

            if (!$this->_matchFilter($item))
                continue;
            foreach ($cartesian as $subKeys) {
                if (!$this->_matchSerieInValues($subKeys, $item)) {
                    continue;
                }
//                if (!$this->_matchSerie($subKeys,$cartesian))
//                    continue;

                $subKey = implode(' ', $subKeys);
                if (!Arr::exists($values, $subKey)) {
                    $values[$subKey] = [];
                    $seriesValues[$subKey] = [];
                }
                $luogo = $item[$mapKey];
                $floatValue = floatval($item['value']);
                $isInt |= $this->isInt($floatValue);
                switch ($mode) {
                    case 'comuni':
                    case 'regioni':
                    case 'nazioni':
                    case 'province':
                        //if (Arr::get($mapIstat, $luogo)) {
                            $regioneIstat = $mapIstat[$luogo];
                            $seriesValues[$subKey][] = $floatValue;
                            //$min = ($min>$floatValue)?$floatValue:$min;
                            //$max = ($max<$floatValue)?$floatValue:$max;

                            if (!Arr::exists($values[$subKey], $luogo)) {
                                $values[$subKey][$luogo] = [
                                    'comune' => $luogo,
                                    'total' => 0
                                ];
                            }
                            $values[$subKey][$luogo]['total'] += $floatValue;
                        //} else {
                        //    Log::notice("ChartData luogo non trovato $luogo modalita $mode");
                        //}
                        break;
                }


            }
        }
        //Log::info("count " . count($values) . " " . print_r($values,1));
        $quantiliValues = [];
        foreach ($values[$subKey] as $key => $val) {
            $quantiliValues[] = $val['total'];
        }
        //Log::info(print_r($quantiliValues,1));
        $result['range'] = $this->_calcolaIntervalli($subKey,$quantiliValues);
        $result['filtersContext'] = $this->filtersContext;
        $result['seriesContext'] = $this->seriesContext;
        $result['measureName'] = $mapKey;
        $result['description'] = $this->data['titolo'];
        $result['values'] = $values;
        $result['sort'] = $seriesValues;
        //$result['step'] = $step;
        $result['leftSeries'] = $leftSeries;
        $result['topSeries'] = $topSeries;
        $result['extra'] = Arr::get($this->data, 'extra', []);
        //$result['extra']['tipo'] = $isInt ? 'integer' : 'float';
        $result['currentFilters'] = $this->filters;
        $result['currentSeries'] = $this->series;

        return $result;
    }


    /**
     * setta i filtri dei dati da recupare la convenzione e' questa funzione lavora sulle left series
     * 1) * tutti di dati in caso di chiavi diverse vengono sommati
     * 2) *-key tutti i dati filtrati per key
     * 3) ? prendere solo i dati del primo raggruppamento della left
     * 4) ?-key prendere solo i dati della chiave indicata
     */
    protected function _setFilters()
    {
        $queryParams = Arr::get($this->params, 'filters', []);
        $this->filters = [];
        $this->filtersContext = [];
        foreach ($queryParams as $key => $query) {
            $key = strtolower($key);
            $filterValues = $this->data['series'][$key]['values'];
            if (substr($query, 0, 1) == "*") {

                //$filterValues['*'] = 'Tutte le categ.';
                $this->filtersContext[$key] = [
                    'value' => '*',
                    'domainValues' => $filterValues,
                    'cardinalita' => '*'
                ];
                $this->filters[$key] = array_keys($filterValues);
                if (substr($query, 0, 2) == "*-") {
                    $selectValue = substr($query, 2);
                    $this->filtersContext[$key]['value'] = explode(",", $selectValue);
                    $this->filters[$key] = explode(",", $selectValue);
                }
                continue;
            }
            if (substr($query, 0, 1) == "?") {
                if ($query == '?') {// i valori del filtro non possono essere sommato prendo il primo valore valido del filtro
                    $this->filtersContext[$key] = [
                        'value' => [ array_keys($filterValues)[0] ],
                        'domainValues' => $filterValues,
                        'cardinalita' => '?'
                    ];
                    $this->filters[$key] = [$filterValues[array_keys($filterValues)[0]] ];
                }
                if (substr($query, 0, 2) == "?-") {
                    $selectValue = [ substr($query, 2) ];
                    $this->filtersContext[$key] = [
                        'value' => $selectValue,
                        'domainValues' => $filterValues
                    ];
                    $this->filters[$key] = $selectValue;
                }
            } else {
                $this->filters[$key] = explode(',',$query);
            }
        }
    }


    protected function _setSeries($isMap = false)
    {
        $queryParams = Arr::get($this->params, 'series', []);
        $this->series = [];
        $this->seriesContext = [];
//        print_r($queryParams);
//        print_r($this->data['series']);

        foreach ($queryParams as $key => $query) {
            $key = strtolower($key);
            $filterValues = $this->data['series'][$key]['values'];
            if (substr($query, 0, 1) == "*") {
                if ($query == '*') { // il filtro e' tutto quindi i valori verranno sommati come se non fosse definito
                    // nel caso di mappa il concetto di visualizza tutti non ha senso, si mostra un solo valore per volta
                    if ($isMap) {
                        // prendo la serie e lo imposto al primo valore del dominio
                        $this->seriesContext[$key] = [
                            'value' => array_keys($filterValues)[0],
                            'domainValues' => $filterValues,
                            'cardinalita' => '?'
                        ];
                        $this->series[$key] = [$filterValues[array_keys($filterValues)[0]]];
                    } else {
                        //$filterValues['*'] = 'Tutte le categ.';
                        $this->seriesContext[$key] = [
                            'value' => '*',
                            'domainValues' => $filterValues,
                            'cardinalita' => '*'
                        ];
                        $this->series[$key] = array_keys($filterValues);
                    }
                } else if (substr($query, 0, 2) == "*-") {
                    $selectValue = substr($query, 2);
                    //echo "$selectValue ";
                    //$this->filters[$key] = $selectValue;
                    $this->series[$key] = explode(",", $selectValue);
                    $this->seriesContext[$key] = [
                        'value' => explode(",", $selectValue), //$selectValue,
                        'domainValues' => $filterValues,
                        'cardinalita' => '*'
                    ];
                }
                continue;
            }
            if (substr($query, 0, 1) == "?") {
                if ($query == '?') {// i valori del filtro non possono essere sommate prendo il primo valore valido del filtro
                    $this->seriesContext[$key] = [
                        'value' => [array_keys($filterValues)[0]],
                        'domainValues' => $filterValues,
                        'cardinalita' => '?'
                    ];
                    $this->series[$key] = [ $filterValues[array_keys($filterValues)[0]] ];
                } else if (substr($query, 0, 2) == "?-") {
                    $selectValue = [substr($query, 2)];
                    $this->seriesContext[$key] = [
                        'value' => $selectValue,
                        'domainValues' => $filterValues,
                        'cardinalita' => '?'
                    ];
                    $this->series[$key] = $selectValue;
                }
            } else {
                $this->series[$key] = explode(",", $query);
            }
        }
    }


    protected function _matchFilter($values)
    {
        foreach ($this->filters as $keyFilter => $filter) {
            if (is_array($filter)) {
                if (!in_array($values[$keyFilter],$filter))
                    return false;
            } else {
                if ($values[$keyFilter] != $filter)
                    if (strcasecmp(trim($values[$keyFilter]), trim($filter)) != 0)
                        return false;
            }
        }
        return true;
    }

    protected function _matchSerieInValues($validSerie, $values)
    {
        $found = true;
//        echo "validSeriea\n";
//        print_r($validSerie);
//        echo "values \n";
//        print_r($values);
        //die();
        foreach ($validSerie as $keySerie => $valueSerie) {
            //print_r($valueSerie);
            //echo "testo " . $values[$keySerie] . "\n"; // con " . $valueSerie . "\n";
            if (is_array($valueSerie)) {
                if (in_array($values[$keySerie], $valueSerie))
                    $found &= true;
                else
                    $found &= false;
            } else {
                if ($values[$keySerie] == $valueSerie)
                    $found &= true;
                else
                    $found &= false;
            }

        }
        //echo "found $found\n";
        if ($found)
            return true;
        return false;
    }

    protected function _matchSerie($currentSerie, $validSerie)
    {
        foreach ($validSerie as $valid) {
            $found = true;
            foreach ($currentSerie as $key => $value) {
                if ($valid[$key] == $value)
                    $found &= true;
                else
                    $found &= false;
            }
            if ($found)
                return true;
        }
        return false;
    }

    protected function _getKeys($series)
    {
        $topSeries = [];
        $leftSeries = [];
        foreach ($series as $key => $serie) {
            if ($serie['type'] == 'top')
                $topSeries[$key] = $serie;
            else
                $leftSeries[$key] = $serie;
        }
        return [
            'top' => $topSeries,
            'left' => $leftSeries
        ];
    }

    protected function _comuniIstat($comuni)
    {
        $istat = [];
        foreach ($comuni as $comune) {
            $codiceIstat = CupGeoComune::where('nome_it', $comune)->first();
            if ($codiceIstat) {
                $codiceIstat = $codiceIstat->codice_istat;
            } else
                $codiceIstat = null;
            $istat[$comune] = $codiceIstat;
        }
        return $istat;
    }

    protected function _regioniIstat($regioni)
    {
        $istat = [];
        foreach ($regioni as $regione) {
            $codiceIstat = CupGeoRegione::where('nome_it', $regione)->first();
            if ($codiceIstat) {
                $codiceIstat = $codiceIstat->nome_it;
            } else
                $codiceIstat = $regione;
            $istat[$regione] = $codiceIstat;
        }
        return $istat;
    }

    protected function _provinceIstat($province)
    {
        $istat = [];
        foreach ($province as $provincia) {
            $codiceIstat = CupGeoProvincia::where('nome_it', $provincia)->first();
            if ($codiceIstat) {
                $codiceIstat = $codiceIstat->nome_it;
            } else
                $codiceIstat = $provincia;
            $istat[$provincia] = $codiceIstat;
        }
        return $istat;
    }

    protected function _nazioniIstat($nazioni)
    {
        $istat = [];
        foreach ($nazioni as $nazione) {
            $codiceIstat = CupGeoNazione::where('nome_it', $nazione)->first();
            if ($codiceIstat) {
                $codiceIstat = $codiceIstat->nome_it;
            } else
                $codiceIstat = $nazione;
            $istat[$nazione] = $codiceIstat;
        }
        return $istat;
    }

    protected function _getSeries($series, $all = false)
    {
        $values = [];
//        echo "---serie totali\n";
//        print_r($series);
//        echo "---serie richieste\n";
//        print_r($this->series);
//        die();
        foreach ($series as $serieName => $serie) {
            if ($all) {
                $values[] = array_keys($serie['values']);
                continue;
            }

            if (Arr::exists($this->series, $serieName)) {
                $serieValue = $this->series[$serieName];
                if (is_array($serieValue)) {
                    $values[] = $serieValue;
                } else {
                    $setOperator = substr($serieValue, 0, 1);
                    //echo "$setOperator :: $serieValue\n";
                    switch ($setOperator) {
                        case '*':  // formato * oppure *-val1,val2
                            // ha solo l'operatore come il simbolo
                            if ($setOperator == $serieValue) {
                                $values[] = array_keys($serie['values']);
                            } else {
                                $serieValue = substr($serieValue, 2);
                                $values[] = explode(',', $serieValue);
                            }
                            break;
                        case '?':
                            if ($setOperator == $serieValue) {
                                $values[] = [array_keys($serie['values'])[0]];
                            } else {
                                $serieValue = substr($serieValue, 2);
                                $values[] = explode(',', $serieValue);
                            }
                            break;
                    }
                }
            } else {
                $values[] = array_keys($serie['values']);
            }
//            echo "---$serieName values\n";
//            print_r($values);
        }
        $cartesian = $this->_cartesian($values);
        $keys = array_keys($series);
        $cartesianAssoc = [];
        foreach ($cartesian as $items) {
            $tmp = [];
            foreach ($items as $i => $item) {
                $tmp[$keys[$i]] = $item;
            }
            $cartesianAssoc[] = $tmp;
        }
//        echo "<br>--cartesia asso---<br>";
//        print_r($cartesianAssoc);
//        die();
        return $cartesianAssoc;
    }

    protected function _cartesian($array)
    {
        if (!$array) {
            return array(array());
        }

        $subset = array_shift($array);
        $cartesianSubset = $this->_cartesian($array);

        $result = array();
        foreach ($subset as $value) {
            foreach ($cartesianSubset as $p) {
                array_unshift($p, $value);
                $result[] = $p;
            }
        }

        return $result;
    }

    protected function _calcolaIntervalli($key,$seriesValues)
    {
        $interval = [];


        sort($seriesValues);
        Log::info("serievalues sorted" . print_r($seriesValues,1));
        $quartile = intval(count($seriesValues) / 5);
        Log::info("quartile $quartile");
        $interval[$key] = [];
        $interval[$key][] = $seriesValues[$quartile];
        $interval[$key][] = $seriesValues[$quartile*2];
        $interval[$key][] = $seriesValues[$quartile*3];
        $interval[$key][] = $seriesValues[$quartile*4];
        Log::info("range" . print_r($interval,1));
        return $interval;
    }

    protected function _calcolaIntervalliOld($seriesValues)
    {
        $interval = [];

        foreach ($seriesValues as $key => $val) {
            $valid = array_unique($seriesValues[$key]);
            sort($valid);
            $lun = count($valid);
            if ($lun < 4) {
                $min = $valid[0];
                $mins = [];
                for ($i = 0; $i < 4 - $lun; $i++) {
                    $mins[] = $min;
                }
                $interval[$key] = array_merge($mins, $valid);
            } else {
                $step = floor(count($valid) / 4.0);
                $interval[$key] = [];
                for ($i = 0; $i < 4; $i++) {
                    // TODO controllare il calcolo del range non funziona bene con valori ripetutti troppe volte
                    //            if ($i>0 &&
                    //                ($seriesValues[$i*$step]  == $seriesValues[($i-1) * $step]) )
                    //                continue;
                    $interval[$key][] = $valid[$i * $step];
                }
            }

        }
        return $interval;
    }

    private function _getValidFilterValue($query, $filters)
    {

    }

    private function isInt($value)
    {
        if ((int)$value == $value) {
            return true;
        }
        return false;
    }

    private function _setMapOptions($series) {
        $this->mapOptions = [

        ];
        //echo "series " . print_r($series,true);
        $ciSeries = [];
        foreach ($series as $k => $s) {
            $ciSeries[strtolower(trim($k))] = $k;
        }
        $config = config('cupparis-chart.sinonimi_tipo_geografico',[]);
        $keySearched = [];
        foreach ($config as $cKey => $cValidKeys) {
            //echo "$cKey " . print_r($cValidKeys,true);
            foreach ($cValidKeys as $key) {
                //echo " cerco " . strtolower(trim($key)) . " nel vettore " . print_r($ciSeries,true);
                $keySearched[] = $key;
                if (Arr::exists($ciSeries,strtolower(trim($key)))) {
                    $this->mapOptions = [
                        'mode' => $cKey,
                        'mapKey' => $ciSeries[strtolower(trim($key))],
                        'mapIstat' => $this->_comuniIstat($series[strtolower(trim($key))]['values'])
                    ];
                    return ;
                }
            }
            //echo "----\n";
        }
        throw new \Exception('La mappa non ha una key riconosciuta come key geografica ' . join(',',$keySearched));


//        if (array_key_exists('comune', $leftSeries)) {
//            $mode = 'comuni';
//            $mapKey = 'comune';
//            $mapIstat = $this->_comuniIstat($leftSeries[$mapKey]['values']);
//        } else if (array_key_exists('regione', $leftSeries)) {
//            $mode = 'regioni';
//            $mapKey = 'regione';
//            $mapIstat = $this->_regioniIstat($leftSeries[$mapKey]['values']);
//        } else if (array_key_exists('nazione', $leftSeries)) {
//            $mode = 'nazioni';
//            $mapKey = 'nazione';
//            $mapIstat = $this->_nazioniIstat($leftSeries[$mapKey]['values']);
//        } else if (array_key_exists('provincia', $leftSeries)) {
//            $mode = 'province';
//            $mapKey = 'provincia';
//            $mapIstat = $this->_provinceIstat($leftSeries[$mapKey]['values']);
//        }
    }
}

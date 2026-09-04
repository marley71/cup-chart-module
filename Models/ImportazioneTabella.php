<?php namespace Modules\CupChart\Models;

use App\Services\Importazione\RenderTableService;
use Gecche\Cupparis\App\Breeze\Breeze;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

/**
 * Breeze (Eloquent) model for T_AREA table.
 */
class ImportazioneTabella extends Breeze
{

//    use ModelWithUploadsTrait;

    protected $table = 'importazioni_tabelle';

    protected $guarded = ['id'];

    public $timestamps = false;
    public $ownerships = false;

    public $appends = [
         'graph_key', //'metadatao',
        'extra'
    ];

//    protected $casts = [
//        'metadata' => 'array',
//    ];

    public static $relationsData = [

        'importazione' => [self::BELONGS_TO, 'related' => \App\Models\Importazione::class, 'table' => 'importazioni', 'foreignKey' => 'importazione_id'],
        'grafici' => array(self::HAS_MANY, 'related' => GraficoTabella::class, 'table' => 'grafici_tabella','foreignKey' => 'importazione_tabelle_id'),

//        'belongsto' => array(self::BELONGS_TO, Area::class, 'foreignKey' => '<FOREIGNKEYNAME>'),
//        'belongstomany' => array(self::BELONGS_TO_MANY, Area::class, 'table' => '<TABLEPIVOTNAME>','pivotKeys' => [],'foreignKey' => '<FOREIGNKEYNAME>','otherKey' => '<OTHERKEYNAME>') ,
//        'hasmany' => array(self::HAS_MANY, Area::class, 'table' => '<TABLENAME>','foreignKey' => '<FOREIGNKEYNAME>'),
    ];

    public static $rules = [
//        'username' => 'required|between:4,255|unique:users,username',
    ];

    public $columnsForSelectList = ['nome'];
     //['id','nome'];

    public $defaultOrderColumns = ['importazione_id' => 'ASC','sheetname' => 'ASC', 'progressivo' => 'ASC'];
     //['cognome' => 'ASC','nome' => 'ASC'];

    public $columnsSearchAutoComplete = ['nome'];
     //['cognome','denominazione','codicefiscale','partitaiva'];

    public $nItemsAutoComplete = 20;
    public $nItemsForSelectList = 100;
    public $itemNoneForSelectList = false;
    public $fieldsSeparator = ' - ';


    public function getMetadataoAttribute() {
        return json_encode($this->metadata,JSON_PRETTY_PRINT);
    }

    public function getGraphKeyAttribute() {
        return $this->importazione_id . "_" . $this->sheetname . "_" . $this->progressivo;
    }

    public function getExtraAttribute() {
        try {
            $arr = json_decode($this->metadata,true);
            return Arr::exists($arr,'extra')?Arr::get($arr,'extra'):['bo' => 23];
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getTabellaExcelAttribute() {
        $renderTableService = new RenderTableService($this);
        return $renderTableService->getHtmlFromMetadata();
    }

    public function getChartData() {
        $id = $this->elastic_id;
        if (env('USE_ELASTIC')) {
            $es = new ElasticSearch();
            $data = $es->get([
                'index' => env('ELASTIC_INDEX'),
                'id' => $id
            ]);
            $data = $data['_source'];
        } else {
            $filename = storage_path('files/elastic/'.$this->importazione_id.'/'.$id.".json");
            $data = json_decode(file_get_contents($filename),true);
        }
        return $data;
    }
    public function scopeSearchRestricted(Builder $q, $search,$restriction, $threshold = null, $entireText = false, $entireTextOnly = false) {
        $q->where('nome', 'like', '%'.$search.'%');
        return $this->scopeSearchRestricted($q, $search, $restriction, $threshold, $entireText, $entireTextOnly);
    }
    
}

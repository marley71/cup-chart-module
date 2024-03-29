<?php  namespace Modules\CupChart\Foorm\Importazione;


use App\Models\GraficoTabella;
use App\Services\CreaGrafico;
use Gecche\Cupparis\App\Foorm\Base\FoormInsert as BaseFoormInsert;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Modules\CupChart\Services\GeneraGrafico;

class FoormInsert extends BaseFoormInsert
{

    protected $fileId;

    public $validationSettings = [
        'rules' => [
            'file' => 'required',
        ]
//        'username' => 'required|between:4,255|unique:users,username',
    ];


    public function filterPredefinedValuesFromInput($value)
    {

        if (is_array($value)) {

            if (Arr::get($value, 'file') == '{}') {
                $value['file'] = null;
            }

        }

        return parent::filterPredefinedValuesFromInput($value);
    }

    protected function setFieldsToModel($model, $configFields, $input)
    {
//        unset($input['mainrole']);
//        unset($input['password_confirmation']);
        if (!Arr::get($input,'nome',null)) {
            $fileData = json_decode(Arr::get($input,'file','{}'),true);
            $input['nome'] = Arr::get($fileData,'filename');
            $input['nome'] = substr($input['nome'],0,strrpos($input['nome'],'.'));
        }

        $model->setFieldsFromResource($input,'file');
        unset($input['file']);

        parent::setFieldsToModel($model, $configFields, $input);

    }

    protected function saveModel($input)
    {
        DB::beginTransaction();
        try {
            // in caso di nome non definito ci metto il nome del file senza estensione

            parent::saveModel($input);
            $this->model->filesOps($input,'file');
            DB::commit();

        } catch (\Exception $e) {
            DB::rollback();
        }

    }

    public function save($input = null, $validate = true)
    {
//        $fileData = request()->get('file',[]);
//        //if ()
        $saved = parent::save($input, $validate); // TODO: Change the autogenerated stub
        // TODO meglio far partire un processo in coda
        Artisan::call('manage-importazione',[
            'id' => $this->model->getKey(),
            '--strict' => 1
        ]);
        Artisan::call('crea-importazione-json',['id' => $this->model->getKey()]);
        $graf = new GeneraGrafico();

        $graf->creaGrafico($this->model->getKey());
        return $saved;
    }


}

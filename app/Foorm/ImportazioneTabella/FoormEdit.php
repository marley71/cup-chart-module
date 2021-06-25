<?php

namespace App\Foorm\ImportazioneTabella;


use App\Models\Importazione;
use Gecche\Cupparis\App\Foorm\Base\FoormEdit as BaseFoormEdit;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

class FoormEdit extends \Modules\CupChart\Foorm\ImportazioneTabella\FoormEdit
{

    public function finalizeData($finalizationFunc = null) {
        $this->formData['tabella_excel'] = $this->model->tabella_excel;

//        $this->formData['elastic_id'] = implode("_",$elId);
    }

}
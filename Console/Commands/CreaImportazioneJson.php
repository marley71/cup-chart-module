<?php  namespace Modules\CupChart\Console\Commands;


use App\Models\Importazione;
use App\Services\Importazione\ElasticJsonService;
use App\Services\Importazione\SplitTablesService;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class CreaImportazioneJson extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crea-importazione-json
                    {id : Id della importazione da lavorare}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crea CNR-GAP json x elastic da xls file import.';

    /**
     * The filesystem instance.
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $files;

    protected $importazioneService;

    protected $elasticPath = 'files/elastic/';

    /**
     * Create a new reminder table command instance.
     *
     * @param \Illuminate\Filesystem\Filesystem $files
     * @return void
     */
    public function __construct(Filesystem $files)
    {
        parent::__construct();

        $this->files = $files;


    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

        $importazioneId = $this->argument('id');

        $importazione = Importazione::find($importazioneId);

        if (!$importazione || !$importazioneId) {
            throw new \Exception("Importazione inesistente");
        }

        try {
            Log::info('CreaImportazioneJson', ['importazioneId' => $importazioneId]);
            $this->importazioneService = new ElasticJsonService($importazione);


            $dir = storage_path($this->elasticPath . $importazione->getKey());
            if (!File::exists($dir)) {
                File::makeDirectory($dir);
            }
            $lastSheetCode = null;
            $prog = 1;
            Log::info('importazione->tabelle', ['importazione->tabelle' => $importazione->tabelle]);
            foreach ($importazione->tabelle as $tabella) {
                Log::info('tabella', ['tabella' => $tabella]);
                $metadata = json_decode($tabella->metadata,true);
                $json = $this->importazioneService->getTableJson($tabella->sheetname, $tabella->nome, $metadata);

                $sheetCode = substr(str_pad(str_replace(' ', '', $tabella->sheetname), 3, 'X', STR_PAD_RIGHT), 0, 3);
                if ($sheetCode == $lastSheetCode) {
                    $prog++;
                } else {
                    $prog = 1;
                }
                $elId = [
                    $tabella->importazione_id,
                    $tabella->sheetname,
                    $tabella->progressivo,
                ];

                //$filename = $dir . '/' . 'values_' . $sheetCode . $prog . '_' . $tabella->progressivo . '.json';
                $filename = $dir .'/' . implode('_',$elId) . ".json";
                File::put($filename, $json);

            }
        } catch (\Exception $e) {
            throw $e;
        }


        $this->comment('Elastic json per importazione ' . $importazioneId . ' creato');

    }


}

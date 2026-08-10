<?php namespace Modules\CupChart\Console\Commands;


use App\Models\Importazione;
use App\Services\Importazione\SplitStrictTablesService;
use App\Services\Importazione\SplitTablesService;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class ManageImportazione extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'manage-importazione
                    {id : Id della importazione da lavorare}
                    {--strict=0 : excel in formato strict, default 0}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage CNR-GAP xls file import.';

    /**
     * The filesystem instance.
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $files;

    protected $importazioneService;

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

            $isStrict = $this->option('strict') == 1 ? true : false;

            $this->importazioneService = $isStrict ?
                new SplitStrictTablesService($importazione) :
                new SplitTablesService($importazione);

            $importazioneData = $importazione->data;
            $sheets = Arr::get($importazioneData, 'sheets', []);
            Log::info('sheets', ['sheets' => $sheets]);
            foreach ($sheets as $sheetName => $sheetInfo) {
                Log::info("SHEET::: ".$sheetName);
                $importazioneData['sheets'][$sheetName]['nTables'] = $this->importazioneService->getTablesFromSheet($sheetName,
                    $sheetInfo);
                //PER BLOCCARE AL PRIMO FOGLIO
//                break;
            }
            $importazione->data = $importazioneData;
            $importazione->save();
        } catch (\Exception $e) {
            throw $e;
        }


        $this->comment('Xls import ' . $importazioneId . ' processed');

    }


}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearCollection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:clear-collection';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clears all documents in the aslaw_logs collection';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        DB::connection('mongodb')->table('aslaw_logs')->delete();
        $this->info('All documents in the collection have been deleted.');
    }
}
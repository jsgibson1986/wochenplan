<?php

namespace App\Console\Commands;

use App\Models\Quelle;
use App\Services\Kalender\KalenderSync;
use Illuminate\Console\Command;

class KalenderSyncBefehl extends Command
{
    protected $signature = 'kalender:sync {--quelle= : Nur diese Quelle (ID)}';

    protected $description = 'Holt alle aktiven Kalenderquellen neu ab';

    public function handle(KalenderSync $sync): int
    {
        if ($id = $this->option('quelle')) {
            $quelle = Quelle::findOrFail($id);
            $this->line($quelle->name.': '.$sync->quelle($quelle));

            return self::SUCCESS;
        }

        foreach ($sync->alle() as $name => $ergebnis) {
            $this->line($name.': '.$ergebnis);
        }

        return self::SUCCESS;
    }
}

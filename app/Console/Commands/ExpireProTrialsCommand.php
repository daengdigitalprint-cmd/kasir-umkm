<?php

namespace App\Console\Commands;

use App\Services\TrialService;
use Illuminate\Console\Command;

class ExpireProTrialsCommand extends Command
{
    protected $signature = 'trial:expire';

    protected $description = 'Akhiri trial Pro yang lewat waktu dan kirim pengingat bertahap (H-3, H-1, H+3)';

    public function handle(): int
    {
        $expired = TrialService::expireExpiredTrials();
        $this->info("Expired {$expired} trial(s).");

        $reminders = TrialService::sendReminders();
        $this->info("Sent {$reminders} reminder(s).");

        return self::SUCCESS;
    }
}

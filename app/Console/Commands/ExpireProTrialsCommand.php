<?php

namespace App\Console\Commands;

use App\Services\TrialService;
use Illuminate\Console\Command;

class ExpireProTrialsCommand extends Command
{
    protected $signature = 'trial:expire';

    protected $description = 'Expire all Pro trials that have passed their end date and send 24h reminders';

    public function handle(): int
    {
        $expired = TrialService::expireExpiredTrials();
        $this->info("Expired {$expired} trial(s).");

        $reminders = TrialService::sendExpiryReminders();
        $this->info("Sent {$reminders} reminder(s).");

        return self::SUCCESS;
    }
}

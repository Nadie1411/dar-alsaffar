<?php

namespace App\Console\Commands;

use App\Services\Store\Payments\MyFatoorahClient;
use App\Services\Store\Payments\PaymentService;
use Illuminate\Console\Command;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Check online payments that were never confirmed, and release orders whose payment ran out';

    public function handle(PaymentService $payments, MyFatoorahClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->components->info('Online payment is not configured, so there is nothing to reconcile.');

            return self::SUCCESS;
        }

        $counts = $payments->reconcile();

        $this->components->twoColumnDetail('Payments checked', (string) $counts['checked']);
        $this->components->twoColumnDetail('Found paid', (string) $counts['paid']);
        $this->components->twoColumnDetail('Expired, order released', (string) $counts['expired']);
        $this->components->twoColumnDetail('Could not be reached', (string) $counts['unreachable']);

        return self::SUCCESS;
    }
}

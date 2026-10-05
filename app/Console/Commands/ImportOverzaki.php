<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Store\Import\OverzakiImporter;
use App\Support\Money;
use Illuminate\Console\Command;
use RuntimeException;

class ImportOverzaki extends Command
{
    protected $signature = 'store:import-overzaki
        {--skip-images : Keep pointing at Overzaki\'s image CDN instead of copying the images here}
        {--skip-fees : Do not read each delivery area\'s fee from Overzaki}
        {--deactivate-missing : Hide local products that Overzaki no longer lists}
        {--force : Run even though the store has been switched to its own backend}
        {--if-empty : Do nothing when the catalogue already has products, so it is safe to run on every deployment}';

    protected $description = 'Copy the catalogue, delivery areas and add-ons from Overzaki into this application\'s own database';

    public function handle(OverzakiImporter $importer): int
    {
        // A catalogue that is already there is the shop's own by now: leave it be.
        // With nothing in it there is nothing to overwrite, so the guard below
        // has no reason to stop the first import.
        if ($this->option('if-empty')) {
            if (Product::query()->exists()) {
                $this->components->info('The catalogue already has products, so nothing was imported.');

                return self::SUCCESS;
            }
        } elseif (config('store.backend') === 'local' && ! $this->option('force')) {
            // After the switch-over the admin panel is where the shop is managed,
            // and a re-import would overwrite whatever was changed there.
            $this->components->error('The store runs on its own backend now, so importing from Overzaki would overwrite changes made in the admin panel. Pass --force only if that is what you want.');

            return self::FAILURE;
        }

        try {
            $report = $importer
                ->reportProgressTo(fn (string $message) => $this->components->info($message))
                ->run([
                    'images' => ! $this->option('skip-images'),
                    'fees' => ! $this->option('skip-fees'),
                    'deactivate_missing' => (bool) $this->option('deactivate-missing'),
                ]);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Imported', 'Created', 'Updated'],
            collect($report->counts)->map(fn (array $count, string $entity) => [
                str_replace('_', ' ', $entity), $count['created'], $count['updated'],
            ])->values()->all()
        );

        if (! $this->option('skip-images')) {
            $this->components->twoColumnDetail('Images', sprintf(
                '%d downloaded, %d already here, %d failed',
                $report->images['downloaded'], $report->images['reused'], $report->images['failed'],
            ));
        }

        if ($report->deactivated > 0) {
            $this->components->twoColumnDetail('Products hidden (no longer in Overzaki)', (string) $report->deactivated);
        }

        if ($report->observed !== []) {
            $this->newLine();
            $this->components->info('Store-wide checkout settings Overzaki applies (to confirm in the admin panel later)');

            foreach ($report->observed as $name => $value) {
                $this->components->twoColumnDetail(
                    str_replace('_', ' ', $name),
                    is_bool($value) ? ($value ? 'yes' : 'no') : Money::format(Money::fromFils((int) $value), 'KWD'),
                );
            }
        }

        foreach ($report->warnings as $warning) {
            $this->components->warn($warning);
        }

        return self::SUCCESS;
    }
}

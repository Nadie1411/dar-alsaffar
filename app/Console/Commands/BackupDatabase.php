<?php

namespace App\Console\Commands;

use App\Services\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Keeps a dated, consistent copy of the shop's database — and of its settings
 * file — and forgets copies older than a fortnight.
 *
 * The copy is made with SQLite's own `VACUUM INTO`, which is safe while the
 * shop is taking orders and, unlike copying the file, does not miss what is
 * still in the write-ahead log. It lands on the same server: for a backup that
 * survives losing the server, copy the folder somewhere else as well.
 */
class BackupDatabase extends Command
{
    protected $signature = 'store:backup
        {--path= : Where to put the copy (default storage/backups)}
        {--keep=14 : How many days of copies to keep}';

    protected $description = 'Make a dated copy of the shop database and settings, and prune old ones';

    public function handle(Settings $settings): int
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->components->error('This only backs up an SQLite database.');

            return self::FAILURE;
        }

        $directory = rtrim((string) ($this->option('path') ?: storage_path('backups')), '/');
        $stamp = now()->format('Ymd-His');
        $target = "{$directory}/database-{$stamp}.sqlite";

        File::ensureDirectoryExists($directory);

        // The target is quoted for SQL; it is a path this command built, never
        // anything a visitor typed.
        DB::statement('VACUUM INTO '.DB::getPdo()->quote($target));

        if (! File::exists($target) || File::size($target) === 0) {
            $this->components->error('The backup could not be written to '.$directory.'.');

            return self::FAILURE;
        }

        $this->components->info('Database copied to '.$target);

        $source = $settings->file();

        if (File::exists($source)) {
            File::copy($source, "{$directory}/settings-{$stamp}.json");
        }

        $removed = $this->prune($directory, max((int) $this->option('keep'), 1));

        if ($removed > 0) {
            $this->components->info("Removed {$removed} old file(s).");
        }

        return self::SUCCESS;
    }

    /**
     * Deletes the copies this command made that are older than the days to keep.
     */
    protected function prune(string $directory, int $days): int
    {
        $removed = 0;
        $cutoff = now()->subDays($days)->getTimestamp();

        foreach (File::files($directory) as $file) {
            if (preg_match('/^(database-\d{8}-\d{6}\.sqlite|settings-\d{8}-\d{6}\.json)$/', $file->getFilename()) === 1
                && $file->getMTime() < $cutoff) {
                File::delete($file->getPathname());
                $removed++;
            }
        }

        return $removed;
    }
}

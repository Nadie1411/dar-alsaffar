<?php

namespace Tests\Feature\Console\Commands;

use App\Models\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use PDO;
use Tests\Concerns\IsolatesSettings;
use Tests\TestCase;

class BackupDatabaseTest extends TestCase
{
    use IsolatesSettings, LazilyRefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/store-backup-test-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    /**
     * Runs the command the way the scheduler does: with no transaction open.
     * A test normally runs inside one, which SQLite will not VACUUM from.
     *
     * @param  array<string,mixed>  $options
     */
    private function backUp(array $options = []): PendingCommand
    {
        DB::select('select 1'); // wakes the lazily refreshed database

        while (DB::transactionLevel() > 0) {
            DB::commit();
        }

        return $this->artisan('store:backup', ['--path' => $this->directory] + $options);
    }

    /** @return array<int,string> */
    private function copies(string $pattern): array
    {
        return array_map('basename', glob($this->directory.'/'.$pattern) ?: []);
    }

    public function test_it_makes_a_dated_copy_that_holds_what_the_shop_holds(): void
    {
        Product::factory()->create(['name_en' => 'Kept Safe']);

        $this->backUp()
            ->expectsOutputToContain('Database copied to')
            ->assertExitCode(0);

        $files = $this->copies('database-*.sqlite');
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^database-\d{8}-\d{6}\.sqlite$/', $files[0]);

        $copy = new PDO('sqlite:'.$this->directory.'/'.$files[0]);
        $this->assertSame('Kept Safe', $copy->query('select name_en from products')->fetchColumn());
        $this->assertSame('ok', $copy->query('pragma integrity_check')->fetchColumn());
    }

    public function test_the_settings_file_is_copied_alongside_when_there_is_one(): void
    {
        $this->setting(['popup.title' => 'Eid offers']);

        $this->backUp()->assertExitCode(0);

        $copies = $this->copies('settings-*.json');
        $this->assertCount(1, $copies);
        $this->assertSame('Eid offers', json_decode(File::get($this->directory.'/'.$copies[0]), true)['popup']['title']);
    }

    public function test_no_settings_copy_is_made_when_there_are_no_settings(): void
    {
        $this->backUp()->assertExitCode(0);

        $this->assertSame([], $this->copies('settings-*.json'));
    }

    public function test_copies_older_than_the_days_to_keep_are_removed_and_nothing_else_is(): void
    {
        File::ensureDirectoryExists($this->directory);
        $old = now()->subDays(30)->getTimestamp();

        foreach (['database-20200101-000000.sqlite', 'settings-20200101-000000.json'] as $name) {
            File::put($this->directory.'/'.$name, 'old');
            touch($this->directory.'/'.$name, $old);
        }

        // Things that are not this command's, however old.
        foreach (['notes.txt', 'database-notes.sqlite', 'keep-me.json'] as $name) {
            File::put($this->directory.'/'.$name, 'mine');
            touch($this->directory.'/'.$name, $old);
        }

        $this->backUp(['--keep' => 14])
            ->expectsOutputToContain('Removed 2 old file(s).')
            ->assertExitCode(0);

        $this->assertSame([], $this->copies('database-2020*'));
        $this->assertSame([], $this->copies('settings-2020*'));
        $this->assertFileExists($this->directory.'/notes.txt');
        $this->assertFileExists($this->directory.'/database-notes.sqlite');
        $this->assertFileExists($this->directory.'/keep-me.json');
        $this->assertCount(1, $this->copies('database-2*.sqlite'), 'and today\'s copy');
    }

    public function test_a_recent_copy_is_never_pruned(): void
    {
        File::ensureDirectoryExists($this->directory);
        File::put($this->directory.'/database-20261001-030000.sqlite', 'recent');
        touch($this->directory.'/database-20261001-030000.sqlite', now()->subDays(3)->getTimestamp());

        $this->backUp(['--keep' => 14])->assertExitCode(0);

        $this->assertFileExists($this->directory.'/database-20261001-030000.sqlite');
    }

    public function test_it_is_scheduled_nightly(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('store:backup')->assertExitCode(0);
    }
}

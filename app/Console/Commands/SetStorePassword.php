<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Sets the control-panel password.
 *
 * The password is never stored — only its hash goes into .env, so the file
 * cannot leak the password itself.
 */
class SetStorePassword extends Command
{
    protected $signature = 'store:password {--show : Print the hash instead of writing it to .env}';

    protected $description = 'Set the password for the store control panel';

    public function handle(): int
    {
        $password = $this->secret('New control panel password');

        if (! is_string($password) || strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        if ($password !== $this->secret('Confirm password')) {
            $this->error('The passwords did not match.');

            return self::FAILURE;
        }

        $hash = Hash::make($password);

        if ($this->option('show')) {
            $this->line('ADMIN_PASSWORD_HASH="'.$hash.'"');

            return self::SUCCESS;
        }

        $this->writeEnv($hash);
        $this->call('config:clear');

        $this->info('Control panel password set. Open /admin to sign in.');

        return self::SUCCESS;
    }

    protected function writeEnv(string $hash): void
    {
        $path = base_path('.env');
        $line = 'ADMIN_PASSWORD_HASH="'.$hash.'"';
        $contents = file_exists($path) ? (string) file_get_contents($path) : '';

        $contents = preg_match('/^ADMIN_PASSWORD_HASH=.*$/m', $contents)
            ? (string) preg_replace('/^ADMIN_PASSWORD_HASH=.*$/m', $line, $contents)
            : rtrim($contents, "\n")."\n\n".$line."\n";

        file_put_contents($path, $contents);
    }
}

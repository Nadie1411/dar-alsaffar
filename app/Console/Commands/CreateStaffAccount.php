<?php

namespace App\Console\Commands;

use App\Enums\AdminRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Console\Input\StreamableInputInterface;

/**
 * Creates the first sign-in for the admin panel — or resets a password when
 * someone is locked out and nobody else with access is left to help.
 *
 * Two ways in. By hand, it asks for the password. From a deployment it is run
 * with `--bootstrap --password-stdin`: the password arrives on standard input
 * (so it is never on a command line or in a log), the account is made only if
 * no owner exists yet, and every later deployment leaves it exactly as it is —
 * so a password changed in the panel is never put back.
 */
class CreateStaffAccount extends Command
{
    protected $signature = 'store:admin
        {email : The address they sign in with}
        {--name= : Their name (asked for when the account is new)}
        {--role=owner : owner, manager or staff}
        {--locale=ar : ar or en}
        {--bootstrap : Make the first owner only: do nothing when an active owner already exists}
        {--password-stdin : Read the password from standard input instead of asking for it}';

    protected $description = 'Create a panel account, or reset the password of an existing one';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $bootstrap = (bool) $this->option('bootstrap');
        $role = $bootstrap ? AdminRole::Owner : AdminRole::tryFrom((string) $this->option('role'));
        $locale = (string) $this->option('locale');

        if (Validator::make(['email' => $email], ['email' => ['email']])->fails()) {
            $this->error('That is not a valid e-mail address.');

            return self::FAILURE;
        }

        if ($role === null || ! in_array($locale, ['ar', 'en'], true)) {
            $this->error('The role must be owner, manager or staff, and the locale ar or en.');

            return self::FAILURE;
        }

        // Everything from here on is for the first owner only: once there is one,
        // this run has nothing to do — and nothing it does could change that account.
        if ($bootstrap && User::query()->where('role', AdminRole::Owner->value)->where('is_active', true)->exists()) {
            $this->info('An owner account already exists, so nothing was changed.');

            return self::SUCCESS;
        }

        $user = User::query()->where('email', $email)->first();
        $password = $this->password($user === null);

        if ($password === null) {
            return self::FAILURE;
        }

        if ($user !== null) {
            $user->update(['password' => $password, 'is_active' => true] + ($bootstrap ? ['role' => AdminRole::Owner] : []));

            $this->info($bootstrap
                ? 'Made the account an owner and set its password. Any session it had open is signed out.'
                : "Password reset for {$email}. Any session they had open is signed out.");

            return self::SUCCESS;
        }

        $name = trim((string) ($this->option('name') ?: $this->ask('Name')));

        if ($name === '') {
            $this->error('A name is needed.');

            return self::FAILURE;
        }

        User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'locale' => $locale,
            'is_active' => true,
        ]);

        // A deployment's output is read by anyone who can see its log, so the address is left out of it.
        $this->info($bootstrap
            ? 'Created the owner account. It can sign in at /panel.'
            : "Created {$role->value} account for {$email}. They can sign in at /panel.");

        return self::SUCCESS;
    }

    /**
     * The password, from standard input or asked for, and only if it is one
     * the panel itself would accept: ten characters or more, with letters and numbers.
     */
    protected function password(bool $isNew): ?string
    {
        $fromStdin = (bool) $this->option('password-stdin');
        $password = $fromStdin
            ? rtrim($this->readStandardInput(), "\r\n")
            : $this->secret($isNew ? 'Password' : 'New password');

        $check = Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', Password::min(10)->letters()->numbers()]],
        );

        if ($check->fails()) {
            $this->error('The password must be at least 10 characters, with letters and numbers.');

            return null;
        }

        if (! $fromStdin && $password !== $this->secret('Confirm password')) {
            $this->error('The passwords did not match.');

            return null;
        }

        return (string) $password;
    }

    protected function readStandardInput(): string
    {
        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;

        return (string) stream_get_contents($stream ?? STDIN);
    }
}

<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\AdminRole;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Concerns\UsesLocalStore;
use Tests\TestCase;

class BootstrapOwnerTest extends TestCase
{
    use LazilyRefreshDatabase, UsesLocalStore;

    private const PASSWORD = 'Deploy-owner-pass-1';

    /**
     * Runs store:admin the way a deployment does: arguments on the command line, the password on standard input.
     *
     * @param  array<string,mixed>  $options
     * @return array{0:int,1:string}
     */
    private function runWithStdin(string $email, string $stdin, array $options = []): array
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $stdin);
        rewind($stream);

        $input = new ArrayInput(['command' => 'store:admin', 'email' => $email, '--password-stdin' => true] + $options);
        $input->setStream($stream);
        $input->setInteractive(false);
        $output = new BufferedOutput;

        $code = $this->app->make(Kernel::class)->handle($input, $output);

        return [$code, $output->fetch()];
    }

    public function test_it_creates_the_first_owner_from_a_password_on_standard_input(): void
    {
        [$code, $output] = $this->runWithStdin('Boss@Dar.test', self::PASSWORD."\n", ['--bootstrap' => true, '--name' => 'The Boss']);

        $this->assertSame(0, $code);
        $user = User::query()->where('email', 'boss@dar.test')->firstOrFail();
        $this->assertSame(AdminRole::Owner, $user->role);
        $this->assertTrue($user->is_active);
        $this->post('/panel/login', ['email' => 'boss@dar.test', 'password' => self::PASSWORD])
            ->assertRedirect(route('panel.dashboard'));
        $this->assertStringNotContainsString('boss@dar.test', $output);
        $this->assertStringNotContainsString(self::PASSWORD, $output);
    }

    public function test_it_changes_nothing_once_an_active_owner_exists(): void
    {
        $owner = User::factory()->create(['email' => 'owner@dar.test', 'role' => AdminRole::Owner, 'is_active' => true, 'password' => 'Original-pass-123']);
        $before = $owner->fresh()->password;

        [$code] = $this->runWithStdin('owner@dar.test', 'Changed-pass-12345', ['--bootstrap' => true]);
        [$codeForAnother] = $this->runWithStdin('other@dar.test', self::PASSWORD, ['--bootstrap' => true, '--name' => 'Other']);

        $this->assertSame(0, $code);
        $this->assertSame(0, $codeForAnother);
        $this->assertSame($before, $owner->fresh()->password);
        $this->assertDatabaseMissing('users', ['email' => 'other@dar.test']);
    }

    public function test_it_promotes_an_existing_member_when_there_is_no_owner_yet(): void
    {
        $manager = User::factory()->create(['email' => 'mgr@dar.test', 'role' => AdminRole::Manager, 'is_active' => false]);

        [$code] = $this->runWithStdin('mgr@dar.test', self::PASSWORD, ['--bootstrap' => true]);

        $this->assertSame(0, $code);
        $this->assertSame(AdminRole::Owner, $manager->fresh()->role);
        $this->assertTrue($manager->fresh()->is_active);
    }

    public function test_an_inactive_owner_does_not_count_as_an_owner(): void
    {
        User::factory()->create(['role' => AdminRole::Owner, 'is_active' => false]);

        [$code] = $this->runWithStdin('new@dar.test', self::PASSWORD, ['--bootstrap' => true, '--name' => 'New']);

        $this->assertSame(0, $code);
        $this->assertDatabaseHas('users', ['email' => 'new@dar.test', 'role' => AdminRole::Owner->value]);
    }

    public function test_only_the_trailing_newline_is_stripped_from_the_password(): void
    {
        $this->runWithStdin('spaces@dar.test', " Pass with spaces 1 \n", ['--bootstrap' => true, '--name' => 'Spaces']);

        $this->post('/panel/login', ['email' => 'spaces@dar.test', 'password' => ' Pass with spaces 1 '])
            ->assertRedirect(route('panel.dashboard'));
    }

    public function test_a_weak_or_missing_password_creates_nothing(): void
    {
        foreach (['', "short1\n", "onlyletterslongenough\n"] as $stdin) {
            [$code, $output] = $this->runWithStdin('weak@dar.test', $stdin, ['--bootstrap' => true, '--name' => 'Weak']);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('at least 10 characters', $output);
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_bad_address_is_refused(): void
    {
        [$code] = $this->runWithStdin('not-an-email', self::PASSWORD, ['--bootstrap' => true, '--name' => 'X']);

        $this->assertSame(1, $code);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_without_bootstrap_it_still_resets_the_password_of_an_existing_owner(): void
    {
        $owner = User::factory()->create(['email' => 'owner@dar.test', 'role' => AdminRole::Owner, 'is_active' => true]);

        [$code] = $this->runWithStdin('owner@dar.test', self::PASSWORD);

        $this->assertSame(0, $code);
        $this->post('/panel/login', ['email' => $owner->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('panel.dashboard'));
    }

    public function test_the_import_with_if_empty_does_nothing_when_the_catalogue_already_has_products(): void
    {
        Http::preventStrayRequests();
        Product::factory()->create();

        $this->artisan('store:import-overzaki', ['--if-empty' => true])
            ->expectsOutputToContain('nothing was imported')
            ->assertExitCode(0);

        $this->assertDatabaseCount('products', 1);
    }
}

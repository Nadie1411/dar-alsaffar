<?php

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * scripts/deploy-lib.sh edits the server's .env during a deployment, so it is
 * run here for real, against a temporary file.
 */
class DeployLibTest extends TestCase
{
    private string $env;

    protected function setUp(): void
    {
        parent::setUp();

        $this->env = tempnam(sys_get_temp_dir(), 'env');
    }

    protected function tearDown(): void
    {
        @unlink($this->env);

        parent::tearDown();
    }

    private function bash(string $body): int
    {
        $lib = dirname(__DIR__, 3).'/scripts/deploy-lib.sh';
        exec('bash -c '.escapeshellarg('set -euo pipefail; . '.escapeshellarg($lib).'; '.$body).' 2>&1', $output, $code);

        return $code;
    }

    private function setValue(string $key, string $value): void
    {
        $this->assertSame(0, $this->bash('set_env_value '.escapeshellarg($this->env).' '.escapeshellarg($key).' '.escapeshellarg($value)));
    }

    public function test_it_replaces_the_line_and_leaves_the_others_alone(): void
    {
        file_put_contents($this->env, "APP_KEY=base64:abc\nSTORE_BACKEND=overzaki\nAPP_ENV=production\n");

        $this->setValue('STORE_BACKEND', 'local');

        $this->assertSame("APP_KEY=base64:abc\nSTORE_BACKEND=local\nAPP_ENV=production\n", file_get_contents($this->env));
    }

    public function test_it_appends_a_missing_key_and_creates_a_missing_file(): void
    {
        file_put_contents($this->env, "APP_ENV=production\n");
        $this->setValue('STORE_BACKEND', 'local');
        $this->assertSame("APP_ENV=production\nSTORE_BACKEND=local\n", file_get_contents($this->env));

        unlink($this->env);
        $this->setValue('STORE_BACKEND', 'local');
        $this->assertSame("STORE_BACKEND=local\n", file_get_contents($this->env));
    }

    public function test_it_does_not_match_a_key_that_only_starts_the_same_way(): void
    {
        file_put_contents($this->env, "STORE_BACKEND_OLD=x\n#STORE_BACKEND=y\n");

        $this->setValue('STORE_BACKEND', 'local');

        $this->assertSame("STORE_BACKEND_OLD=x\n#STORE_BACKEND=y\nSTORE_BACKEND=local\n", file_get_contents($this->env));
    }

    public function test_it_keeps_a_duplicated_key_to_a_single_line(): void
    {
        file_put_contents($this->env, "STORE_BACKEND=a\nX=1\nSTORE_BACKEND=b\n");

        $this->setValue('STORE_BACKEND', 'local');

        $this->assertSame("STORE_BACKEND=local\nX=1\n", file_get_contents($this->env));
    }

    public function test_it_writes_awkward_values_exactly_as_given(): void
    {
        file_put_contents($this->env, "K=old\n");

        $this->setValue('K', 'a&b\\1/c"d $HOME');

        $this->assertSame("K=a&b\\1/c\"d \$HOME\n", file_get_contents($this->env));
    }

    /**
     * @return array<string,array{0:string,1:int}>
     */
    public static function backends(): array
    {
        return ['local' => ['local', 0], 'overzaki' => ['overzaki', 0], 'typo' => ['Local', 1], 'empty' => ['', 1], 'other' => ['shopify', 1]];
    }

    #[DataProvider('backends')]
    public function test_only_a_backend_the_app_can_run_is_accepted(string $name, int $expected): void
    {
        $this->assertSame($expected, $this->bash('valid_backend '.escapeshellarg($name)));
    }
}

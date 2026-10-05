<?php

namespace Tests\Concerns;

trait LoadsFixtures
{
    /**
     * @return array<string,mixed>
     */
    protected function fixture(string $name): array
    {
        return json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/'.$name.'.json')),
            true,
            flags: JSON_THROW_ON_ERROR
        );
    }
}

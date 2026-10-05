<?php

namespace App\Services\Store\Import;

use Illuminate\Database\Eloquent\Model;

/**
 * What an import run did, for the console to print and the tests to assert.
 */
class ImportReport
{
    /** @var array<string,array{created:int,updated:int}> */
    public array $counts = [];

    /** @var array<int,string> */
    public array $warnings = [];

    /** @var array{downloaded:int,reused:int,failed:int} */
    public array $images = ['downloaded' => 0, 'reused' => 0, 'failed' => 0];

    /**
     * Store-wide settings Overzaki applies at checkout, read off a real quote.
     * They are reported rather than stored: the owner confirms them when the
     * settings screen exists.
     *
     * @var array<string,mixed>
     */
    public array $observed = [];

    public int $deactivated = 0;

    /** @var array<string,array<int|string,true>> */
    private array $seen = [];

    /** Counts a row once per run, even when it is saved again (a category shared by several products). */
    public function record(string $entity, Model $model): void
    {
        if (isset($this->seen[$entity][$model->getKey()])) {
            return;
        }

        $this->seen[$entity][$model->getKey()] = true;
        $this->counts[$entity] ??= ['created' => 0, 'updated' => 0];
        $this->counts[$entity][$model->wasRecentlyCreated ? 'created' : 'updated']++;
    }

    public function warn(string $message): void
    {
        if (! in_array($message, $this->warnings, true)) {
            $this->warnings[] = $message;
        }
    }
}

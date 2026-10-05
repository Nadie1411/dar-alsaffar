<?php

namespace App\Services\Store;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Keeps the panel's activity log: who did what, to which order, product or
 * person, and when.
 */
class ActivityLogger
{
    /**
     * @param  array<string,mixed>  $properties  what changed — kept short, never a secret
     */
    public function record(?User $by, string $action, ?Model $subject = null, array $properties = [], ?string $label = null): ActivityLog
    {
        return ActivityLog::query()->create([
            'user_id' => $by?->id,
            'action' => $action,
            'subject_type' => $subject === null ? null : Str::snake(class_basename($subject)),
            'subject_id' => $subject?->getKey(),
            'subject_label' => $label ?? $this->labelFor($subject),
            'properties' => $properties === [] ? null : $properties,
            'ip' => request()?->ip(),
        ]);
    }

    /** What to call the subject in the log: its number, code, name or e-mail, whichever it has. */
    protected function labelFor(?Model $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        foreach (['number', 'code', 'name_ar', 'name_en', 'name', 'email'] as $attribute) {
            $value = $subject->getAttribute($attribute);

            if (is_string($value) && trim($value) !== '') {
                return Str::limit(trim($value), 180, '');
            }
        }

        return '#'.$subject->getKey();
    }
}

<?php

namespace App\Models;

use Database\Factories\GatewayCredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment gateway's keys, as saved from the admin panel.
 *
 * The two secrets are encrypted with the application key when they are
 * written and decrypted only when the gateway is called. They are hidden from
 * serialisation, so they cannot reach a response, a log line or a job payload
 * by accident.
 */
#[Fillable(['provider', 'api_key', 'webhook_secret', 'api_url', 'enabled', 'updated_by'])]
#[Hidden(['api_key', 'webhook_secret'])]
class GatewayCredential extends Model
{
    /** @use HasFactory<GatewayCredentialFactory> */
    use HasFactory;

    public const MYFATOORAH = 'myfatoorah';

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * The key the shop signs its push messages with. The public half is handed to
 * browsers; the private half is encrypted with the application key and hidden
 * from serialisation.
 */
#[Fillable(['public_key', 'private_key'])]
#[Hidden(['private_key'])]
class PushKey extends Model
{
    protected function casts(): array
    {
        return ['private_key' => 'encrypted'];
    }
}

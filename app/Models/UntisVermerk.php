<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UntisVermerk extends Model
{
    protected $table = 'untis_vermerke';

    protected $fillable = ['schluessel', 'titel', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

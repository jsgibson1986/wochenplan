<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Termin extends Model
{
    protected $table = 'termine';

    protected $fillable = ['quelle_id', 'uid', 'titel', 'beschreibung', 'ort', 'link', 'beginn', 'ende', 'ganztaegig'];

    protected function casts(): array
    {
        return [
            'beginn' => 'datetime',
            'ende' => 'datetime',
            'ganztaegig' => 'boolean',
        ];
    }

    public function quelle(): BelongsTo
    {
        return $this->belongsTo(Quelle::class);
    }

    /**
     * Termine, die den Zeitraum [von, bis) berühren. Ein Termin ohne Dauer
     * (beginn == ende) zählt, wenn er im Zeitraum beginnt.
     */
    public function scopeImZeitraum(Builder $query, \DateTimeInterface $von, \DateTimeInterface $bis): Builder
    {
        return $query
            ->where('beginn', '<', $bis)
            ->where(function (Builder $q) use ($von) {
                $q->where('ende', '>', $von)->orWhere('beginn', '>=', $von);
            });
    }
}

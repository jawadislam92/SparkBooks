<?php

namespace App\Models;

use App\Enums\EntryRole;
use App\Exceptions\Ledger\ImmutableLedger;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = [
        'entry_date',
        'memo',
        'source_type',
        'source_id',
        'entry_role',
        'reverses_entry_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'entry_role' => EntryRole::class,
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new ImmutableLedger;
        });

        static::deleting(function () {
            throw new ImmutableLedger;
        });
    }
}

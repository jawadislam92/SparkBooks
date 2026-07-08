<?php

namespace App\Models;

use App\Exceptions\Ledger\ImmutableLedger;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = [
        'account_id',
        'debit',
        'credit',
        'memo',
        'contact_id',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'integer',
            'credit' => 'integer',
            'contact_id' => 'integer',
        ];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
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

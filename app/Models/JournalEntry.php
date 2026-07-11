<?php

namespace App\Models;

use App\Enums\EntryRole;
use App\Exceptions\Ledger\ImmutableLedger;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'entry_number',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'entry_role' => EntryRole::class,
            'entry_number' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /**
     * The original entry THIS entry reverses (null for a primary entry).
     * Phase 7 Task 3: lets a reversal's own display surface the original's
     * entry_number (e.g. "reverses JE-000142") via
     * $reversal->reversesEntry?->displayNumber(), without a second query
     * built by hand at every call site.
     */
    public function reversesEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    /**
     * Phase 7 Task 3 (visible entry numbers): the human-readable,
     * gap-free, per-company sequential number surfaced on the General
     * Ledger and journal/audit views — DISTINCT from this row's own `id`.
     * `entry_number` is nullable only at the schema level (pre-Phase-7-
     * Task-3 rows would be null before the backfill migration runs); every
     * entry LedgerService::post creates always has one, so this only
     * returns null for a not-yet-backfilled legacy row.
     */
    public function displayNumber(): ?string
    {
        return $this->entry_number !== null ? sprintf('JE-%06d', $this->entry_number) : null;
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

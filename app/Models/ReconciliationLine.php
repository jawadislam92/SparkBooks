<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * THE "reconciled" marker (spec §7.9 / task brief): a journal_line is
 * reconciled if and only if a row exists here for it, under a `reconciliation
 * whose status is still 'completed'. journal_lines themselves stay immutable
 * (no column added there) — the (company_id, journal_line_id) unique index
 * on this table (see the migration) is what enforces "a line can't be
 * reconciled twice while a reconciliation is live". Reopening a
 * reconciliation deletes its rows here (ReconciliationService::reopen), which
 * is exactly what makes those lines reconcilable again.
 */
class ReconciliationLine extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'reconciliation_id',
        'journal_line_id',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(Reconciliation::class);
    }

    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class);
    }
}

<?php

namespace App\Models;

use App\Enums\EntryRole;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ManualJournal extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = ['entry_date', 'memo', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['entry_date' => 'date'];
    }

    /**
     * The posted (primary) JournalEntry this manual journal produced. There is
     * no FK column linking the two tables directly — JournalEntry is a
     * polymorphic-by-convention ledger record keyed by (source_type,
     * source_id), so the relation is expressed via that pair instead of a
     * normal foreign key.
     */
    public function journalEntry(): HasOne
    {
        return $this->hasOne(JournalEntry::class, 'source_id', 'id')
            ->where('source_type', 'manual_journal')
            ->where('entry_role', EntryRole::Primary->value);
    }
}

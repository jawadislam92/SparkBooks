<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\AccountBalances;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccount extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = ['name', 'type', 'currency_code', 'ledger_account_id', 'is_archived'];

    protected function casts(): array
    {
        return [
            'is_archived' => 'boolean',
        ];
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ledger_account_id');
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(Reconciliation::class);
    }

    /**
     * The ledger balance of this bank/cash account's linked Account, in
     * signed presentation minor units (same convention as
     * AccountBalances::asOf() — positive is a normal balance for an asset
     * account). Pass a precomputed `AccountBalances::asOf()` array when
     * rendering a list of several bank accounts (see
     * BankAccountController::index) so the whole-ledger aggregate query runs
     * once, not once per row; omit it to compute fresh for a single lookup.
     *
     * @param  array<int, int>|null  $balancesByAccount
     */
    public function balance(?array $balancesByAccount = null): int
    {
        $balancesByAccount ??= app(AccountBalances::class)->asOf();

        return $balancesByAccount[$this->ledger_account_id] ?? 0;
    }

    /** Every non-archived bank/cash account for this company — the shared filter every invoice/bill/expense payment picker applies. Mirrors Account/Contact/Item's own scopeActive. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }

    /** Every archived bank/cash account for this company — BankAccounts/Index's own "Show archived" filter. */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('is_archived', true);
    }
}

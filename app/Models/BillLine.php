<?php

namespace App\Models;

use App\Enums\BillStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class BillLine extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * `company_id` is deliberately absent — BelongsToCompany sets it on
     * create from CompanyContext; it must never be accepted from request
     * input (see BelongsToCompany::bootBelongsToCompany).
     */
    protected $fillable = [
        'bill_id',
        'description',
        'category_account_id',
        'tax_rate_id',
        'tax_rate_snapshot',
        'amount',
        'line_tax',
    ];

    protected function casts(): array
    {
        return [
            'tax_rate_snapshot' => 'decimal:4',
            'amount' => 'integer',
            'line_tax' => 'integer',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function categoryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'category_account_id');
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    /**
     * The draft-mutability freeze: once the parent bill has left draft
     * status, its lines are immutable. Correction is void + reissue of the
     * whole bill, never an edit to a frozen line — mirrors
     * InvoiceLine::booted() (Phase 3 T1) and ExpenseLine::booted() (Phase 4
     * T1) exactly, including the "always re-query fresh" reasoning below.
     * Freezes on ANY non-draft status including Void, per the Phase 4 brief.
     */
    protected static function booted(): void
    {
        $guard = function (BillLine $line) {
            // Deliberately NOT `$line->bill` — a belongsTo access caches the
            // relation on first load (e.g. during the `creating` event), and
            // a stale cached instance would still report the bill's status
            // as it was at creation time even after a later request
            // posts/voids the bill. Always re-query fresh so this guard sees
            // the bill's CURRENT status.
            $bill = Bill::query()->find($line->bill_id);

            if ($bill !== null && $bill->status !== BillStatus::Draft) {
                throw new LogicException(
                    'Bill lines cannot be added, edited, or removed once the bill has left draft status. Void and reissue the bill instead.'
                );
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }
}

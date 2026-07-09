<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class InvoiceLine extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * `company_id` is deliberately absent — BelongsToCompany sets it on
     * create from CompanyContext; it must never be accepted from request
     * input (see BelongsToCompany::bootBelongsToCompany).
     */
    protected $fillable = [
        'invoice_id',
        'description',
        'quantity',
        'unit_price',
        'income_account_id',
        'tax_rate_id',
        'tax_rate_snapshot',
        'line_subtotal',
        'line_tax',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'integer',
            'tax_rate_snapshot' => 'decimal:4',
            'line_subtotal' => 'integer',
            'line_tax' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function incomeAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'income_account_id');
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    /**
     * The draft-mutability freeze: once the parent invoice has left draft
     * status (isPosted() — sent/partially_paid/paid), its lines are
     * immutable. Correction is void + reissue of the whole invoice, never an
     * edit to a posted line — same "corrections are reversals, never edits"
     * principle as the ledger (LedgerService §6), applied here at the
     * document layer since invoice lines aren't ledger rows themselves.
     *
     * Deliberately on InvoiceLine, not Invoice: the invoice's own row keeps
     * changing after posting (status, amount_paid, sent_at, …) via later
     * send/payment/void services — only the LINE ITEMS are frozen.
     */
    protected static function booted(): void
    {
        $guard = function (InvoiceLine $line) {
            // Deliberately NOT `$line->invoice` — a belongsTo access caches
            // the relation on first load (e.g. during the `creating` event),
            // and a stale cached instance would still report the invoice's
            // status as it was at creation time even after a later request
            // posts the invoice. Always re-query fresh so this guard sees the
            // invoice's CURRENT status.
            $invoice = Invoice::query()->find($line->invoice_id);

            if ($invoice !== null && $invoice->status->isPosted()) {
                throw new LogicException(
                    'Invoice lines cannot be added, edited, or removed once the invoice is posted (sent/partially_paid/paid). Void and reissue the invoice instead.'
                );
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }
}

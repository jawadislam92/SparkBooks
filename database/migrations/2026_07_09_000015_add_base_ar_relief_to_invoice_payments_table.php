<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_payments', function (Blueprint $t) {
            // Minor units, BASE currency — the AR credit actually posted for
            // this payment (posting rule P2fx, spec §17.1). Distinct from
            // base_amount (the bank DEBIT — what the bank actually credited):
            // for a partial payment base_ar_relief = round_half_up(invoice's
            // fx_rate x amount); for the payment that brings the document
            // balance to exactly zero, base_ar_relief is instead the ENTIRE
            // remaining base AR for the invoice (base_total minus every prior
            // non-void payment's base_ar_relief) so base AR always zeroes
            // exactly — no phantom paisa of rounding residue ever survives a
            // fully paid invoice (the "settlement rounding" rule). Stored
            // (not recomputed on read) so that rule is exact and auditable:
            // the running "remaining base AR" sum for the NEXT payment is a
            // plain SUM(base_ar_relief) over this invoice's posted payments,
            // never a re-derivation from amount x rate that could drift from
            // what was actually posted to the ledger.
            //
            // Nullable: existing Task 4 domestic-payment rows predate this
            // column. Every payment recorded from here on (domestic or FX)
            // always populates it — for a domestic payment it's simply equal
            // to base_amount/amount (fx_rate 1 collapses the FX formula to
            // the Task 4 identity, no special case needed).
            $t->unsignedBigInteger('base_ar_relief')->nullable()->after('base_amount');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_payments', function (Blueprint $t) {
            $t->dropColumn('base_ar_relief');
        });
    }
};

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
            // NOT NULL (no default): SparkBooks has zero production data (the
            // product hasn't launched), and EVERY invoice_payments row in
            // every real or test environment is created by
            // InvoicePaymentService::recordPayment (the sole creation path in
            // the app) or the factory — both of which ALWAYS populate
            // base_ar_relief. So there is no "legacy row that predates this
            // column" to accommodate; a nullable column would only invite the
            // exact bug it's meant to prevent. The settlement-rounding math
            // sums prior payments' base_ar_relief via SQL SUM(), which
            // silently treats a NULL as 0 — so a single nullable prior row
            // could understate the remaining base AR and leave a phantom
            // paisa on a fully paid invoice. NOT NULL makes that class of bug
            // structurally impossible (the DB rejects any row missing it),
            // rather than deferring it to a backfill-someday note.
            $t->unsignedBigInteger('base_ar_relief')->after('base_amount');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_payments', function (Blueprint $t) {
            $t->dropColumn('base_ar_relief');
        });
    }
};

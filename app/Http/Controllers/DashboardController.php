<?php

namespace App\Http\Controllers;

use App\Enums\ContactType;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Services\AccountBalances;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard — Phase 2 gets a light real-data pass (cash position + a couple
 * of counts) so the screen isn't a dead end while the CRUD modules land.
 * Real widgets (P&L, agings, cash flow, etc.) are Phase 5; this deliberately
 * stays minimal — see docs/PROJECT_MAP.md §2.
 */
class DashboardController extends Controller
{
    public function show(AccountBalances $balances): Response
    {
        $balancesByAccount = $balances->asOf();

        // Non-archived bank/cash accounts only — an archived account's
        // balance shouldn't count toward the headline cash figure.
        $bankAccounts = BankAccount::query()->where('is_archived', false)->get();

        $cashPosition = $bankAccounts->sum(
            fn (BankAccount $bankAccount) => $bankAccount->balance($balancesByAccount)
        );

        // ContactType::Both counts toward both sides — it genuinely is both
        // a customer and a vendor, same convention as Contact/Show's
        // outstanding-balance math (app/Http/Controllers/ContactController.php).
        $customerCount = Contact::query()
            ->where('is_archived', false)
            ->whereIn('type', [ContactType::Customer->value, ContactType::Both->value])
            ->count();

        $vendorCount = Contact::query()
            ->where('is_archived', false)
            ->whereIn('type', [ContactType::Vendor->value, ContactType::Both->value])
            ->count();

        return Inertia::render('Dashboard', [
            'cash_position' => $cashPosition,
            'customer_count' => $customerCount,
            'vendor_count' => $vendorCount,
            'bank_account_count' => $bankAccounts->count(),
        ]);
    }
}

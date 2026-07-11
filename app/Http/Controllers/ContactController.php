<?php

namespace App\Http\Controllers;

use App\Enums\AccountSubtype;
use App\Enums\ContactType;
use App\Http\Requests\StoreContactRequest;
use App\Http\Requests\UpdateContactRequest;
use App\Models\Account;
use App\Models\Contact;
use App\Models\JournalLine;
use App\Support\AuditLogger;
use App\Support\CsvExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactController extends Controller
{
    private const AUDITED_FIELDS = ['type', 'name', 'email', 'phone', 'billing_address', 'tax_number', 'notes'];

    public function index(): Response
    {
        $contacts = Contact::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Contact $contact) => $this->contactPayload($contact))
            ->values();

        return Inertia::render('Contacts/Index', [
            'contacts' => $contacts,
        ]);
    }

    /**
     * List-screen CSV export (Phase 6 Task 3, spec §11/§17.8) — the SAME
     * scope Contacts/Index reads (every contact for the current company,
     * archived included — mirrors the index's own full load, which the
     * client itself filters), optionally narrowed by `type`. `type=customer`/
     * `type=vendor` match Contacts/Index's own client-side filter semantics
     * (ContactType::isCustomer/isVendor — "both" counts as either), NOT a
     * bare `type` column equality, so the export always matches what the
     * caller is looking at; `type=both` still filters to exactly the
     * dual-role contacts. Gated `exports.run` in addition to this
     * controller's own `contacts.manage` group (routes/web.php).
     * Audit-logged via the shared CsvExport helper.
     */
    public function exportCsv(Request $request, AuditLogger $audit): StreamedResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', 'string', Rule::enum(ContactType::class)],
        ]);
        $type = $validated['type'] ?? null;

        $contacts = Contact::query()
            ->when($type === ContactType::Customer->value, fn ($query) => $query->whereIn('type', [ContactType::Customer->value, ContactType::Both->value]))
            ->when($type === ContactType::Vendor->value, fn ($query) => $query->whereIn('type', [ContactType::Vendor->value, ContactType::Both->value]))
            ->when($type === ContactType::Both->value, fn ($query) => $query->where('type', ContactType::Both->value))
            ->orderBy('name')
            ->get();

        $rows = $contacts->map(fn (Contact $contact) => [
            $contact->name,
            $contact->type->value,
            $contact->email,
            $contact->phone,
            $contact->tax_number,
            $contact->is_archived ? 'Yes' : 'No',
        ]);

        return CsvExport::stream(
            'contacts.csv',
            ['Name', 'Type', 'Email', 'Phone', 'Tax Number', 'Archived'],
            $rows,
            $audit,
            'contacts.exported',
            ['type' => $type ?? 'all'],
        );
    }

    public function store(StoreContactRequest $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validated();

        $contact = Contact::query()->create([
            'type' => $data['type'],
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'billing_address' => $data['billing_address'] ?? null,
            'tax_number' => $data['tax_number'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $audit->log('contact.created', $contact, after: $contact->only(self::AUDITED_FIELDS));

        return redirect()->route('contacts.index');
    }

    /**
     * $contact is deliberately a plain route parameter, not an implicit
     * Eloquent binding: Contact's BelongsToCompany global scope requires
     * CompanyContext to already be set, but SubstituteBindings (which
     * resolves implicit bindings) runs as part of the framework's "web"
     * middleware group — earlier than this route's own auth/company/can
     * middleware, and earlier than SetCompanyContext. Doing the lookup here,
     * inside the controller action, guarantees the company context is
     * already in place. Mirrors AccountController.
     */
    public function update(UpdateContactRequest $request, string $contact, AuditLogger $audit): RedirectResponse
    {
        $model = Contact::query()->findOrFail($contact);
        $before = $model->only(self::AUDITED_FIELDS);
        $data = $request->validated();

        $model->update([
            'type' => $data['type'],
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'billing_address' => $data['billing_address'] ?? null,
            'tax_number' => $data['tax_number'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $audit->log('contact.updated', $model, before: $before, after: $model->only(self::AUDITED_FIELDS));

        return redirect()->route('contacts.index');
    }

    public function archive(string $contact, AuditLogger $audit): RedirectResponse
    {
        $model = Contact::query()->findOrFail($contact);
        $model->update(['is_archived' => true]);
        $audit->log('contact.archived', $model);

        return redirect()->route('contacts.index');
    }

    public function unarchive(string $contact, AuditLogger $audit): RedirectResponse
    {
        $model = Contact::query()->findOrFail($contact);
        $model->update(['is_archived' => false]);
        $audit->log('contact.unarchived', $model);

        return redirect()->route('contacts.index');
    }

    /**
     * Contact detail. Phase 2 has no invoices/bills yet, so the outstanding
     * balance below is always 0 today — but it's derived the same way every
     * other read-side figure in this app is (straight from journal_lines,
     * never from a document total, per PROJECT_MAP §9), so it becomes correct
     * the moment invoicing (Phase 3) starts posting to AR/AP with this
     * contact_id. receivable = what they owe you (debit-normal); payable =
     * what you owe them (credit-normal). The two are summed into one net
     * figure since a "both" contact can carry both at once.
     */
    public function show(string $contact): Response
    {
        $model = Contact::query()->findOrFail($contact);

        $receivableAccountIds = Account::query()->where('subtype', AccountSubtype::Receivable->value)->pluck('id');
        $payableAccountIds = Account::query()->where('subtype', AccountSubtype::Payable->value)->pluck('id');

        $receivableBalance = (int) JournalLine::query()
            ->where('contact_id', $model->id)
            ->whereIn('account_id', $receivableAccountIds)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) AS n')
            ->value('n');

        $payableBalance = (int) JournalLine::query()
            ->where('contact_id', $model->id)
            ->whereIn('account_id', $payableAccountIds)
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) AS n')
            ->value('n');

        return Inertia::render('Contacts/Show', [
            'contact' => $this->contactPayload($model),
            'outstanding_balance' => $receivableBalance + $payableBalance,
        ]);
    }

    /** @return array<string, mixed> */
    private function contactPayload(Contact $contact): array
    {
        return [
            'id' => $contact->id,
            'type' => $contact->type->value,
            'name' => $contact->name,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'billing_address' => $contact->billing_address,
            'tax_number' => $contact->tax_number,
            'notes' => $contact->notes,
            'is_archived' => $contact->is_archived,
        ];
    }
}

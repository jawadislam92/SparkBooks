# SparkBooks Phase 1 — Ledger Core + App Shell Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** The double-entry heart of SparkBooks — accounts + seeded chart of accounts, `LedgerService` with every iron rule, manual journal entries, period locks, Trial Balance, integrity checker — plus the real SparkBooks design system and app shell (replacing Breeze's default look).

**Architecture:** Domain logic in services behind one ledger write-gate (`LedgerService::post`), per spec §4. Reports compute from `journal_lines` only. UI = design-token-driven React components on Inertia; screens are "sheets" on ledger-tinted paper with an ink sidebar.

**Tech Stack:** as Phase 0 (Laravel 13, Inertia/React/TS, MySQL 8.4, Tailwind v3) + @fontsource packages + vitest for TS money-format tests.

**Plan style note:** domain/correctness tasks carry complete code (transcribe verbatim). UI tasks carry exact token files, component contracts, and structural skeletons — implementers compose final JSX within those tokens; no new colors/fonts/spacing values may be invented.

## Global Constraints

- **Shell env (every Bash session):** `export PATH="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64:/c/laragon/bin/composer:/c/laragon/bin/nodejs/node-v22:/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin:$PATH"`; work in `/c/laragon/www/SparkBooks`, branch `phase-1-ledger-core`.
- Spec authority: `docs/superpowers/specs/2026-07-08-accounting-software-v1-design.md` §4 (iron rules), §5 (CoA seed), §6 (posting notes), §7.2/7.10/7.11 (screens), §9 (integrity checker), §12 (required tests), §17.2–17.3 (idempotency backstop + DB constraints).
- Money = BIGINT minor units everywhere; **no float on money paths** (CI greps for float casts on money columns — add that grep in Task 12).
- Every tenant model: `BelongsToCompany`, **never** `company_id` in `$fillable`/request payloads.
- All ledger writes go through `LedgerService::post()` — no other code path may insert into journal tables.
- Posted entries immutable (model guards throw); corrections = reversals.
- Permission gates from `config/permissions.php` guard every new route (`accounts.manage`, `ledger.post_manual`, `reports.view`, `exports.run`, `periods.lock`).
- Audit-log every mutating action (`AuditLogger`), including CSV exports (spec §17.8).
- Commits after every task with trailer `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.
- Tests on real MySQL; TDD strictly for domain tasks.
- **Design tokens are law** (Task 1). No new hex values, font families, shadows, or radii anywhere in later tasks. No `<?php // path` header comments.

### Design system (binding visual spec — from the frontend-design pass + Jawad's brief)

- **Palette** (only these + white): `ink` #16323B (petrol — text/sidebar; ramp 800 #1D3E49, 700 #24505E, 600 #2E6273, 100 #D7E2E1 borders-on-ink), `paper` #F7FAF7 (app bg), `paper-raised` #FFFFFF (sheets/cards), `rule` #E4EEE6 (table rules/dividers), `spark` #0FA678 (primary; 600 #0C8A64 hover, 50 #EAF7F1 tint), `lagoon` #0C8FA6 (gradient end only), `amber` #E8A13D (attention; 50 #FBF3E4), `clay` #D65A4A (danger/debit alerts; 50 #F9ECEA). **The gradient** `linear-gradient(90deg, #0FA678, #0C8FA6)` is reserved for: primary buttons, the balance beam, active-nav indicator, and focus glow — nowhere else.
- **Type:** display "Bricolage Grotesque" (600/700 — page titles, big money figures); body/UI "Public Sans" (400/500/600); data/money "Spline Sans Mono" (400/500, always `tabular-nums`, always right-aligned in tables). Via @fontsource, self-hosted.
- **Surfaces:** screens = white "sheets" (`rounded-[0.875rem]`, shadow `0 1px 2px rgba(22,50,59,.06), 0 4px 16px rgba(22,50,59,.06)`) on paper background; tables inside sheets use `rule`-colored 1px row rules (ledger-paper feel), row hover = `spark-50` tint at 40%.
- **Motion:** 120ms ease-out standard transitions; the beam settles with `cubic-bezier(.34,1.56,.64,1)` 240ms; number roll-ups ≤400ms; `prefers-reduced-motion` disables all of it. Nothing else animates.
- **Signature element — BalanceBeam:** a 3px gradient bar that tilts up to ±3° toward the heavier side of a debit/credit pair and settles level with a brief glow when balanced and non-zero. Lives in the manual-JE editor (primary home), and as the Trial Balance footer equality indicator. Never used decoratively.
- **Copy register:** user-language, sentence case, active verbs ("Post entry", "Archive account"). Users never see the words debit/credit **outside** the accountant-facing screens (manual JE editor, Trial Balance, account ledger — there they ARE the right words).
- **Instant feel:** Inertia link prefetch on hover for sidebar links; buttons show pressed state ≤80ms; no full-page spinners — Inertia progress bar (2px, gradient) only.

---

### Task 1: Design tokens, fonts, primitives + money formatting (TS)

**Files:**
- Modify: `tailwind.config.js` (tokens), `resources/css/app.css` (font imports via app.tsx instead), `resources/js/app.tsx` (fontsource imports, progress bar color), `package.json`
- Create: `resources/js/lib/money.ts`, `resources/js/lib/money.test.ts`, `vitest.config.ts`, `resources/js/Components/ui/Button.tsx`, `Sheet.tsx`, `Field.tsx`, `MoneyText.tsx`, `resources/js/Components/ledger/BalanceBeam.tsx`

**Interfaces (produced, later tasks consume):**
- `formatMoney(minor: number, currency: string): string` — Intl-based, e.g. `formatMoney(150050, 'PKR') === 'Rs 1,500.50'` (exact string from `Intl.NumberFormat('en-PK', {style:'currency', currency})` — pin in test via the actual runtime output, not a guess); `parseMoneyInput(text: string): number | null` — user-typed decimal → minor units via string math (**never** `parseFloat` then multiply; split on '.', pad/truncate to 2 digits, integer arithmetic; reject >2 decimals and non-numeric; empty/'.' → null).
- `<Button variant="primary|quiet|danger" size="md|sm">` — primary = spark gradient, white text; quiet = ink-700 text, paper hover; danger = clay.
- `<Sheet title? actions?>` — white rounded card per Surfaces spec.
- `<Field label error?>` wrapper; `<MoneyText minor currency size?>` — Spline Sans Mono, tabular-nums, right-aligned.
- `<BalanceBeam debitTotal creditTotal currency>` — per signature spec; `aria-live="polite"` announcing "Balanced" / "Out by <amount>, <debits|credits> heavier". Exact behavior: `tilt = clamp((credit-debit)/max(debit,credit,1), -1, 1) * 3°`; balanced & >0 → level + one 240ms glow pulse (box-shadow spark at 35%); zero/zero → level, 30% opacity.

**Steps:**
- [ ] `npm i -D vitest @fontsource/bricolage-grotesque @fontsource/public-sans @fontsource/spline-sans-mono` (fontsource are deps not dev — put in dependencies). Add `"test:js": "vitest run"` script. `vitest.config.ts`: environment 'node', include `resources/js/**/*.test.ts`.
- [ ] Write failing `money.test.ts`: formatMoney round-trips known values (0, 1, 150050, 999999999999) for PKR + USD; parseMoneyInput('1,500.50')→150050, ('1500')→150000, ('0.5')→50, ('1.999')→null, ('abc')→null, ('')→null; property: `parseMoneyInput(formatMoney(n,'PKR').replace(/[^\d.]/g,''))===n` for the sample set. Run → fail.
- [ ] Implement `money.ts` (string-math parser; Intl formatter with a small per-currency memo). Run → pass.
- [ ] Tailwind tokens: extend colors/fontFamily/boxShadow/borderRadius exactly per the Design system block above (`display:['"Bricolage Grotesque"','sans-serif']`, `sans:['"Public Sans"','sans-serif']`, `mono:['"Spline Sans Mono"','monospace']`).
- [ ] app.tsx: import the three fontsource weights used (400/500/600/700 as listed); set Inertia progress `color: '#0FA678'`.
- [ ] Build primitives + BalanceBeam per contracts. `npm run build` green; `npm run test:js` green.
- [ ] Commit: `feat(ui): SparkBooks design tokens, fonts, primitives, BalanceBeam`.

---

### Task 2: App shell — ink sidebar, header, company switcher UI, restyled auth/onboarding/dashboard

**Files:**
- Create: `resources/js/Layouts/AppShell.tsx`, `resources/js/Components/shell/SidebarLink.tsx`, `CompanyMenu.tsx`, `UserMenu.tsx`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php` (share via **closures**: `auth.user`, `companies` [id,name,role], `currentCompany` [id,name,base_currency]), `resources/js/Pages/Dashboard.tsx`, `Onboarding/CreateCompany.tsx`, auth pages (Login/Register/etc.) minimal restyle to tokens, `app/Http/Controllers/CompanySwitchController.php` (audit-log the switch)
- Test: extend `tests/Feature/CompanyContextTest.php` (switch is audit-logged); new `tests/Feature/InertiaSharedPropsTest.php`

**Interfaces:**
- `AppShell` (default layout): left sidebar 248px, `bg-ink` full-height; logo wordmark "SparkBooks" (Bricolage 700, the "k" underlined by a 10px-wide gradient beam accent); nav groups (Phase 1): **Overview** → Dashboard; **Books** → Chart of accounts, Journal entries; **Reports** → Trial balance. Active link: `bg-ink-800` + 3px gradient left indicator; links prefetch on hover. Header: 56px, paper, page title slot, right side CompanyMenu (current company name + chevron; menu lists memberships, posts to `company.switch`) and UserMenu (profile, log out).
- Shared props MUST be closures (evaluated after `SetCompanyContext` runs) — this resolves the Phase-0 final-review ordering note; add one test asserting shared `currentCompany.id` equals the user's current company on a dashboard request.
- Switch audit: `company.switched` action with before/after company ids (log it in the TARGET company's context via `runFor`).

**Steps:** failing tests (shared props; audit row on switch) → implement middleware shares + controller audit → tests pass → build shell + restyle pages (visual work: match the Design system block; keep auth pages minimal — centered sheet on paper, logo above) → `npm run build`, full `composer test` → browser-check shell renders (screenshot) → commit.

---

### Task 3: Accounts — migration, model, types, CoA seeder

**Files:**
- Create: migration `create_accounts_table`, `app/Enums/AccountType.php`, `app/Enums/AccountSubtype.php`, `app/Models/Account.php`, `database/factories/AccountFactory.php`, `app/Services/ChartOfAccountsSeeder.php`
- Modify: `app/Services/CompanySetupService.php` (`seedDefaults` calls the seeder)
- Test: `tests/Feature/AccountsTest.php`

**Complete code:**

```php
// migration
Schema::create('accounts', function (Blueprint $t) {
    $t->id();
    $t->foreignId('company_id')->constrained()->restrictOnDelete();
    $t->string('code', 10);
    $t->string('name', 120);
    $t->string('type', 12);        // AccountType enum values
    $t->string('subtype', 24);     // AccountSubtype enum values
    $t->boolean('is_system')->default(false);
    $t->boolean('is_archived')->default(false);
    $t->string('description', 500)->nullable();
    $t->timestamps();
    $t->unique(['company_id', 'code']);
});
```

```php
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    /** Debit-normal account types increase with debits. */
    public function isDebitNormal(): bool
    {
        return in_array($this, [self::Asset, self::Expense], true);
    }
}
```

```php
enum AccountSubtype: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case Receivable = 'receivable';
    case FixedAsset = 'fixed_asset';
    case CurrentAsset = 'current_asset';
    case Payable = 'payable';
    case Tax = 'tax';
    case CurrentLiability = 'current_liability';
    case Equity = 'equity';
    case OperatingIncome = 'operating_income';
    case OtherIncome = 'other_income';
    case OperatingExpense = 'operating_expense';

    public function type(): AccountType { /* map each case to its AccountType — Cash|Bank|Receivable|FixedAsset|CurrentAsset => Asset; Payable|Tax|CurrentLiability => Liability; Equity => Equity; OperatingIncome|OtherIncome => Income; OperatingExpense => Expense */ }
}
```

`Account`: `BelongsToCompany`, `$fillable = ['code','name','type','subtype','is_system','is_archived','description']` (NOT company_id), casts to enums + booleans. `isControlAccount(): bool` → subtype is Receivable or Payable. Model guards: `updating` throws `LogicException` if `is_system` and (`code` or `type` or `subtype` dirty); `deleting` always throws (`'Accounts are archived, never deleted.'`).

`ChartOfAccountsSeeder::seed(Company $company): void` — inserts the **exact** spec §5 table (23 accounts: 1000 Cash on Hand cash/system, 1010 Bank Account bank/system, 1100 Accounts Receivable receivable/system, 1500 Office Equipment fixed_asset, 2000 Accounts Payable payable/system, 2100 Tax Payable tax/system, 3000 Owner's Equity equity, 3100 Opening Balance Equity equity/system, 3200 Retained Earnings equity/system, 3900 Owner Drawings equity, 4000 Service Income operating_income, 4900 Other Income other_income, 5000–5990 the twelve expenses operating_expense) — copy names verbatim from spec §5. Runs inside the caller's context/transaction.

**Tests (write first):** seeding a company creates exactly 23 accounts with those codes; system flags match §5; `(company, code)` unique at DB level (QueryException on dup); system account can't change code/type but CAN rename; delete always throws; cross-company isolation (account codes reusable across companies). Then wire `CompanySetupService::seedDefaults` + test onboarding now seeds (update OnboardingTest count assertions if any). Full suite green → commit.

---

### Task 4: Journal tables + EntryDraft + LedgerService (THE gate)

**Files:**
- Create: migrations `create_journal_entries_table`, `create_journal_lines_table`; `app/Domain/Ledger/EntryDraft.php`, `app/Domain/Ledger/LineDraft.php`, `app/Enums/EntryRole.php`, `app/Models/JournalEntry.php`, `app/Models/JournalLine.php`, `app/Services/LedgerService.php`, `app/Exceptions/Ledger/{UnbalancedEntry,EmptyEntry,LockedPeriod,ControlAccountViolation,ImmutableLedger}.php` (all extend a base `LedgerException extends DomainException`)
- Test: `tests/Feature/Ledger/LedgerServiceTest.php`

**Complete code (verbatim):**

```php
// create_journal_entries_table
Schema::create('journal_entries', function (Blueprint $t) {
    $t->id();
    $t->foreignId('company_id')->constrained()->restrictOnDelete();
    $t->date('entry_date');
    $t->string('memo', 500);
    $t->string('source_type', 40);
    $t->unsignedBigInteger('source_id');
    $t->string('entry_role', 10)->default('primary');
    $t->foreignId('reverses_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
    $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    $t->timestamp('posted_at')->useCurrent();
    // §17.2 backstop: one primary + one reversal max per source
    $t->unique(['company_id', 'source_type', 'source_id', 'entry_role'], 'journal_entries_source_unique');
    $t->index(['company_id', 'entry_date']);
});
```

```php
// create_journal_lines_table
Schema::create('journal_lines', function (Blueprint $t) {
    $t->id();
    $t->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
    $t->foreignId('company_id')->constrained()->restrictOnDelete();
    $t->foreignId('account_id')->constrained()->restrictOnDelete();
    $t->unsignedBigInteger('debit')->default(0);
    $t->unsignedBigInteger('credit')->default(0);
    $t->string('memo', 500)->nullable();
    $t->foreignId('contact_id')->nullable(); // FK added in Phase 2 when contacts exist
    $t->index(['company_id', 'account_id']);
});
// §17.3 CHECK: exactly one side positive
DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT chk_one_sided CHECK ((debit = 0 AND credit > 0) OR (debit > 0 AND credit = 0))');
```

```php
enum EntryRole: string { case Primary = 'primary'; case Reversal = 'reversal'; }
```

```php
// LineDraft — readonly DTO
final readonly class LineDraft
{
    public function __construct(
        public int $accountId,
        public int $debit,          // minor units, >= 0
        public int $credit,         // minor units, >= 0
        public ?string $memo = null,
        public ?int $contactId = null,
    ) {}
}
```

```php
// EntryDraft — readonly DTO
final readonly class EntryDraft
{
    /** @param list<LineDraft> $lines */
    public function __construct(
        public CarbonImmutable $entryDate,
        public string $memo,
        public string $sourceType,   // 'manual_journal' in Phase 1; invoice/payment/... later
        public int $sourceId,
        public array $lines,
        public EntryRole $role = EntryRole::Primary,
        public ?int $reversesEntryId = null,
    ) {}
}
```

```php
class LedgerService
{
    public function __construct(private CompanyContext $context) {}

    /**
     * THE one gate into the ledger. Callers must already hold a DB transaction
     * (documents + ledger commit together) — this method enforces that.
     */
    public function post(EntryDraft $draft): JournalEntry
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('LedgerService::post must run inside the caller\'s transaction.');
        }
        $this->validate($draft);

        $entry = JournalEntry::query()->create([
            'entry_date' => $draft->entryDate->toDateString(),
            'memo' => $draft->memo,
            'source_type' => $draft->sourceType,
            'source_id' => $draft->sourceId,
            'entry_role' => $draft->role->value,
            'reverses_entry_id' => $draft->reversesEntryId,
            'created_by' => auth()->id(),
        ]);
        foreach ($draft->lines as $line) {
            $entry->lines()->create([
                'company_id' => $entry->company_id,
                'account_id' => $line->accountId,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'memo' => $line->memo,
                'contact_id' => $line->contactId,
            ]);
        }
        return $entry->load('lines');
    }

    /** Post a mirror-image reversal of an existing entry (same source, role=reversal). */
    public function reverse(JournalEntry $entry, CarbonImmutable $date, string $memo): JournalEntry
    {
        $lines = $entry->lines->map(fn (JournalLine $l) => new LineDraft(
            accountId: $l->account_id,
            debit: $l->credit,
            credit: $l->debit,
            memo: $l->memo,
            contactId: $l->contact_id,
        ))->all();

        return $this->post(new EntryDraft(
            entryDate: $date, memo: $memo,
            sourceType: $entry->source_type, sourceId: $entry->source_id,
            lines: $lines, role: EntryRole::Reversal, reversesEntryId: $entry->id,
        ));
    }

    private function validate(EntryDraft $draft): void
    {
        if ($draft->lines === []) {
            throw new EmptyEntry();
        }
        $debits = $credits = 0;
        foreach ($draft->lines as $line) {
            if ($line->debit < 0 || $line->credit < 0
                || ($line->debit === 0) === ($line->credit === 0)) { // exactly one side > 0
                throw new UnbalancedEntry('Each line must have exactly one positive side.');
            }
            $debits += $line->debit;
            $credits += $line->credit;
            $account = Account::query()->findOrFail($line->accountId); // tenant-scoped => foreign account 404s
            if ($account->is_archived) {
                throw new ControlAccountViolation("Account {$account->code} is archived.");
            }
            if ($account->isControlAccount()) {
                if ($line->contactId === null) {
                    throw new ControlAccountViolation("Lines on {$account->code} require a contact.");
                }
                if ($draft->sourceType === 'manual_journal') {
                    throw new ControlAccountViolation('Manual journal entries may not touch AR/AP control accounts — void and reissue the source document instead.');
                }
            }
        }
        if ($debits !== $credits) {
            throw new UnbalancedEntry("Debits ({$debits}) must equal credits ({$credits}).");
        }
        $locked = $this->context->get()->books_locked_through;
        if ($locked && $draft->entryDate->lte(CarbonImmutable::parse($locked))) {
            throw new LockedPeriod("The books are locked through {$locked->format('Y-m-d')}.");
        }
    }
}
```

`JournalEntry`/`JournalLine` models: `BelongsToCompany`, `$guarded = ['id']` is NOT used — explicit `$fillable` WITHOUT company_id; **immutability guards** on both: `updating`/`deleting` → `throw new ImmutableLedger('Posted ledger entries are immutable — post a reversal instead.')`. `JournalEntry::lines()` hasMany; casts entry_date date, entry_role enum.

**Tests (write first — these are spec §12.1–12.3 for the core; every one runs inside `DB::transaction`):** balanced 2-line and 3-line entries post with exact line values; unbalanced → UnbalancedEntry, nothing persisted; both-sides-zero / both-sides-positive line → UnbalancedEntry; empty lines → EmptyEntry; posting outside a transaction → LogicException; archived account → rejected; manual_journal touching 1100/2000 → ControlAccountViolation; control-account line without contact (source_type 'invoice') → ControlAccountViolation; with contact → posts; locked period (set books_locked_through) → LockedPeriod, day after → posts; update/delete posted entry or line → ImmutableLedger; DB CHECK rejects a raw both-sides insert (DB::table insert → QueryException); duplicate (source_type, source_id, primary) raw insert → QueryException (unique backstop); `reverse()` produces mirror lines, links reverses_entry_id, and a second reverse of the same entry → QueryException from the unique index; **global invariant hook**: an `afterEach`-style assertion (base TestCase trait `AssertsLedgerBalanced`, added here, used by all ledger tests) verifying SUM(debit)=SUM(credit) across the whole test DB. Foreign-company account id in a draft → ModelNotFoundException (tenant scope). Full suite → commit.

---

### Task 5: Idempotency baseline-guard fix + per-request role cache (Phase-0 backlog, pre-money deadline)

**Files:**
- Modify: `app/Support/IdempotencyService.php`, `app/Models/User.php`, `app/Providers/AppServiceProvider.php`
- Test: extend `tests/Feature/IdempotencyTest.php`, `tests/Feature/PermissionMatrixTest.php`

**Changes:** (1) IdempotencyService: replace constructor-captured baseline with capture-on-first-`execute()`-per-instance? No — the defect is late resolution inside a txn. Correct fix: capture baseline in the constructor **and** register the service in `SetCompanyContext` middleware (eager `app(IdempotencyService::class)` right after context set — request baseline is then pre-transaction by construction); keep the constructor comment documenting why. Test: resolving the service first inside `DB::transaction` then calling execute outside still works via a fresh scoped instance… (write the test that previously would have defeated the guard: within a request simulation, eager resolution means `execute()` inside an ambient transaction still throws). (2) Role cache: `User::roleIn()` memoizes per (user instance, company id) in an array property; `currentRole()` helper. Permission test additions: deny-when-context-unset gate test; catalog completeness = union of MATRIX rows (upgrading the weak `assertNotEmpty`). All suite green → commit.

---

### Task 6: Manual journal entries — document, service, endpoints

**Files:**
- Create: migration `create_manual_journals_table` (id, company_id FK restrict, entry_date date, memo string(500), status string(10) default 'posted', created_by nullable FK, timestamps), `app/Models/ManualJournal.php`, `app/Services/ManualJournalService.php`, `app/Http/Controllers/ManualJournalController.php`, `app/Http/Requests/StoreManualJournalRequest.php`, `app/Policies` usage via gates
- Modify: `routes/web.php`
- Test: `tests/Feature/ManualJournalTest.php`

**Service (complete):**

```php
class ManualJournalService
{
    public function __construct(
        private LedgerService $ledger,
        private IdempotencyService $idempotency,
        private AuditLogger $audit,
    ) {}

    /** @param array{entry_date:string, memo:string, lines:list<array{account_id:int, debit:int, credit:int, memo?:?string}>} $data */
    public function post(string $idempotencyKey, array $data): ManualJournal
    {
        $result = $this->idempotency->execute($idempotencyKey, 'manual_journal.post', function () use ($data) {
            return DB::transaction(function () use ($data) {
                $doc = ManualJournal::query()->create([
                    'entry_date' => $data['entry_date'],
                    'memo' => $data['memo'],
                    'created_by' => auth()->id(),
                ]);
                $entry = $this->ledger->post(new EntryDraft(
                    entryDate: CarbonImmutable::parse($data['entry_date']),
                    memo: $data['memo'],
                    sourceType: 'manual_journal',
                    sourceId: $doc->id,
                    lines: array_map(fn ($l) => new LineDraft(
                        accountId: $l['account_id'], debit: $l['debit'],
                        credit: $l['credit'], memo: $l['memo'] ?? null,
                    ), $data['lines']),
                ));
                $this->audit->log('manual_journal.posted', $doc, after: ['entry_id' => $entry->id, 'memo' => $doc->memo]);
                return ['id' => $doc->id];
            });
        });
        return ManualJournal::query()->findOrFail($result['id']);
    }

    public function reverse(ManualJournal $journal, string $date, string $memo): JournalEntry
    {
        return DB::transaction(function () use ($journal, $date, $memo) {
            $entry = JournalEntry::query()
                ->where('source_type', 'manual_journal')->where('source_id', $journal->id)
                ->where('entry_role', EntryRole::Primary->value)->firstOrFail();
            $reversal = $this->ledger->reverse($entry, CarbonImmutable::parse($date), $memo);
            $journal->forceFill(['status' => 'reversed'])->save();
            $this->audit->log('manual_journal.reversed', $journal, after: ['reversal_entry_id' => $reversal->id]);
            return $reversal;
        });
    }
}
```

**Request validation:** entry_date required date; memo required max 500; lines array min 2; per-line account_id integer exists-in-tenant (validated in service via scope anyway), debit/credit integer min 0; client sends **minor units** (UI converts via parseMoneyInput); idempotency key = UUID header `X-Idempotency-Key` required. Routes (gate `ledger.post_manual`): GET /journal-entries (index: paginated ManualJournals + their entries), GET /journal-entries/new, POST /journal-entries, POST /journal-entries/{manualJournal}/reverse. Controller returns Inertia pages; reversal requires confirm.

**Tests first:** posting via HTTP happy path (assert document + exact ledger lines + audit row); staff role → 403; unbalanced → 422 with error; double-submit same idempotency key → one document; reverse → mirror entry + status reversed; reverse twice → 422 (unique index surfaces as friendly error); locked period → 422 with the lock message. Full suite → commit.

---

### Task 7: Manual JE editor UI (BalanceBeam's home) + journal list

**Files:**
- Create: `resources/js/Pages/Journal/Index.tsx`, `resources/js/Pages/Journal/New.tsx`, `resources/js/Components/ledger/LineEditor.tsx`
- Test: browser verification (this is UI; server behavior already tested)

**Spec:** New.tsx — sheet titled "New journal entry"; date + memo fields; line grid (account select searchable, memo, debit input, credit input — money inputs right-aligned mono, entering one side clears the other); add-line button; footer row: totals per side as `MoneyText` + **BalanceBeam** between them; Post button (primary, disabled until balanced && ≥2 lines, sends UUID key header, disables on submit). Debit/credit language is correct here (accountant screen). Errors from 422 render under fields/top. Index.tsx: ledger-ruled table (date, memo, amount, status chip [posted=spark-50/ink, reversed=amber-50], created by), row → detail expand showing lines; Reverse action with confirm dialog. Account select options provided by controller (id, code, name, type — exclude archived, exclude control accounts since manual JEs can't use them anyway: filter server-side).

**Steps:** build → `npm run build` → drive in browser (create balanced entry watching beam settle level with glow; try unbalanced → beam tilts toward heavier side, Post disabled; post; reverse) → screenshot both states → fix anything → commit.

---

### Task 8: Chart of Accounts screen + account ledger drill-down

**Files:**
- Create: `app/Http/Controllers/AccountController.php`, `app/Http/Requests/{StoreAccountRequest,UpdateAccountRequest}.php`, `app/Services/AccountBalances.php`, `resources/js/Pages/Accounts/Index.tsx`, `resources/js/Pages/Accounts/Show.tsx`
- Modify: `routes/web.php`
- Test: `tests/Feature/ChartOfAccountsTest.php`

**AccountBalances (complete):**

```php
class AccountBalances
{
    /** @return array<int, int> account_id => signed balance in minor units (debit-normal positive when debits exceed) */
    public function asOf(?CarbonImmutable $date = null): array
    {
        $q = JournalLine::query()
            ->selectRaw('account_id, SUM(debit) - SUM(credit) AS raw_balance')
            ->groupBy('account_id');
        if ($date) {
            $q->whereHas('entry', fn ($e) => $e->where('entry_date', '<=', $date->toDateString()));
        }
        // Presentation sign: debit-normal accounts show raw; credit-normal show negated.
        $balances = [];
        $accounts = Account::query()->get()->keyBy('id');
        foreach ($q->pluck('raw_balance', 'account_id') as $id => $raw) {
            $type = $accounts[$id]->type;
            $balances[$id] = $type->isDebitNormal() ? (int) $raw : -(int) $raw;
        }
        return $balances;
    }
}
```

**Routes** (gate `accounts.manage` for mutations; `reports.view` NOT required for CoA index — accountant+admin+owner all hold accounts.manage; Staff gets 403): GET /accounts, POST /accounts, PATCH /accounts/{account}, POST /accounts/{account}/archive + /unarchive, GET /accounts/{account} (ledger drill-down: paginated lines newest-first with running balance computed server-side from a base offset). Create/update validation: code required unique-per-company regex `/^\d{4}$/`, name required 120, subtype valid enum (type derives from subtype — never accepted separately), description optional; system accounts: name/description editable only (request rejects other fields).

**UI:** Index = sheets per type group (Assets, Liabilities, Equity, Income, Expenses) with code (mono), name, subtype label (user words: "Bank", "Money owed to you"…), balance `MoneyText`, system padlock icon (tooltip "Built-in account"), archived toggle filter, New account modal. Show = account header (code, name, balance) + ledger-ruled table (date, memo/source, debit, credit, running balance). Copy per design block — outside this screen balances never say debit/credit; here they do.

**Tests first:** index groups + balances correct after posting entries (post via LedgerService inside txn in test); create account validates code format/uniqueness; system account PATCH restricted; archive hides from JE account options endpoint; drill-down running balance math; staff 403 on mutations; cross-company 404. Browser: create → archive → drill-down. Commit.

---

### Task 9: Period locks — settings endpoint + UI

**Files:**
- Create: `app/Http/Controllers/PeriodLockController.php`, section in a new `resources/js/Pages/Settings/Books.tsx`
- Modify: `routes/web.php`
- Test: `tests/Feature/PeriodLockTest.php`

**Behavior:** GET /settings/books (gate `periods.lock` to change; visible read-only with `reports.view`) shows current `books_locked_through`; POST sets/moves it (date nullable to unlock, cannot be in the future beyond today), audit-logged `periods.lock_moved` with before/after. Moving the lock BACKWARD is allowed (Owner/Accountant judgement per spec §4 note) but audit row is mandatory. LedgerService enforcement already exists (Task 4). UI: sheet with date picker + explanation copy ("Entries dated on or before this date can't be added or changed."). Tests: gate matrix (staff/admin 403, accountant/owner ok); posting into locked date via ManualJournalService → 422 (already covered but assert through HTTP here); audit row with before/after; unlock (null) works. Commit.

---

### Task 10: Trial Balance report + CSV export

**Files:**
- Create: `app/Services/Reports/TrialBalanceReport.php`, `app/Http/Controllers/Reports/TrialBalanceController.php`, `resources/js/Pages/Reports/TrialBalance.tsx`
- Modify: `routes/web.php`
- Test: `tests/Feature/Reports/TrialBalanceTest.php`

**Report service (complete):**

```php
class TrialBalanceReport
{
    /** @return array{rows: list<array{account_id:int, code:string, name:string, type:string, debit:int, credit:int}>, total_debit:int, total_credit:int, as_of:string} */
    public function build(CarbonImmutable $asOf): array
    {
        $sums = JournalLine::query()
            ->selectRaw('account_id, SUM(debit) AS d, SUM(credit) AS c')
            ->whereHas('entry', fn ($e) => $e->where('entry_date', '<=', $asOf->toDateString()))
            ->groupBy('account_id')->get()->keyBy('account_id');

        $rows = [];
        $totalD = $totalC = 0;
        foreach (Account::query()->orderBy('code')->get() as $account) {
            $s = $sums[$account->id] ?? null;
            $net = $s ? ((int) $s->d - (int) $s->c) : 0;
            if ($net === 0 && ! $s) continue;             // never-used accounts skipped
            $debit = max($net, 0);
            $credit = max(-$net, 0);
            if ($debit === 0 && $credit === 0) continue;   // fully offset accounts skipped
            $rows[] = ['account_id' => $account->id, 'code' => $account->code, 'name' => $account->name,
                       'type' => $account->type->value, 'debit' => $debit, 'credit' => $credit];
            $totalD += $debit;
            $totalC += $credit;
        }
        return ['rows' => $rows, 'total_debit' => $totalD, 'total_credit' => $totalC, 'as_of' => $asOf->toDateString()];
    }
}
```

**Routes** (gate `reports.view`; export additionally `exports.run`): GET /reports/trial-balance?as_of=YYYY-MM-DD (default today), GET /reports/trial-balance/export.csv → streamed CSV (code,name,debit,credit as decimal strings via integer math `intdiv($m,100).'.'.str_pad($m%100,2,'0',STR_PAD_LEFT)` — never float), audit-logged `report.exported` {report, as_of}. UI: date picker, ledger-ruled table (code mono, name, debit, credit `MoneyText`), footer totals + BalanceBeam as equality indicator + "Balanced" caption, Export CSV quiet button. **Test first:** totals equal after a set of posted entries (§12.9 TB invariant); as_of respects entry_date; report derives only from journal_lines (post entries, tamper a document-free path is N/A — assert numbers match hand-computed); CSV numbers exact (string compare, incl. an odd-paisa value); export audit-logged; gate checks. Browser-verify + screenshot. Commit.

---

### Task 11: Integrity checker — command, schedule, health screen

**Files:**
- Create: `app/Console/Commands/VerifyLedgerIntegrity.php`, `app/Services/IntegrityChecker.php`, `app/Http/Controllers/HealthController.php`, `resources/js/Pages/Settings/Health.tsx`, migration `create_integrity_runs_table` (id, company_id FK, ran_at, findings json nullable, clean boolean)
- Modify: `routes/console.php` (daily schedule), `routes/web.php`
- Test: `tests/Feature/IntegrityCheckerTest.php`

**Checker (complete core):**

```php
class IntegrityChecker
{
    /** @return list<array{check:string, detail:string}> findings (empty = clean) */
    public function checkCompany(Company $company): array
    {
        return app(CompanyContext::class)->runFor($company, function () {
            return DB::transaction(function () {                    // one consistent snapshot per company (§9)
                $findings = [];
                // (a) every entry balances
                $unbalanced = JournalLine::query()
                    ->selectRaw('journal_entry_id, SUM(debit) AS d, SUM(credit) AS c')
                    ->groupBy('journal_entry_id')->havingRaw('SUM(debit) <> SUM(credit)')->get();
                foreach ($unbalanced as $row) {
                    $findings[] = ['check' => 'entry_balance', 'detail' => "Entry {$row->journal_entry_id} off by ".($row->d - $row->c)];
                }
                // (b) trial balance sums to zero
                $net = (int) JournalLine::query()->selectRaw('COALESCE(SUM(debit) - SUM(credit),0) AS n')->value('n');
                if ($net !== 0) {
                    $findings[] = ['check' => 'trial_balance', 'detail' => "Ledger net {$net} (should be 0)"];
                }
                // (f) control-account lines carry contact_id
                $missing = JournalLine::query()
                    ->whereIn('account_id', Account::query()->whereIn('subtype', ['receivable','payable'])->pluck('id'))
                    ->whereNull('contact_id')->count();
                if ($missing > 0) {
                    $findings[] = ['check' => 'control_contact', 'detail' => "{$missing} control-account line(s) missing contact"];
                }
                // (e-lite) every manual_journal document has exactly its expected entries
                $orphaned = JournalEntry::query()->where('source_type', 'manual_journal')
                    ->whereNotIn('source_id', ManualJournal::query()->pluck('id'))->count();
                if ($orphaned > 0) {
                    $findings[] = ['check' => 'orphan_entries', 'detail' => "{$orphaned} ledger entr(ies) reference missing manual journals"];
                }
                return $findings;
            });
        });
    }
}
```

**Command:** iterates `Company::query()->withoutGlobalScopes()->get()` (companies aren't tenant-scoped anyway — but be explicit); **exits non-zero + logs error if zero companies exist, or if a company with posted documents yields zero journal entries** (vacuous-check guard, spec §3); per company: run checker, persist an `integrity_runs` row, `Log::error` + (Phase 6: alert channel) on findings; command output lists per-company clean/findings. Schedule: `Schedule::command('ledger:verify')->dailyAt('03:00')` in routes/console.php. Health screen (gate `audit.view`): last run per company-context, findings list, "Run now" button (dispatches synchronously, audit-logged). **Tests first:** clean company → clean run row; seeded corruption detected — use `DB::statement` raw tampering (update a line's debit bypassing guards) → entry_balance + trial_balance findings; contact-less control line (raw insert) → control_contact finding; zero-companies invocation → non-zero exit; command on 2 companies isolates findings to the tampered one. Commit.

---

### Task 12: Phase 1 exit verification (controller-driven)

- [ ] Full `composer test` + `npm run test:js` + `composer lint:check` + CI grep guard: add to CI a step `! grep -rE "(float|double)\s*\(" app/ --include=*.php | grep -iE "debit|credit|amount|balance|total"` (documented as the §12.10 float guard).
- [ ] Browser walkthrough on the real shell: CoA renders seeded accounts grouped w/ balances; create + archive an account; post a manual JE watching the beam tilt→settle→glow; attempt unbalanced post (button disabled); reverse an entry; lock period, attempt back-dated JE → friendly error; Trial Balance balances and exports CSV; Health screen clean; tamper dev DB → run checker → finding appears + exits non-zero; untamper (reverse tamper) → clean.
- [ ] Screenshots: shell+CoA, JE editor mid-tilt, JE editor balanced glow, Trial Balance. Design self-critique vs the brief (non-generic? animation subtle? palette discipline?) — fix what fails the mirror test.
- [ ] Spec §13 Phase 1 exit criteria check; update progress ledger; commit.

---

## Self-Review Notes

- **Spec coverage (Phase 1 row §13):** accounts + CoA screen (T3, T8) ✓; LedgerService all iron rules incl. control-account guards + §17.2 unique + §17.3 CHECK (T4) ✓; manual JEs (T6, T7) ✓; period locks (T4 enforcement, T9 endpoint) ✓; Trial Balance (T10) ✓; integrity checker incl. fails-loudly + seeded-corruption test (T11) ✓; exit criteria (T12). Design system + shell (T1, T2) per the approved product brief; Phase-0 backlog burn-down (T5 + closures in T2 + switch audit in T2).
- **Checks (c)/(d) of §9** (document balances, AR/AP subledger ties) arrive with their tables in Phases 2–4 — checker is structured to add checks per phase.
- **Type consistency:** EntryDraft/LineDraft signatures match between T4 (definition) and T6 (use); AccountBalances/TrialBalance both compute from journal_lines only; formatMoney/parseMoneyInput contracts match T7/T8/T10 UI usage.
- **No placeholders:** UI tasks deliberately specify contracts + visual law rather than full JSX (recorded as a plan-style decision in the header); all domain code is complete.

import CompanyMenu from '@/Components/shell/CompanyMenu';
import SidebarLink from '@/Components/shell/SidebarLink';
import UserMenu from '@/Components/shell/UserMenu';
import Wordmark from '@/Components/shell/Wordmark';
import { PageProps } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { PropsWithChildren, ReactNode } from 'react';

interface NavItem {
    label: string;
    href: string;
    /** See SidebarLink's activeMatch prop. */
    activeMatch?: string[];
    /**
     * Ability key(s) gating visibility, from the shared `can` prop (see
     * HandleInertiaRequests::NAV_ABILITIES). Omitted for links every
     * onboarded user may see (just Dashboard). An array means "visible if
     * the user holds ANY of these" — Period lock's GET route allows
     * periods.lock OR reports.view holders (see PeriodLockController::edit).
     */
    ability?: string | string[];
}

interface NavGroup {
    label: string;
    items: NavItem[];
}

function isVisible(item: NavItem, can: Record<string, boolean>): boolean {
    if (item.ability === undefined) {
        return true;
    }

    const abilities = Array.isArray(item.ability) ? item.ability : [item.ability];

    return abilities.some((ability) => can[ability]);
}

/**
 * The final Phase-2 nav shape, filtered down to the links the current
 * user's role can actually reach (see `can`, shared on every request). A
 * group with no visible items is dropped entirely rather than rendering an
 * empty heading.
 */
function navGroups(can: Record<string, boolean>): NavGroup[] {
    const groups: NavGroup[] = [
        {
            label: 'Overview',
            items: [{ label: 'Dashboard', href: route('dashboard') }],
        },
        {
            label: 'Sales',
            items: [
                {
                    label: 'Invoices',
                    href: route('invoices.index'),
                    ability: 'invoices.draft',
                    activeMatch: [route('invoices.new')],
                },
                { label: 'Contacts', href: route('contacts.index'), ability: 'contacts.manage' },
                { label: 'Items', href: route('items.index'), ability: 'items.manage' },
            ],
        },
        {
            label: 'Purchases',
            items: [
                {
                    label: 'Expenses',
                    href: route('expenses.index'),
                    ability: 'expenses.draft',
                    activeMatch: [route('expenses.new')],
                },
                {
                    label: 'Bills',
                    href: route('bills.index'),
                    ability: 'bills.draft',
                    activeMatch: [route('bills.new')],
                },
            ],
        },
        {
            label: 'Books',
            items: [
                { label: 'Chart of accounts', href: route('accounts.index'), ability: 'accounts.manage' },
                { label: 'Journal entries', href: route('journal-entries.index'), ability: 'ledger.post_manual' },
                {
                    label: 'Banking',
                    href: route('bank-accounts.index'),
                    ability: 'bank_accounts.manage',
                    // Transfers + other receipts are launched from the
                    // Banking screen itself (see Banking/Index.tsx) rather
                    // than getting their own nav entries — but Banking
                    // should still read as "active" while on those screens.
                    activeMatch: [route('transfers.new'), route('other-receipts.new')],
                },
            ],
        },
        {
            label: 'Reports',
            items: [
                { label: 'Profit & loss', href: route('reports.profit-and-loss'), ability: 'reports.view' },
                { label: 'Balance sheet', href: route('reports.balance-sheet'), ability: 'reports.view' },
                { label: 'A/R aging', href: route('reports.ar-aging'), ability: 'reports.view' },
                { label: 'A/P aging', href: route('reports.ap-aging'), ability: 'reports.view' },
                { label: 'Trial balance', href: route('reports.trial-balance'), ability: 'reports.view' },
                { label: 'General ledger', href: route('reports.general-ledger'), ability: 'reports.view' },
                { label: 'Tax summary', href: route('reports.tax-summary'), ability: 'reports.view' },
            ],
        },
        {
            label: 'Settings',
            items: [
                { label: 'Tax rates', href: route('tax-rates.index'), ability: 'tax_rates.manage' },
                {
                    label: 'Period lock',
                    href: route('settings.books'),
                    ability: ['periods.lock', 'reports.view'],
                },
                { label: 'Opening balances', href: route('setup.opening-balances'), ability: 'settings.manage' },
                { label: 'Health', href: route('settings.health'), ability: 'audit.view' },
            ],
        },
    ];

    return groups
        .map((group) => ({ ...group, items: group.items.filter((item) => isVisible(item, can)) }))
        .filter((group) => group.items.length > 0);
}

/**
 * The default authenticated layout: ink sidebar (248px) + 56px paper header
 * with the page title and the company/user menus. Every page behind auth
 * (other than the centered auth/onboarding sheets) renders inside this.
 */
export default function AppShell({
    title,
    children,
}: PropsWithChildren<{ title: ReactNode }>) {
    const { can } = usePage<PageProps>().props;

    return (
        <div className="flex min-h-screen bg-paper">
            <aside className="flex w-[248px] shrink-0 flex-col bg-ink">
                <div className="px-5 py-6">
                    <Link
                        href={route('dashboard')}
                        className="rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-spark focus-visible:ring-offset-2 focus-visible:ring-offset-ink"
                    >
                        <Wordmark tone="white" />
                    </Link>
                </div>
                <nav className="flex-1 space-y-6 px-3 pb-6">
                    {navGroups(can).map((group) => (
                        <div key={group.label}>
                            <p className="px-4 pb-2 text-xs font-semibold uppercase tracking-wide text-ink-100/60">
                                {group.label}
                            </p>
                            <div className="space-y-1">
                                {group.items.map((item) => (
                                    <SidebarLink
                                        key={item.href}
                                        href={item.href}
                                        activeMatch={item.activeMatch}
                                    >
                                        {item.label}
                                    </SidebarLink>
                                ))}
                            </div>
                        </div>
                    ))}
                </nav>
            </aside>

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="flex h-14 shrink-0 items-center justify-between border-b border-rule bg-paper px-6">
                    <h1 className="font-display text-lg font-semibold text-ink">
                        {title}
                    </h1>
                    <div className="flex items-center gap-3">
                        <CompanyMenu />
                        <UserMenu />
                    </div>
                </header>
                <main className="flex-1 p-6">{children}</main>
            </div>
        </div>
    );
}

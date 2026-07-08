import CompanyMenu from '@/Components/shell/CompanyMenu';
import SidebarLink from '@/Components/shell/SidebarLink';
import UserMenu from '@/Components/shell/UserMenu';
import Wordmark from '@/Components/shell/Wordmark';
import { Link } from '@inertiajs/react';
import { PropsWithChildren, ReactNode } from 'react';

interface NavItem {
    label: string;
    href: string;
}

interface NavGroup {
    label: string;
    items: NavItem[];
}

function navGroups(): NavGroup[] {
    return [
        {
            label: 'Overview',
            items: [{ label: 'Dashboard', href: route('dashboard') }],
        },
        {
            label: 'Books',
            items: [
                { label: 'Chart of accounts', href: '/accounts' },
                { label: 'Journal entries', href: '/journal-entries' },
            ],
        },
        {
            label: 'Reports',
            items: [
                { label: 'Trial balance', href: '/reports/trial-balance' },
            ],
        },
        {
            label: 'Settings',
            items: [{ label: 'Books', href: '/settings/books' }],
        },
    ];
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
                    {navGroups().map((group) => (
                        <div key={group.label}>
                            <p className="px-4 pb-2 text-xs font-semibold uppercase tracking-wide text-ink-100/60">
                                {group.label}
                            </p>
                            <div className="space-y-1">
                                {group.items.map((item) => (
                                    <SidebarLink
                                        key={item.href}
                                        href={item.href}
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

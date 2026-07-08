import { Link, usePage } from '@inertiajs/react';
import { ReactNode } from 'react';

interface SidebarLinkProps {
    href: string;
    children: ReactNode;
    /**
     * Extra paths that should also mark this link active, for flows reached
     * from this link's screen but routed at their own top-level path rather
     * than nested under it — e.g. Transfers and Other receipts are launched
     * from the Banking screen (bank-accounts.index) but live at /transfers
     * and /other-receipts, not /bank-accounts/*. Matched with the same
     * exact-or-child-path rule as `href`.
     */
    activeMatch?: string[];
}

/**
 * Ziggy's route() returns an absolute URL by default, but usePage().url is
 * path-only — so href and the current page url need normalizing to the same
 * shape before comparing. `new URL` with a base handles both cases.
 */
function pathOf(href: string): string {
    return new URL(href, window.location.origin).pathname;
}

function isPathActive(currentPath: string, href: string): boolean {
    const targetPath = pathOf(href);

    return currentPath === targetPath || currentPath.startsWith(`${targetPath}/`);
}

/**
 * A single sidebar nav item. Active state is path-based (not route-name
 * based) because the Books/Reports routes it can point at don't exist yet in
 * Phase 1 — they 404 until later tasks, but the link still needs to render
 * and highlight correctly once they do.
 */
export default function SidebarLink({ href, children, activeMatch }: SidebarLinkProps) {
    const { url } = usePage();
    const currentPath = pathOf(url);
    const isActive =
        isPathActive(currentPath, href) ||
        (activeMatch?.some((extra) => isPathActive(currentPath, extra)) ?? false);

    return (
        <Link
            href={href}
            prefetch="hover"
            aria-current={isActive ? 'page' : undefined}
            className={
                `relative flex h-9 items-center rounded-md pl-4 pr-3 text-sm font-medium transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus-visible:ring-2 focus-visible:ring-spark focus-visible:ring-offset-2 focus-visible:ring-offset-ink ` +
                (isActive
                    ? 'bg-ink-800 text-white'
                    : 'text-ink-100 hover:bg-ink-800/60 hover:text-white')
            }
        >
            {isActive && (
                <span
                    aria-hidden="true"
                    className="absolute inset-y-1 left-0 w-[3px] rounded-full bg-gradient-to-b from-spark to-lagoon"
                />
            )}
            {children}
        </Link>
    );
}

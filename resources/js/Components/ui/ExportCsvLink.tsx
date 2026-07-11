import { ReactNode } from 'react';

interface ExportCsvLinkProps {
    href: string;
    children?: ReactNode;
}

/**
 * A quiet "Export CSV" link for a list-screen export endpoint (Phase 6 Task
 * 3, spec §11/§17.8) — a plain `<a>` (not an Inertia Link) so the browser
 * navigates natively and downloads the streamed CSV, rather than Inertia
 * trying to swap it in as a page visit. Styling mirrors
 * Reports/TrialBalance.tsx's own inline Export CSV link exactly, factored
 * out here since every list Index.tsx (Invoices/Bills/Expenses/Contacts/
 * Items/Banking/Journal) needs the identical button. Callers gate rendering
 * on `can['exports.run']` themselves (mirrors every other permission-gated
 * action in these pages) — this component itself renders unconditionally
 * whenever mounted.
 */
export default function ExportCsvLink({ href, children = 'Export CSV' }: ExportCsvLinkProps) {
    return (
        <a
            href={href}
            className="inline-flex h-8 items-center justify-center gap-2 rounded-lg px-3 font-sans text-xs font-semibold text-ink-700 transition duration-[120ms] ease-out hover:bg-paper focus:outline-none focus-visible:ring-2 focus-visible:ring-spark focus-visible:ring-offset-2 focus-visible:ring-offset-paper-raised motion-reduce:transition-none"
        >
            {children}
        </a>
    );
}

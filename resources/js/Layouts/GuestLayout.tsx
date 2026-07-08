import Sheet from '@/Components/ui/Sheet';
import Wordmark from '@/Components/shell/Wordmark';
import { Link } from '@inertiajs/react';
import { PropsWithChildren } from 'react';

/**
 * Centered single sheet on the paper background, wordmark above — used by
 * every auth screen (login, register, ...) and onboarding's create-company
 * form, since none of them have a company context yet to hang an AppShell off.
 */
export default function Guest({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen flex-col items-center justify-center gap-8 bg-paper px-4 py-12">
            <Link
                href="/"
                className="rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-spark focus-visible:ring-offset-2"
            >
                <Wordmark tone="ink" />
            </Link>

            <Sheet className="w-full sm:max-w-md">{children}</Sheet>
        </div>
    );
}

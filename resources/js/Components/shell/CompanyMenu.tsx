import { PageProps } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

function capitalize(value: string): string {
    return value.length ? value[0].toUpperCase() + value.slice(1) : value;
}

/**
 * Current company name + role badge in the header, with a dropdown of the
 * user's other memberships. Selecting one posts to `company.switch`.
 */
export default function CompanyMenu() {
    const { companies, currentCompany } = usePage<PageProps>().props;
    const [open, setOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        function handlePointerDown(event: MouseEvent) {
            if (!containerRef.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        }

        function handleKeyDown(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        }

        document.addEventListener('mousedown', handlePointerDown);
        document.addEventListener('keydown', handleKeyDown);

        return () => {
            document.removeEventListener('mousedown', handlePointerDown);
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [open]);

    if (!currentCompany) {
        return null;
    }

    const currentRole = companies.find(
        (membership) => membership.id === currentCompany.id,
    )?.role;

    return (
        <div ref={containerRef} className="relative">
            <button
                type="button"
                aria-haspopup="menu"
                aria-expanded={open}
                aria-label="Switch company"
                onClick={() => setOpen((value) => !value)}
                className="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm font-medium text-ink transition duration-[120ms] ease-out motion-reduce:transition-none hover:bg-paper focus:outline-none focus-visible:ring-2 focus-visible:ring-spark focus-visible:ring-offset-2"
            >
                <span>{currentCompany.name}</span>
                {currentRole && (
                    <span className="rounded-full bg-ink-100 px-2 py-0.5 text-xs font-medium text-ink-700">
                        {capitalize(currentRole)}
                    </span>
                )}
                <svg
                    aria-hidden="true"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    className="h-4 w-4 text-ink-700"
                >
                    <path
                        fillRule="evenodd"
                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                        clipRule="evenodd"
                    />
                </svg>
            </button>

            {open && (
                <div
                    role="menu"
                    aria-label="Companies"
                    className="absolute right-0 z-50 mt-2 w-64 origin-top-right rounded-sheet bg-paper-raised py-1 shadow-sheet"
                >
                    {companies.map((membership) => {
                        const isActive = membership.id === currentCompany.id;

                        return (
                            <Link
                                key={membership.id}
                                role="menuitem"
                                href={route('company.switch')}
                                method="post"
                                data={{ company_id: membership.id }}
                                as="button"
                                onClick={() => setOpen(false)}
                                className="flex w-full items-center justify-between gap-2 px-4 py-2 text-left text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none hover:bg-paper focus:outline-none focus-visible:bg-paper focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-spark"
                            >
                                <span className="flex flex-col">
                                    <span className="font-medium">
                                        {membership.name}
                                    </span>
                                    <span className="text-xs text-ink-700">
                                        {capitalize(membership.role)}
                                    </span>
                                </span>
                                {isActive && (
                                    <svg
                                        aria-hidden="true"
                                        viewBox="0 0 20 20"
                                        fill="currentColor"
                                        className="h-4 w-4 shrink-0 text-spark"
                                    >
                                        <path
                                            fillRule="evenodd"
                                            d="M16.704 5.29a1 1 0 010 1.415l-7.5 7.5a1 1 0 01-1.415 0l-3.5-3.5a1 1 0 111.415-1.414L8.5 12.086l6.79-6.795a1 1 0 011.414 0z"
                                            clipRule="evenodd"
                                        />
                                    </svg>
                                )}
                            </Link>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

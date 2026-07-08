import { PageProps } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

/** User's name/email in the header, with a dropdown to Profile and Log out. */
export default function UserMenu() {
    const { auth } = usePage<PageProps>().props;
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

    return (
        <div ref={containerRef} className="relative">
            <button
                type="button"
                aria-haspopup="menu"
                aria-expanded={open}
                aria-label={`Account menu for ${auth.user.name}`}
                onClick={() => setOpen((value) => !value)}
                className="flex h-8 w-8 items-center justify-center rounded-full bg-ink text-sm font-semibold text-white transition duration-[120ms] ease-out motion-reduce:transition-none hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-spark focus-visible:ring-offset-2"
            >
                {auth.user.name.charAt(0).toUpperCase()}
            </button>

            {open && (
                <div
                    role="menu"
                    aria-label="Account"
                    className="absolute right-0 z-50 mt-2 w-56 origin-top-right rounded-sheet bg-paper-raised py-1 shadow-sheet"
                >
                    <div className="border-b border-rule px-4 py-3">
                        <p className="truncate text-sm font-medium text-ink">
                            {auth.user.name}
                        </p>
                        <p className="truncate text-xs text-ink-700">
                            {auth.user.email}
                        </p>
                    </div>
                    <Link
                        role="menuitem"
                        href={route('profile.edit')}
                        onClick={() => setOpen(false)}
                        className="block px-4 py-2 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none hover:bg-paper focus:outline-none focus-visible:bg-paper focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-spark"
                    >
                        Profile
                    </Link>
                    <Link
                        role="menuitem"
                        href={route('logout')}
                        method="post"
                        as="button"
                        onClick={() => setOpen(false)}
                        className="block w-full px-4 py-2 text-left text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none hover:bg-paper focus:outline-none focus-visible:bg-paper focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-spark"
                    >
                        Log out
                    </Link>
                </div>
            )}
        </div>
    );
}

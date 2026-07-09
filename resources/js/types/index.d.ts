export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
}

/** One of the auth user's company memberships, as shared on every request. */
export interface CompanyMembership {
    id: number;
    name: string;
    role: string;
}

/** The company currently in scope, as shared on every request (null when none). */
export interface CurrentCompany {
    id: number;
    name: string;
    base_currency: string;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    companies: CompanyMembership[];
    currentCompany: CurrentCompany | null;
    /**
     * A minimal ability map for the current user in currentCompany, keyed by
     * permission (see config/permissions.php). Only covers the abilities the
     * sidebar nav (Layouts/AppShell.tsx) needs to decide what to render —
     * every route those links point at is still independently gated
     * server-side. Empty for guests.
     */
    can: Record<string, boolean>;
};

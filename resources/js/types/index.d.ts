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
};

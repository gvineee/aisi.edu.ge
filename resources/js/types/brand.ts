export interface BrandColors {
    primary?: string;
    accent?: string;
    secondary?: string;
    muted?: string;
    teal?: string;
}

export interface Brand {
    name: string;
    shortName: string | null;
    logoUrl: string | null;
    heroImageUrl: string | null;
    colors: BrandColors;
    locale: string;
    contact: {
        email: string | null;
        phone: string | null;
        address: string | null;
    };
}

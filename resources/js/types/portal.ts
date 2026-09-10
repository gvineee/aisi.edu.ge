export type PortalNavIconKey = 'home' | 'documents' | 'messages';

export interface PortalNavItem {
    key: string;
    label: string;
    href: string;
    icon: PortalNavIconKey;
}

export interface PortalRoleOption {
    value: string;
    label: string;
}

export interface PortalContextProps {
    activeRole: string | null;
    availableRoles: PortalRoleOption[];
    navItems: PortalNavItem[];
}

export type PortalNavIconKey =
    | 'home'
    | 'documents'
    | 'messages'
    | 'cms'
    | 'members'
    | 'teachers'
    | 'assignments'
    | 'academic-structure'
    | 'substitutions'
    | 'timetable'
    | 'admissions-pipeline'
    | 'school-visits'
    | 'books'
    | 'book-lists'
    | 'library-desk'
    | 'pickup'
    | 'consents'
    | 'gradebook'
    | 'assessment-settings'
    | 'progress'
    | 'report-cards'
    | 'health'
    | 'appointments'
    | 'services'
    | 'pulse'
    | 'finance'
    | 'support-access'
    | 'teacher-workspace';

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

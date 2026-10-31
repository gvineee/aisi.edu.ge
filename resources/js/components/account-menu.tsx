import { Link, router } from '@inertiajs/react';
import { ChevronDown, LogOut, ShieldCheck, UserRound } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { logout } from '@/routes';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';

type Props = {
    user: { name: string; email: string };
    /** Shown above the items, e.g. the school or "platform". */
    context?: string;
};

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}

/**
 * The signed-in person's own menu — profile, security (password, MFA,
 * passkeys) and sign-out — the same on every width, so the account pages
 * are reachable from a phone too.
 */
export default function AccountMenu({ user, context }: Props) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                className="inline-flex min-h-11 items-center gap-2 rounded-lg px-2 text-sm font-medium hover:bg-slate-100 focus-visible:ring-2 focus-visible:ring-[var(--brand-accent,#F5683C)] focus-visible:outline-none"
                aria-label={`ანგარიშის მენიუ: ${user.name}`}
            >
                <span
                    aria-hidden
                    className="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-[var(--brand-primary,#132B45)]"
                >
                    {initials(user.name)}
                </span>
                <span className="hidden max-w-40 truncate sm:inline">
                    {user.name}
                </span>
                <ChevronDown size={16} aria-hidden />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-64">
                <DropdownMenuLabel className="font-normal">
                    <span className="block truncate font-semibold">
                        {user.name}
                    </span>
                    <span className="block truncate text-xs text-slate-600">
                        {user.email}
                    </span>
                    {context && (
                        <span className="mt-1 block truncate text-xs text-slate-600">
                            {context}
                        </span>
                    )}
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                    <Link
                        href={editProfile()}
                        className="min-h-11 cursor-pointer"
                    >
                        <UserRound aria-hidden /> პროფილი
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link
                        href={editSecurity()}
                        className="min-h-11 cursor-pointer"
                    >
                        <ShieldCheck aria-hidden /> უსაფრთხოება: პაროლი, MFA,
                        Passkey
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                    <Link
                        href={logout()}
                        as="button"
                        onClick={() => router.flushAll()}
                        className="min-h-11 w-full cursor-pointer"
                    >
                        <LogOut aria-hidden /> გასვლა
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

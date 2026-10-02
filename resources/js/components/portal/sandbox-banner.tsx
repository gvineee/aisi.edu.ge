import { FlaskConical } from 'lucide-react';

/**
 * Shown on every finance screen while only the sandbox provider exists:
 * no real money moves and the module is not production-ready.
 */
export default function SandboxBanner() {
    return (
        <p
            role="note"
            className="mb-6 flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950"
        >
            <FlaskConical size={18} className="mt-0.5 shrink-0" aria-hidden />
            <span>
                <strong>სატესტო რეჟიმი (Sandbox).</strong> რეალური გადახდა არ
                ხდება. ფინანსური მოდული ბანკის ინტეგრაციისა და ბუღალტრის
                შემოწმების გარეშე საწარმოო გამოყენებისთვის მზად არ არის.
            </span>
        </p>
    );
}

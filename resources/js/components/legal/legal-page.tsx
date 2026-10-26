import { Head, Link } from '@inertiajs/react';
import { SnackLogo } from '@/components/snack/snack-logo';
import { home } from '@/routes';
import { deleteAccount, privacy, terms } from '@/routes/legal';

/**
 * Who operates the hosted Snack service and how to reach them. One place,
 * because the terms, the privacy policy and the deletion instructions all quote
 * it, and an address that differs between them is the first thing an app store
 * reviewer notices.
 */
export const LEGAL = {
    company: 'Pedi Solutions Inc.',
    country: 'the Philippines',
    contactEmail: 'privacy@pedi.ph',
    effectiveDate: 'October 26, 2026',
} as const;

const LINKS = [
    { label: 'Terms of Service', href: terms() },
    { label: 'Privacy Policy', href: privacy() },
    { label: 'Delete your account', href: deleteAccount() },
] as const;

/**
 * The shell shared by the public legal pages: a title, the effective date, and
 * prose. These URLs are what the App Store and Play Store listings point at, so
 * they are reachable without signing in and without an initialized instance.
 */
export function LegalPage({
    title,
    summary,
    children,
}: {
    title: string;
    summary: string;
    children: React.ReactNode;
}) {
    return (
        <>
            <Head title={`${title} — Snack`} />

            <div className="min-h-dvh bg-background text-foreground">
                <header className="border-b border-border">
                    <div className="mx-auto flex w-full max-w-3xl items-center justify-between gap-4 px-6 py-4">
                        <Link href={home()} aria-label="Snack home">
                            <SnackLogo />
                        </Link>
                    </div>
                </header>

                <main className="mx-auto w-full max-w-3xl px-6 py-12 lg:py-16">
                    <h1 className="text-3xl font-bold tracking-tight text-balance sm:text-4xl">
                        {title}
                    </h1>

                    <p className="mt-3 text-sm text-muted-foreground">
                        Effective {LEGAL.effectiveDate}
                    </p>

                    <p className="mt-6 text-lg text-pretty text-muted-foreground">
                        {summary}
                    </p>

                    <div className="mt-10 space-y-10 text-[15px] leading-7 [&_a]:font-medium [&_a]:text-brand [&_a]:underline-offset-4 hover:[&_a]:underline [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:tracking-tight [&_h3]:mt-6 [&_h3]:font-semibold [&_li]:mt-1.5 [&_ol]:mt-3 [&_ol]:list-decimal [&_ol]:pl-6 [&_p]:mt-3 [&_ul]:mt-3 [&_ul]:list-disc [&_ul]:pl-6">
                        {children}
                    </div>
                </main>

                <footer className="border-t border-border">
                    <nav
                        aria-label="Legal"
                        className="mx-auto flex w-full max-w-3xl flex-wrap gap-x-6 gap-y-2 px-6 py-8 text-sm text-muted-foreground"
                    >
                        {LINKS.map((link) => (
                            <Link
                                key={link.label}
                                href={link.href}
                                className="hover:text-foreground"
                            >
                                {link.label}
                            </Link>
                        ))}
                    </nav>
                </footer>
            </div>
        </>
    );
}

export function ContactLink() {
    return <a href={`mailto:${LEGAL.contactEmail}`}>{LEGAL.contactEmail}</a>;
}

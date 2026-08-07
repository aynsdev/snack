import { Check, Copy, GitBranch } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Task } from '@/types';

/**
 * What to type in a terminal to start work on this card.
 *
 * Snack is not connected to anybody's repository and does not pretend to be:
 * this section creates nothing and lists no branches, it hands over the three
 * strings that make the work findable again afterwards — the branch, the command
 * that opens it, and a commit message carrying the reference. That convention is
 * the whole value; a team half of whom write `PWA-1499` and half of whom write
 * `fix-login` has no way back from a commit to a card.
 *
 * Shown to everybody who can open the card, including people who cannot edit
 * it: nothing here writes, and the person opening the pull request is not always
 * the person allowed to reassign the card.
 */
export function DevelopmentSection({ task }: { task: Task }) {
    const branch = task.branch_name;
    const field = useRef<HTMLInputElement>(null);
    const [copied, setCopied] = useState<string | null>(null);

    const handleCopy = async (what: string, text: string) => {
        if (what === 'branch') {
            // Selected whether or not the copy succeeds: a visible selection is
            // what lets somebody finish the job with the keyboard.
            field.current?.select();
        }

        if (await copyText(text)) {
            setCopied(what);
            window.setTimeout(() => setCopied(null), 2000);
        }
    };

    return (
        <section className="border-t border-border pt-3">
            <p className="mb-1 flex items-center gap-1.5 text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase">
                <GitBranch aria-hidden="true" className="size-3.5" />
                Development
            </p>

            {/* A real field rather than text, so the branch is still reachable
                where copying is not — see copyText below. */}
            <Input
                ref={field}
                readOnly
                value={branch}
                aria-label="Branch name"
                onFocus={(event) => event.currentTarget.select()}
                title={branch}
                className="w-full font-mono text-xs"
            />

            <Button
                type="button"
                size="sm"
                className="mt-1.5 w-full"
                onClick={() => handleCopy('branch', branch)}
            >
                {copied === 'branch' ? (
                    <Check aria-hidden="true" />
                ) : (
                    <Copy aria-hidden="true" />
                )}
                {copied === 'branch' ? 'Copied' : 'Copy branch name'}
            </Button>

            {/* The other two are text rather than buttons: a sidebar of three
                filled buttons reads as three equally likely actions, and the
                branch name is the one anybody came here for. */}
            <div className="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground">
                <CopyLink
                    label="Copy checkout command"
                    done={copied === 'command'}
                    onCopy={() =>
                        handleCopy('command', `git checkout -b ${branch}`)
                    }
                />

                <CopyLink
                    label="Copy commit message"
                    done={copied === 'commit'}
                    onCopy={() =>
                        handleCopy(
                            'commit',
                            `${task.reference} ${task.title.trim()}`,
                        )
                    }
                />
            </div>
        </section>
    );
}

function CopyLink({
    label,
    done,
    onCopy,
}: {
    label: string;
    done: boolean;
    onCopy: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onCopy}
            className="transition-colors hover:text-foreground"
        >
            {done ? 'Copied' : label}
        </button>
    );
}

/**
 * Copy, including where the modern way is unavailable.
 *
 * `navigator.clipboard` is undefined on insecure origins, and this application
 * is served over plain HTTP in development and on internal deployments. Without
 * a fallback the two buttons that have no field behind them would do nothing at
 * all there — a button that silently fails is worse than no button.
 *
 * So the old `execCommand('copy')` route stays as the fallback: deprecated,
 * still implemented everywhere, and it works on an insecure origin because it
 * copies a selection the user's own click produced rather than writing to the
 * clipboard unprompted.
 */
async function copyText(text: string): Promise<boolean> {
    if (navigator?.clipboard) {
        try {
            await navigator.clipboard.writeText(text);

            return true;
        } catch {
            // Denied or unavailable — fall through and try the old way.
        }
    }

    const carrier = document.createElement('textarea');

    carrier.value = text;
    carrier.setAttribute('readonly', '');
    // Off-screen rather than hidden: a field that is not rendered cannot be
    // selected, and an unselected field copies nothing.
    carrier.style.position = 'fixed';
    carrier.style.opacity = '0';
    carrier.style.pointerEvents = 'none';

    document.body.appendChild(carrier);
    carrier.select();

    try {
        return document.execCommand('copy');
    } catch {
        return false;
    } finally {
        carrier.remove();
    }
}

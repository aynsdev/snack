import { Bell, X } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    readNotificationSupport,
    requestNotificationPermission,
} from '@/lib/notifications';

/** Remembers a dismissal, so declining once is not asked again next page. */
const DISMISSED_KEY = 'snack:notification-prompt-dismissed';

/**
 * Offers to turn on desktop notifications, once.
 *
 * # Why this is not requested on load
 *
 * Chrome treats a dismissed permission prompt as a denial and will not let the
 * page ask again — the decision is permanent from the app's side. Asking the
 * moment someone arrives, before a single message has come in, spends that one
 * chance on a question they have no context for, and the usual answer is no.
 *
 * So the ask is an explicit affordance, shown only while the browser would
 * actually act on it: granted, denied, and unsupported all render nothing,
 * because in none of those cases is there a question left to put to the user.
 */
export function NotificationPermissionPrompt() {
    const [support, setSupport] = useState(readNotificationSupport);
    const [dismissed, setDismissed] = useState(readDismissed);
    const [asking, setAsking] = useState(false);

    if (support.state !== 'prompt' || dismissed) {
        return null;
    }

    const dismiss = () => {
        setDismissed(true);

        try {
            window.localStorage.setItem(DISMISSED_KEY, '1');
        } catch {
            // Private browsing, or storage disabled. The prompt reappearing on
            // the next visit is a far smaller problem than a crash here.
        }
    };

    const enable = async () => {
        setAsking(true);

        try {
            setSupport(await requestNotificationPermission());
        } finally {
            setAsking(false);
        }
    };

    return (
        <div className="mx-3 mb-3 rounded-lg border border-sidebar-border bg-sidebar-accent/50 p-3">
            <div className="flex items-start gap-2">
                <Bell aria-hidden="true" className="mt-0.5 size-4 shrink-0" />

                <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium">Get notified</p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        Show a desktop notification when a message arrives while
                        you are away.
                    </p>

                    <Button
                        size="sm"
                        className="mt-2 h-7"
                        disabled={asking}
                        onClick={() => void enable()}
                    >
                        {asking ? 'Waiting…' : 'Turn on'}
                    </Button>
                </div>

                <button
                    type="button"
                    onClick={dismiss}
                    aria-label="Dismiss notification prompt"
                    className="rounded p-0.5 text-muted-foreground hover:bg-sidebar-accent hover:text-sidebar-foreground"
                >
                    <X aria-hidden="true" className="size-3.5" />
                </button>
            </div>
        </div>
    );
}

function readDismissed(): boolean {
    try {
        return window.localStorage.getItem(DISMISSED_KEY) === '1';
    } catch {
        return false;
    }
}

import { Head, Link } from '@inertiajs/react';
import { Compass } from 'lucide-react';
import { EmptyState } from '@/components/snack/empty-state';
import { Button } from '@/components/ui/button';
import { useWorkspace } from '@/hooks/use-workspace';
import { index as browseChannels } from '@/routes/channels';

type Props = {
    canCreateChannels: boolean;
};

/**
 * Shown when someone enters a workspace with no channels to land in — a guest
 * who has not been added anywhere yet, or a workspace whose channels have all
 * been archived.
 */
export default function WorkspaceEmpty({ canCreateChannels }: Props) {
    const workspace = useWorkspace();

    return (
        <>
            <Head title={workspace.name} />

            <div className="flex min-w-0 flex-1 items-center justify-center">
                <EmptyState
                    icon={Compass}
                    title="Nothing here yet"
                    description={
                        canCreateChannels
                            ? 'You are not in any channels. Browse what exists, or start one of your own.'
                            : 'You have not been added to any channels yet. An admin can add you to the ones you need.'
                    }
                    action={
                        canCreateChannels ? (
                            <Button asChild>
                                <Link href={browseChannels(workspace.slug)}>
                                    Browse channels
                                </Link>
                            </Button>
                        ) : null
                    }
                />
            </div>
        </>
    );
}

import { Film, Image, MessageSquare } from 'lucide-react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { BiteMediaModel } from '@/types/bite';

export function BiteModelControls({
    models,
    value,
    onChange,
    disabled = false,
}: {
    models: BiteMediaModel[];
    value: string | null;
    onChange: (value: string | null) => void;
    disabled?: boolean;
}) {
    const selected = models.find((model) => model.id === value);
    const mode = selected?.kind ?? 'chat';
    const choices = models.filter((model) => model.kind === mode);

    return (
        <div className="flex flex-wrap items-center gap-2">
            <div
                className="flex items-center gap-1"
                role="group"
                aria-label="Bite mode"
            >
                {(
                    [
                        { id: 'chat', label: 'Chat', icon: MessageSquare },
                        { id: 'image', label: 'Image', icon: Image },
                        { id: 'video', label: 'Video', icon: Film },
                    ] as const
                )
                    .filter(
                        (item) =>
                            item.id === 'chat' ||
                            models.some((model) => model.kind === item.id),
                    )
                    .map((item) => (
                        <button
                            key={item.id}
                            type="button"
                            disabled={disabled}
                            aria-pressed={mode === item.id}
                            onClick={() =>
                                onChange(
                                    models.find(
                                        (model) => model.kind === item.id,
                                    )?.id ?? null,
                                )
                            }
                            className={cn(
                                'inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50',
                                mode === item.id
                                    ? 'bg-primary/10 text-foreground'
                                    : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                            )}
                        >
                            <item.icon
                                aria-hidden="true"
                                className="size-3.5"
                            />
                            {item.label}
                        </button>
                    ))}
            </div>

            {selected && (
                <Select
                    value={selected.id}
                    onValueChange={onChange}
                    disabled={disabled}
                >
                    <SelectTrigger
                        aria-label={`${mode === 'image' ? 'Image' : 'Video'} model`}
                        className="h-8 w-auto max-w-full min-w-40 rounded-lg text-xs shadow-none"
                    >
                        <SelectValue>{selected.label}</SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                        {choices.map((model) => (
                            <SelectItem
                                key={model.id}
                                value={model.id}
                                textValue={model.label}
                            >
                                <span className="flex flex-col gap-0.5">
                                    <span>{model.label}</span>
                                    <span className="text-xs text-muted-foreground">
                                        {model.description}
                                    </span>
                                </span>
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            )}
        </div>
    );
}

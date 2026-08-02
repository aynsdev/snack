import { Check } from 'lucide-react';
import { colorThemes, useColorTheme } from '@/hooks/use-color-theme';
import { cn } from '@/lib/utils';

export function ThemePicker() {
    const { colorTheme, updateColorTheme } = useColorTheme();

    return (
        <div
            role="group"
            aria-label="Color theme"
            className="grid gap-3 sm:grid-cols-2"
        >
            {colorThemes.map(({ value, label, colors }) => {
                const isSelected = colorTheme === value;

                return (
                    <button
                        key={value}
                        type="button"
                        aria-pressed={isSelected}
                        onClick={() => updateColorTheme(value)}
                        className={cn(
                            'flex items-center gap-3 rounded-xl border bg-card p-3 text-left transition-colors hover:bg-accent',
                            isSelected
                                ? 'border-brand ring-2 ring-brand/20'
                                : 'border-border',
                        )}
                    >
                        <ThemeSwatch colors={colors} />
                        <span className="min-w-0 flex-1 text-sm font-medium">
                            {label}
                        </span>
                        {isSelected && (
                            <Check
                                aria-hidden="true"
                                className="size-4 text-brand"
                            />
                        )}
                    </button>
                );
            })}
        </div>
    );
}

export function ThemeSwatch({
    colors,
    size = 'large',
}: {
    colors: readonly [string, string, string];
    size?: 'small' | 'large';
}) {
    return (
        <span
            aria-hidden="true"
            className={cn(
                'flex shrink-0 overflow-hidden rounded-full border border-black/10',
                size === 'large' ? 'size-9' : 'size-5',
            )}
        >
            {colors.map((color) => (
                <span
                    key={color}
                    className="h-full flex-1"
                    style={{ backgroundColor: color }}
                />
            ))}
        </span>
    );
}

import { Palette } from 'lucide-react';
import { ThemeSwatch } from '@/components/theme-picker';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { colorThemes, useColorTheme } from '@/hooks/use-color-theme';
import type { ColorTheme } from '@/hooks/use-color-theme';

export function ThemeSwitcher() {
    const { colorTheme, updateColorTheme } = useColorTheme();
    const activeTheme = colorThemes.find(({ value }) => value === colorTheme)!;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                aria-label={`Change color theme. Current theme: ${activeTheme.label}`}
                className="flex size-10 items-center justify-center rounded-lg text-rail-muted transition-colors hover:bg-rail-foreground/10 hover:text-rail-foreground data-[state=open]:bg-rail-foreground/10 data-[state=open]:text-rail-foreground"
            >
                <Palette aria-hidden="true" className="size-4.5" />
            </DropdownMenuTrigger>
            <DropdownMenuContent side="right" align="end" className="w-56">
                <DropdownMenuLabel>Color theme</DropdownMenuLabel>
                <DropdownMenuRadioGroup
                    value={colorTheme}
                    onValueChange={(value) =>
                        updateColorTheme(value as ColorTheme)
                    }
                >
                    {colorThemes.map(({ value, label, colors }) => (
                        <DropdownMenuRadioItem key={value} value={value}>
                            <ThemeSwatch colors={colors} size="small" />
                            {label}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

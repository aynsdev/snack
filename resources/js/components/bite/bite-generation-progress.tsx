import { Film, Image } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { BitePendingGeneration } from '@/types/bite';

const WORDS = [
    'Thinking',
    'Imagining',
    'Composing',
    'Combobulating',
    'Adding the details',
    'Connecting the dots',
    'Making it happen',
];

function useActivity() {
    const ref = useRef<HTMLDivElement>(null);
    const [visible, setVisible] = useState(false);
    const [awake, setAwake] = useState(
        () => typeof document === 'undefined' || !document.hidden,
    );
    const [word, setWord] = useState(0);

    useEffect(() => {
        const element = ref.current;

        if (element === null) {
            return;
        }

        const observer = new IntersectionObserver(([entry]) =>
            setVisible(entry.isIntersecting),
        );
        observer.observe(element);

        const updateVisibility = () => setAwake(!document.hidden);
        document.addEventListener('visibilitychange', updateVisibility);

        return () => {
            observer.disconnect();
            document.removeEventListener('visibilitychange', updateVisibility);
        };
    }, []);

    const active = visible && awake;

    useEffect(() => {
        if (!active) {
            return;
        }

        const timer = window.setInterval(() => {
            setWord(
                (current) =>
                    (current +
                        1 +
                        Math.floor(Math.random() * (WORDS.length - 1))) %
                    WORDS.length,
            );
        }, 3200);

        return () => window.clearInterval(timer);
    }, [active]);

    return { ref, active, word: WORDS[word] };
}

export function BiteThinking() {
    const { ref, active, word } = useActivity();

    return (
        <div
            ref={ref}
            role="status"
            aria-label="Bite is thinking"
            className="bite-activity flex items-center gap-2 px-4 py-5 text-sm text-muted-foreground sm:px-6"
            data-active={active}
        >
            <span
                aria-hidden="true"
                className="bite-activity-dot size-2 rounded-full bg-current"
            />
            <span aria-hidden="true">{word}…</span>
        </div>
    );
}

export function BiteGenerationProgress({
    generation,
}: {
    generation: BitePendingGeneration;
}) {
    const { ref, active, word } = useActivity();
    const isVideo = generation.kind === 'video';
    const Icon = isVideo ? Film : Image;

    return (
        <div
            ref={ref}
            role="status"
            aria-label={`Generating ${generation.kind} with ${generation.model}`}
            className="bite-activity px-4 py-5 sm:px-6"
            data-active={active}
        >
            <div className="w-full max-w-sm">
                <div
                    aria-hidden="true"
                    className="relative isolate overflow-hidden rounded-2xl bg-muted"
                    style={{
                        aspectRatio:
                            generation.aspect_ratio?.replace(':', '/') ??
                            (isVideo ? '16/9' : '1/1'),
                    }}
                >
                    <svg
                        className="absolute inset-0 size-full opacity-15"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <filter id={`bite-grain-${generation.id}`}>
                            <feTurbulence
                                type="fractalNoise"
                                baseFrequency="0.7"
                                numOctaves="3"
                                stitchTiles="stitch"
                            />
                            <feColorMatrix type="saturate" values="0" />
                        </filter>
                        <rect
                            width="100%"
                            height="100%"
                            filter={`url(#bite-grain-${generation.id})`}
                        />
                    </svg>
                    <div className="bite-generation-sweep absolute inset-0 bg-linear-to-r from-transparent via-foreground/10 to-transparent" />
                    <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 text-muted-foreground">
                        <Icon className="size-7 stroke-1" />
                        <span className="text-sm">
                            Creating your {generation.kind}
                        </span>
                    </div>
                </div>

                <div
                    aria-hidden="true"
                    className="mt-3 flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <span className="bite-activity-dot size-1.5 shrink-0 rounded-full bg-current" />
                    <span className="min-w-0 flex-1">{word}…</span>
                    <span className="shrink-0 text-xs">{generation.model}</span>
                </div>
                <p className="mt-1.5 line-clamp-2 text-xs leading-5 text-pretty text-muted-foreground">
                    {generation.prompt}
                </p>
            </div>
        </div>
    );
}

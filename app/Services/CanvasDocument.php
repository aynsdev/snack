<?php

namespace App\Services;

/**
 * Validates and flattens the Tiptap JSON stored for a Canvas.
 *
 * The browser sends a document tree, never HTML. Only the nodes and marks the
 * Canvas editor registers are accepted, which prevents a crafted request from
 * persisting an extension the viewer cannot safely or predictably render.
 */
class CanvasDocument
{
    /** @var array<int, string> */
    private const NODE_TYPES = [
        'doc', 'paragraph', 'text', 'blockquote', 'bulletList', 'orderedList',
        'listItem', 'heading', 'codeBlock', 'hardBreak', 'horizontalRule',
        'taskList', 'taskItem', 'table', 'tableRow', 'tableHeader', 'tableCell',
        'image', 'youtube', 'details', 'detailsSummary', 'detailsContent',
        'inlineMath', 'blockMath',
    ];

    /** @var array<int, string> */
    private const MARK_TYPES = [
        'bold', 'italic', 'strike', 'code', 'link', 'underline', 'highlight',
        'subscript', 'superscript', 'textStyle',
    ];

    /** @return array{type: string, content: array<int, mixed>} */
    public function empty(): array
    {
        return [
            'type' => 'doc',
            'content' => [['type' => 'paragraph']],
        ];
    }

    /**
     * @return array{title: string, content: array<string, mixed>}
     */
    public function template(string $template): array
    {
        return match ($template) {
            'employee-onboarding' => [
                'title' => 'Employee onboarding',
                'content' => $this->document([
                    $this->heading('Welcome to the team', 1),
                    $this->paragraph('Everything you need for a confident first week.'),
                    $this->heading('Your first week', 2),
                    $this->task('Meet your manager and teammates'),
                    $this->task('Review the team handbook'),
                    $this->task('Set up your tools and accounts'),
                ]),
            ],
            'meeting-notes' => [
                'title' => 'Meeting notes',
                'content' => $this->document([
                    $this->heading('Meeting notes', 1),
                    $this->paragraph('Date · Attendees · Facilitator'),
                    $this->heading('Agenda', 2),
                    $this->paragraph('Add the topics you want to cover.'),
                    $this->heading('Decisions', 2),
                    $this->paragraph('Capture what the team agreed to.'),
                    $this->heading('Action items', 2),
                    $this->task('Add an owner and due date'),
                ]),
            ],
            'project-brief' => [
                'title' => 'Project brief',
                'content' => $this->document([
                    $this->heading('Project brief', 1),
                    $this->heading('Overview', 2),
                    $this->paragraph('Describe the problem and the outcome this project should create.'),
                    $this->heading('Goals', 2),
                    $this->paragraph('Define the measurable results.'),
                    $this->heading('Scope and milestones', 2),
                    $this->task('Add the first milestone'),
                ]),
            ],
            default => throw new \InvalidArgumentException('Unknown Canvas template.'),
        };
    }

    /** @param array<string, mixed> $document */
    public function isValid(array $document): bool
    {
        $nodes = 0;

        return ($document['type'] ?? null) === 'doc'
            && $this->validNode($document, 0, $nodes)
            && $nodes <= 10_000
            && strlen((string) json_encode($document)) <= 500_000;
    }

    /** @param array<string, mixed> $document */
    public function text(array $document): string
    {
        $parts = [];
        $this->collectText($document, $parts);

        return str(implode('', $parts))
            ->replaceMatches('/[ \t]+\n/', "\n")
            ->replaceMatches('/\n{3,}/', "\n\n")
            ->trim()
            ->limit(200_000, '')
            ->toString();
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function validNode(array $node, int $depth, int &$nodes): bool
    {
        $nodes++;

        if ($depth > 30 || $nodes > 10_000) {
            return false;
        }

        $type = $node['type'] ?? null;

        if (! is_string($type) || ! in_array($type, self::NODE_TYPES, true)) {
            return false;
        }

        if ($type === 'text' && (! isset($node['text']) || ! is_string($node['text']))) {
            return false;
        }

        if (isset($node['attrs']) && ! is_array($node['attrs'])) {
            return false;
        }

        if (! $this->safeSource($type, $node['attrs'] ?? [])) {
            return false;
        }

        if (isset($node['marks'])) {
            if (! is_array($node['marks'])) {
                return false;
            }

            foreach ($node['marks'] as $mark) {
                if (! is_array($mark) || ! $this->validMark($mark)) {
                    return false;
                }
            }
        }

        if (! isset($node['content'])) {
            return true;
        }

        if (! is_array($node['content'])) {
            return false;
        }

        foreach ($node['content'] as $child) {
            if (! is_array($child) || ! $this->validNode($child, $depth + 1, $nodes)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $mark */
    private function validMark(array $mark): bool
    {
        $type = $mark['type'] ?? null;

        if (! is_string($type) || ! in_array($type, self::MARK_TYPES, true)) {
            return false;
        }

        $attrs = $mark['attrs'] ?? [];

        if (! is_array($attrs)) {
            return false;
        }

        return $type !== 'link' || $this->safeUrl($attrs['href'] ?? null);
    }

    /** @param array<string, mixed> $attrs */
    private function safeSource(string $type, array $attrs): bool
    {
        if (! in_array($type, ['image', 'youtube'], true)) {
            return true;
        }

        $source = $attrs['src'] ?? null;

        if (! is_string($source) || strlen($source) > 2_048) {
            return false;
        }

        if (str_starts_with($source, '/')) {
            return true;
        }

        return in_array(
            strtolower((string) parse_url($source, PHP_URL_SCHEME)),
            ['http', 'https'],
            true,
        );
    }

    private function safeUrl(mixed $value): bool
    {
        if (! is_string($value) || strlen($value) > 2_048) {
            return false;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     * @return array{type: string, content: array<int, array<string, mixed>>}
     */
    private function document(array $content): array
    {
        return ['type' => 'doc', 'content' => $content];
    }

    /** @return array<string, mixed> */
    private function heading(string $text, int $level): array
    {
        return [
            'type' => 'heading',
            'attrs' => ['level' => $level],
            'content' => [['type' => 'text', 'text' => $text]],
        ];
    }

    /** @return array<string, mixed> */
    private function paragraph(string $text): array
    {
        return [
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => $text]],
        ];
    }

    /** @return array<string, mixed> */
    private function task(string $text): array
    {
        return [
            'type' => 'taskList',
            'content' => [[
                'type' => 'taskItem',
                'attrs' => ['checked' => false],
                'content' => [$this->paragraph($text)],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  $parts
     */
    private function collectText(array $node, array &$parts): void
    {
        if (($node['type'] ?? null) === 'text' && is_string($node['text'] ?? null)) {
            $parts[] = $node['text'];
        }

        if (($node['type'] ?? null) === 'hardBreak') {
            $parts[] = "\n";
        }

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $this->collectText($child, $parts);
            }
        }

        if (in_array($node['type'] ?? null, [
            'paragraph', 'heading', 'blockquote', 'listItem', 'taskItem',
            'tableCell', 'tableHeader', 'codeBlock', 'detailsSummary',
        ], true)) {
            $parts[] = "\n";
        }
    }
}

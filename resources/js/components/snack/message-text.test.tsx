import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { MessageText } from '@/components/snack/message-text';

/**
 * Rendering a message body.
 *
 * The point of these tests is what does *not* happen: a body containing markup
 * must reach the DOM as text, never as elements. That property is what lets the
 * server store author text verbatim instead of sanitizing it.
 */
describe('MessageText', () => {
    it('renders plain text', () => {
        render(<MessageText body="Hello there" />);

        expect(screen.getByText('Hello there')).toBeInTheDocument();
    });

    it('renders a script tag as visible text, not as an element', () => {
        const { container } = render(
            <MessageText body="<script>alert(1)</script>" />,
        );

        expect(container.querySelector('script')).toBeNull();
        expect(
            screen.getByText('<script>alert(1)</script>'),
        ).toBeInTheDocument();
    });

    it('does not render an img tag from a message body', () => {
        const { container } = render(
            <MessageText body={'<img src=x onerror="alert(1)">'} />,
        );

        // The classic sanitizer-bypass payload is simply characters here.
        expect(container.querySelector('img')).toBeNull();
        expect(container.textContent).toContain('<img src=x onerror=');
    });

    it('renders bold, italic, and strikethrough', () => {
        const { container } = render(
            <MessageText body="**bold** _italic_ ~~gone~~" />,
        );

        expect(container.querySelector('strong')).toHaveTextContent('bold');
        expect(container.querySelector('em')).toHaveTextContent('italic');
        expect(container.querySelector('s')).toHaveTextContent('gone');
    });

    it('renders inline code', () => {
        const { container } = render(<MessageText body="run `npm test` now" />);

        expect(container.querySelector('code')).toHaveTextContent('npm test');
    });

    it('renders a fenced code block', () => {
        const { container } = render(
            <MessageText body={'```js\nconst a = 1;\n```'} />,
        );

        expect(container.querySelector('pre code')).toHaveTextContent(
            'const a = 1;',
        );
    });

    it('links a url safely', () => {
        render(<MessageText body="see https://snack.test/docs" />);

        const link = screen.getByRole('link');

        expect(link).toHaveAttribute('href', 'https://snack.test/docs');
        // noopener stops the destination reaching back through window.opener;
        // noreferrer keeps the workspace URL out of the referrer header.
        expect(link).toHaveAttribute('rel', 'noopener noreferrer nofollow');
        expect(link).toHaveAttribute('target', '_blank');
    });

    it('does not create a link for a javascript url', () => {
        render(<MessageText body="javascript:alert(document.cookie)" />);

        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('preserves the author line breaks', () => {
        const { container } = render(<MessageText body={'one\ntwo'} />);

        expect(container.querySelectorAll('br')).toHaveLength(1);
    });

    it('renders a blockquote', () => {
        const { container } = render(<MessageText body="> quoted" />);

        expect(container.querySelector('blockquote')).toHaveTextContent(
            'quoted',
        );
    });

    it('renders a bulleted list as list items', () => {
        const { container } = render(<MessageText body={'- milk\n- eggs'} />);

        expect(container.querySelector('ul')).not.toBeNull();
        expect(container.querySelectorAll('li')).toHaveLength(2);
    });

    it('renders a numbered list as an ordered list', () => {
        const { container } = render(<MessageText body={'1. first'} />);

        expect(container.querySelector('ol')).toHaveTextContent('first');
        // Tailwind's reset hides the markers unless they are asked for back.
        expect(container.querySelector('ol')?.className).toContain(
            'list-decimal',
        );
    });
});

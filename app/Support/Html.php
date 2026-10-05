<?php

namespace App\Support;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Cleans HTML that a member of staff typed or pasted before it is stored and
 * shown to shoppers. A whitelist: only the handful of tags a product
 * description needs survive, with no attributes but a safe link.
 */
class Html
{
    /** @var array<string,array<int,string>> tag => attributes it may keep */
    protected const ALLOWED = [
        'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'blockquote' => [],
        'a' => ['href'],
    ];

    /** Removed together with everything inside them, rather than unwrapped. */
    protected const DROPPED = [
        'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form', 'input', 'button',
        'textarea', 'select', 'option', 'link', 'meta', 'base', 'template', 'noscript', 'audio',
        'video', 'source', 'canvas', 'frame', 'frameset', 'applet',
    ];

    public static function clean(?string $html): string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="clean-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('clean-root');

        if ($root === null) {
            return trim(strip_tags($html));
        }

        self::walk($root);

        $clean = '';

        foreach ($root->childNodes as $child) {
            $clean .= $dom->saveHTML($child);
        }

        return trim($clean);
    }

    /**
     * Text a person typed with no markup, as paragraphs: a blank line starts a
     * new one and a single line break stays a line break.
     */
    public static function paragraphs(?string $text): string
    {
        $text = trim((string) $text);

        if ($text === '') {
            return '';
        }

        return collect(preg_split('/\R{2,}/u', $text) ?: [])
            ->map(fn (string $block) => '<p>'.nl2br(e(trim($block)), false).'</p>')
            ->implode('');
    }

    protected static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                // Comments, processing instructions and the like carry nothing worth keeping.
                if ($child instanceof DOMComment || $child->nodeType === XML_PI_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                    $node->removeChild($child);
                }

                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROPPED, true)) {
                $node->removeChild($child);

                continue;
            }

            self::walk($child);

            if (! isset(self::ALLOWED[$tag])) {
                // An unknown tag goes, its text stays.
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }

                $node->removeChild($child);

                continue;
            }

            foreach (iterator_to_array($child->attributes) as $attribute) {
                if (! in_array(strtolower($attribute->name), self::ALLOWED[$tag], true)) {
                    $child->removeAttribute($attribute->name);
                }
            }

            if ($tag === 'a') {
                self::cleanLink($child);
            }
        }
    }

    protected static function cleanLink(DOMElement $link): void
    {
        $href = trim($link->getAttribute('href'));

        // Only web, mail and phone links. A scheme such as "javascript:" — even
        // one broken up with tabs or line breaks — matches none of these.
        if (preg_match('#^(https?://|mailto:|tel:|/(?!/))#i', $href) !== 1) {
            $link->removeAttribute('href');

            return;
        }

        $link->setAttribute('rel', 'noopener noreferrer');
    }
}

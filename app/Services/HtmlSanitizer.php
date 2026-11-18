<?php

declare(strict_types=1);

namespace App\Services;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMProcessingInstruction;

/**
 * Allowlist based HTML sanitizer.
 *
 * Deviation from the specification: mews/purifier is intentionally not used
 * so the project does not need an extra Composer dependency. This class
 * provides the equivalent narrow allowlist using PHP's DOM extension.
 */
final class HtmlSanitizer
{
    /** @var array<string, array<int, string>> */
    private const ALLOWED = [
        'p' => [],
        'br' => [],
        'strong' => [],
        'em' => [],
        'u' => [],
        'h2' => [],
        'h3' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'a' => ['href'],
        'blockquote' => [],
        'code' => [],
        'pre' => [],
        'img' => ['src', 'alt'],
    ];

    /** Elements removed together with their contents. */
    private const STRIP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math',
        'template', 'noscript', 'link', 'meta', 'base', 'title', 'head',
        'form', 'input', 'button', 'textarea', 'select', 'option',
        'video', 'audio', 'source', 'track', 'canvas', 'applet', 'frame',
        'frameset', 'portal',
    ];

    private const LINK_SCHEMES = ['http', 'https', 'mailto'];

    private const IMAGE_SCHEMES = ['http', 'https'];

    public static function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;
        $dom->preserveWhiteSpace = true;

        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $dom->loadHTML(
                '<?xml encoding="UTF-8"?><body>' . $html . '</body>',
                LIBXML_NONET
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $body = $dom->getElementsByTagName('body')->item(0);

        if (! $body instanceof DOMElement) {
            return '';
        }

        self::sanitizeChildren($body);

        $output = '';

        foreach ($body->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    private static function sanitizeChildren(DOMNode $parent): void
    {
        $children = [];

        foreach ($parent->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
                $parent->removeChild($child);

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            // Clean descendants before unwrapping so moved nodes are safe.
            self::sanitizeChildren($child);

            if (in_array($tag, self::STRIP_WITH_CONTENT, true)) {
                $parent->removeChild($child);

                continue;
            }

            if (! array_key_exists($tag, self::ALLOWED)) {
                self::unwrap($child);

                continue;
            }

            self::sanitizeAttributes($child, $tag);
        }
    }

    private static function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED[$tag];
        $remove = [];

        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->name);

            if (str_starts_with($name, 'on') || ! in_array($name, $allowed, true)) {
                $remove[] = $attribute;

                continue;
            }

            if ($name === 'href' || $name === 'src') {
                $url = preg_replace('/[\x00-\x1F\x7F]/', '', trim($attribute->value)) ?? '';
                $scheme = self::scheme($url);
                $schemes = $tag === 'img' ? self::IMAGE_SCHEMES : self::LINK_SCHEMES;

                if ($scheme !== null && ! in_array($scheme, $schemes, true)) {
                    $remove[] = $attribute;

                    continue;
                }

                $element->setAttribute($name, $url);
            }
        }

        foreach ($remove as $attribute) {
            $element->removeAttributeNode($attribute);
        }

        if ($tag === 'a' && $element->hasAttribute('href')) {
            $element->setAttribute('rel', 'noopener noreferrer nofollow');
        }
    }

    private static function scheme(string $url): ?string
    {
        return preg_match('/^\s*([a-z][a-z0-9+.-]*):/i', $url, $matches) === 1
            ? strtolower($matches[1])
            : null;
    }

    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if (! $parent) {
            return;
        }

        while ($element->firstChild) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }
}

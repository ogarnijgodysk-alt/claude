<?php
declare(strict_types=1);

namespace App;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Oczyszczanie HTML po stronie serwera (biała lista). Skrypty, ramki, style i
 * atrybuty zdarzeń nie przechodzą. Wywoływane przy zapisie i imporcie.
 */
final class Sanitizer
{
    private const ALLOWED = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'mark', 'sup', 'sub', 'small',
        'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'a', 'blockquote', 'hr',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
        'figure', 'figcaption', 'img', 'span', 'code', 'pre',
    ];
    /** Elementy usuwane razem z zawartością. */
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select',
        'textarea', 'link', 'meta', 'svg', 'math', 'noscript', 'template', 'frame', 'frameset', 'applet', 'audio', 'video', 'canvas', 'head', 'title'];
    private const ATTRS = [
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'title'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan'],
    ];

    public static function html(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? '';
        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?><div id="__root">' . $html . '</div>', LIBXML_NOENT | LIBXML_NONET | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('__root');
        if (!$root) {
            return e($html);
        }
        self::clean($root);
        $out = '';
        foreach ($root->childNodes as $ch) {
            $out .= $doc->saveHTML($ch);
        }
        return trim($out);
    }

    /** Czysty tekst (bez tagów) do pól typu tytuł. */
    public static function text(string $s, int $max = 300): string
    {
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        return mb_substr($s, 0, $max);
    }

    private static function clean(DOMNode $node): void
    {
        $children = [];
        foreach ($node->childNodes as $c) {
            $children[] = $c;
        }
        foreach ($children as $c) {
            if ($c instanceof DOMElement) {
                $tag = strtolower($c->tagName);
                if ($tag === 'h1') {
                    $new = $c->ownerDocument->createElement('h2');
                    while ($c->firstChild) {
                        $new->appendChild($c->firstChild);
                    }
                    $node->replaceChild($new, $c);
                    $c = $new;
                    $tag = 'h2';
                }
                if (in_array($tag, self::DROP, true)) {
                    $node->removeChild($c);
                    continue;
                }
                if (!in_array($tag, self::ALLOWED, true)) {
                    // rozpakuj: zostaw dzieci
                    self::clean($c);
                    while ($c->firstChild) {
                        $node->insertBefore($c->firstChild, $c);
                    }
                    $node->removeChild($c);
                    continue;
                }
                self::cleanAttrs($c, $tag);
                if ($tag === 'img' && !$c->hasAttribute('src')) {
                    $node->removeChild($c);
                    continue;
                }
                self::clean($c);
            } elseif ($c->nodeType === XML_COMMENT_NODE || $c->nodeType === XML_PI_NODE || $c->nodeType === XML_CDATA_SECTION_NODE) {
                $node->removeChild($c);
            }
        }
    }

    private static function cleanAttrs(DOMElement $el, string $tag): void
    {
        $allowed = self::ATTRS[$tag] ?? [];
        $names = [];
        foreach ($el->attributes as $a) {
            $names[] = $a->name;
        }
        foreach ($names as $n) {
            if (!in_array(strtolower($n), $allowed, true)) {
                $el->removeAttribute($n);
            }
        }
        if ($tag === 'a') {
            $href = self::safeUrl($el->getAttribute('href'), ['http', 'https', 'mailto', 'tel']);
            if ($href === null) {
                $el->removeAttribute('href');
            } else {
                $el->setAttribute('href', $href);
                if (preg_match('#^https?://#i', $href)) {
                    $el->setAttribute('target', '_blank');
                    $el->setAttribute('rel', 'noopener noreferrer');
                }
            }
        } elseif ($tag === 'img') {
            $src = self::safeUrl($el->getAttribute('src'), ['http', 'https']);
            if ($src === null) {
                $el->removeAttribute('src');
            } else {
                $el->setAttribute('src', $src);
                $el->setAttribute('loading', 'lazy');
            }
        } elseif ($tag === 'td' || $tag === 'th') {
            foreach (['colspan', 'rowspan'] as $a) {
                if ($el->hasAttribute($a)) {
                    $el->setAttribute($a, (string)max(1, min(50, (int)$el->getAttribute($a))));
                }
            }
        }
    }

    /** Zwraca bezpieczny URL albo null. Dozwolone też ścieżki względne (/…) i kotwice (#…). */
    public static function safeUrl(string $url, array $schemes = ['http', 'https']): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $url = preg_replace('/[\x00-\x20\x7F]+/', '', $url) ?? '';
        if ($url === '') {
            return null;
        }
        if ($url[0] === '#' || ($url[0] === '/' && ($url[1] ?? '') !== '/')) {
            return $url;
        }
        if (preg_match('#^([a-z][a-z0-9+.-]*):#i', $url, $m)) {
            return in_array(strtolower($m[1]), $schemes, true) ? $url : null;
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        return null;
    }

    public static function isEmptyHtml(string $html): bool
    {
        if (preg_match('/<(img|table|hr)\b/i', $html)) {
            return false;
        }
        $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(str_replace("\u{00A0}", ' ', $t)) === '';
    }
}

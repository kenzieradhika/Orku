<?php

declare(strict_types=1);

namespace Orku\Loom;

final class LoomRenderer
{
    private string $root;

    public function __construct(string $directory)
    {
        $root = realpath($directory);
        if ($root === false || !is_dir($root)) {
            throw new LoomException('Invalid template directory.');
        }
        $this->root = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
    }

    /** @param array<string, mixed> $data */
    public function render(string $name, array $data = []): string
    {
        return $this->renderTemplate($name, $data, []);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $layouts
     */
    private function renderTemplate(string $name, array $data, array $layouts, ?string $content = null): string
    {
        if (in_array($name, $layouts, true)) {
            throw new LoomException('Cyclic template layout.');
        }
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*(?:\.[a-zA-Z][a-zA-Z0-9_-]*)*$/D', $name)) {
            throw new LoomException('Invalid template name.');
        }
        $path = realpath($this->root.str_replace('.', DIRECTORY_SEPARATOR, $name).'.ork');
        if ($path === false || !str_starts_with($path, $this->root) || !is_file($path) || !is_readable($path)) {
            throw new LoomException('Template not found.');
        }

        $source = file_get_contents($path);
        if ($source === false || str_contains($source, '<?')) {
            throw new LoomException('Invalid template source.');
        }

        if (preg_match('/^\{% extends "([a-zA-Z][a-zA-Z0-9_.-]*)" %\}/', $source, $extends)) {
            $body = substr($source, strlen($extends[0]));
            if (!preg_match('/^\{% block content %\}(.*?)\{% endblock %\}$/s', $body, $block) || $block[0] !== $body) {
                throw new LoomException('Invalid layout content block.');
            }
            if ($content !== null) {
                throw new LoomException('Nested layout inheritance is unsupported.');
            }
            $child = $this->compile($block[1], $data);
            return $this->renderTemplate($extends[1], $data, [...$layouts, $name], $child);
        }
        if ($content !== null) {
            if (substr_count($source, '{% slot content %}') !== 1 || substr_count($source, '{% endslot %}') !== 1) {
                throw new LoomException('Layout must define exactly one content slot.');
            }
            $marker = "\0ORKU_LAYOUT_CONTENT\0";
            $source = preg_replace('/\{% slot content %\}.*?\{% endslot %\}/s', $marker, $source, 1) ?? $source;
        } elseif (str_contains($source, '{%')) {
            throw new LoomException('Unsupported template directive.');
        }
        $html = $this->compile($source, $data);
        return $content === null ? $html : str_replace("\0ORKU_LAYOUT_CONTENT\0", $content, $html);
    }

    /** @param array<string, mixed> $data */
    private function compile(string $source, array $data): string
    {
        $output = '';
        $offset = 0;
        $state = 'text';
        $quote = '';
        $raw = '';
        $tagStart = 0;
        $length = strlen($source);

        while ($offset < $length) {
            if (substr($source, $offset, 2) === '{{' && $state !== 'text') {
                throw new LoomException('Interpolation is only allowed in HTML text.');
            }
            if ($state === 'text' && str_starts_with(substr($source, $offset), '{{')) {
                $end = strpos($source, '}}', $offset + 2);
                if ($end === false) {
                    throw new LoomException('Unclosed interpolation.');
                }
                $expression = trim(substr($source, $offset + 2, $end - $offset - 2));
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)*$/D', $expression)) {
                    throw new LoomException('Unsupported interpolation.');
                }
                $value = $data;
                foreach (explode('.', $expression) as $key) {
                    if (!is_array($value) || !array_key_exists($key, $value)) {
                        throw new LoomException('Missing template value.');
                    }
                    $value = $value[$key];
                }
                if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value) && $value !== null) {
                    throw new LoomException('Template value must be scalar or null.');
                }
                $output .= htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
                $offset = $end + 2;
                continue;
            }
            if ($state === 'text' && substr($source, $offset, 2) === '}}') {
                throw new LoomException('Unexpected interpolation delimiter.');
            }

            if ($state === 'text' && substr($source, $offset, 4) === '<!--') {
                $end = strpos($source, '-->', $offset + 4);
                if ($end === false) {
                    throw new LoomException('Unclosed HTML comment.');
                }
                if (str_contains(substr($source, $offset, $end - $offset), '{{')) {
                    throw new LoomException('Interpolation is only allowed in HTML text.');
                }
                $output .= substr($source, $offset, $end + 3 - $offset);
                $offset = $end + 3;
                continue;
            }

            $char = $source[$offset];
            $output .= $char;
            if ($state === 'text' && $char === '<') {
                $state = 'tag';
                $quote = '';
                $tagStart = $offset;
            } elseif ($state === 'tag') {
                if ($quote !== '') {
                    if ($char === $quote) {
                        $quote = '';
                    }
                } elseif ($char === '"' || $char === "'") {
                    $quote = $char;
                } elseif ($char === '>') {
                    $tag = substr($source, $tagStart, $offset + 1 - $tagStart);
                    if (preg_match('/^<\s*(script|style)\b/i', trim($tag), $match)) {
                        $raw = strtolower($match[1]);
                        $state = 'raw';
                    } else {
                        $state = 'text';
                    }
                }
            } elseif ($state === 'raw' && $char === '<' && preg_match('/^<\/\s*'.preg_quote($raw, '/').'\s*>/i', substr($source, $offset), $match)) {
                $output .= substr($source, $offset + 1, strlen($match[0]) - 1);
                $offset += strlen($match[0]) - 1;
                $state = 'text';
            }
            ++$offset;
        }

        if ($state === 'tag') {
            throw new LoomException('Unclosed HTML tag.');
        }

        return $output;
    }
}

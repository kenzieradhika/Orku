<?php

declare(strict_types=1);

namespace Orku\Loom;

final class LoomRenderer
{
    private const MAX_LOOP_DEPTH = 8;
    private const MAX_LOOP_ITERATIONS = 1000;

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
        $budget = self::MAX_LOOP_ITERATIONS;
        return $this->renderTemplate($name, $data, [], null, $budget, 0);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $layouts
     */
    private function renderTemplate(string $name, array $data, array $layouts, ?string $content, int &$iterBudget, int $loopDepth): string
    {
        if (in_array($name, $layouts, true)) {
            throw new LoomException('Cyclic template reference.');
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

        if (preg_match('/^\{% extends "([a-zA-Z][a-zA-Z0-9_-]*(?:\.[a-zA-Z][a-zA-Z0-9_-]*)*)" %\}/', $source, $extends)) {
            $body = substr($source, strlen($extends[0]));
            if (!preg_match('/^\{% block content %\}(.*?)\{% endblock %\}$/s', $body, $block) || $block[0] !== $body) {
                throw new LoomException('Invalid layout content block.');
            }
            if ($content !== null) {
                throw new LoomException('Nested layout inheritance is unsupported.');
            }
            $child = $this->compile($block[1], $data, [...$layouts, $name], null, $iterBudget, $loopDepth);
            return $this->renderTemplate($extends[1], $data, [...$layouts, $name], $child, $iterBudget, $loopDepth);
        }
        if ($content !== null) {
            if (substr_count($source, '{% slot content %}') !== 1 || substr_count($source, '{% endslot %}') !== 1) {
                throw new LoomException('Layout must define exactly one content slot.');
            }
            $marker = "\0ORKU_LAYOUT_CONTENT\0";
            $source = preg_replace('/\{% slot content %\}.*?\{% endslot %\}/s', $marker, $source, 1) ?? $source;
        }
        $html = $this->compile($source, $data, [...$layouts, $name], null, $iterBudget, $loopDepth);
        return $content === null ? $html : str_replace("\0ORKU_LAYOUT_CONTENT\0", $content, $html);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $layouts
     */
    private function compile(
        string $source,
        array $data,
        array $layouts = [],
        ?string $slotContent = null,
        int &$iterBudget = self::MAX_LOOP_ITERATIONS,
        int $loopDepth = 0,
    ): string {
        $output = '';
        $offset = 0;
        $state = 'text';
        $quote = '';
        $raw = '';
        $tagStart = 0;
        $length = strlen($source);

        while ($offset < $length) {
            if (substr($source, $offset, 2) === '{%' && $state !== 'text') {
                throw new LoomException('Template directive is only allowed in HTML text.');
            }
            if ($state === 'text' && substr($source, $offset, 2) === '{%') {
                if (preg_match('/^\{% for ([a-zA-Z_][a-zA-Z0-9_]*) in ([a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)*) %\}/', substr($source, $offset), $loop)) {
                    $iterVar = $loop[1];
                    $collectionPath = $loop[2];
                    [$body, $end] = $this->loopBody($source, $offset + strlen($loop[0]));
                    if ($loopDepth >= self::MAX_LOOP_DEPTH) {
                        throw new LoomException('Maximum loop nesting depth exceeded.');
                    }
                    $collection = $data;
                    foreach (explode('.', $collectionPath) as $key) {
                        if (!is_array($collection) || !array_key_exists($key, $collection)) {
                            throw new LoomException('Missing loop collection.');
                        }
                        $collection = $collection[$key];
                    }
                    if (!is_array($collection)) {
                        throw new LoomException('Loop collection must be an array.');
                    }
                    if (!array_is_list($collection)) {
                        throw new LoomException('Loop collection must be a list.');
                    }
                    foreach ($collection as $item) {
                        if (--$iterBudget < 0) {
                            throw new LoomException('Maximum loop iteration budget exceeded.');
                        }
                        $iterData = array_merge($data, [$iterVar => $item]);
                        $output .= $this->compile($body, $iterData, $layouts, null, $iterBudget, $loopDepth + 1);
                    }
                    $offset = $end;
                    continue;
                }
                if (preg_match('/^\{% if ([^%]+) %\}/', substr($source, $offset), $condition)) {
                    [$branches, $end] = $this->conditionalBranches($source, $offset + strlen($condition[0]));
                    $expression = trim($condition[1]);
                    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)*$/D', $expression)) {
                        throw new LoomException('Invalid conditional expression.');
                    }
                    $value = $data;
                    foreach (explode('.', $expression) as $key) {
                        if (!is_array($value) || !array_key_exists($key, $value)) {
                            throw new LoomException('Missing conditional value.');
                        }
                        $value = $value[$key];
                    }
                    if (!is_bool($value)) {
                        throw new LoomException('Conditional value must be boolean.');
                    }
                    if ($value) {
                        $output .= $this->compile($branches[0], $data, $layouts, null, $iterBudget, $loopDepth);
                    } elseif (isset($branches[1])) {
                        $output .= $this->compile($branches[1], $data, $layouts, null, $iterBudget, $loopDepth);
                    }
                    $offset = $end;
                    continue;
                }
                if (preg_match('/^\{% slot content %\}/', substr($source, $offset), $slot)) {
                    if ($slotContent === null) {
                        throw new LoomException('Unexpected content slot.');
                    }
                    $output .= $slotContent;
                    $offset += strlen($slot[0]);
                    continue;
                }
                if (preg_match('/^\{% component "([^"]+)"([^}]*) %\}/', substr($source, $offset), $component)) {
                    [$body, $end] = $this->componentBody($source, $offset + strlen($component[0]));
                    $output .= $this->renderComponent($component[1], trim($component[2]), $body, $data, $layouts, $iterBudget, $loopDepth);
                    $offset = $end;
                    continue;
                }
                if (!preg_match('/^\{% include "([a-zA-Z][a-zA-Z0-9_-]*(?:\.[a-zA-Z][a-zA-Z0-9_-]*)*)" %\}/', substr($source, $offset), $include)) {
                    throw new LoomException('Unsupported template directive.');
                }
                $output .= $this->renderTemplate($include[1], $data, $layouts, null, $iterBudget, $loopDepth);
                $offset += strlen($include[0]);
                continue;
            }
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
                $comment = substr($source, $offset, $end - $offset);
                if (str_contains($comment, '{{') || str_contains($comment, '{%')) {
                    throw new LoomException('Template syntax is not allowed in HTML comments.');
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
            } elseif ($state === 'raw' && $char === '<' && preg_match('/^<\/\s*'.preg_quote($raw, '/').'\\s*>/i', substr($source, $offset), $match)) {
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

    /** @return array{list<string>, int} */
    private function conditionalBranches(string $source, int $offset): array
    {
        $branches = [];
        $start = $offset;
        $elseSeen = [false];
        while (preg_match('/\{%\s*(if\b[^%]*|else|endif)\s*%\}/', $source, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $offset = $match[0][1] + strlen($match[0][0]);
            $directive = $match[1][0];
            if (str_starts_with($directive, 'if')) {
                if (count($elseSeen) >= 32) {
                    throw new LoomException('Maximum conditional depth exceeded.');
                }
                $elseSeen[] = false;
            } elseif ($directive === 'else') {
                $depth = array_key_last($elseSeen);
                if ($elseSeen[$depth]) {
                    throw new LoomException('Duplicate conditional else.');
                }
                $elseSeen[$depth] = true;
                if ($depth === 0) {
                    $branches[] = substr($source, $start, $match[0][1] - $start);
                    $start = $offset;
                }
            } elseif ($directive === 'endif') {
                array_pop($elseSeen);
                if ($elseSeen === []) {
                    $branches[] = substr($source, $start, $match[0][1] - $start);
                    return [$branches, $offset];
                }
            }
        }
        throw new LoomException('Unclosed conditional block.');
    }

    /** @return array{string, int} */
    private function loopBody(string $source, int $offset): array
    {
        $start = $offset;
        $depth = 1;
        while (preg_match('/\{%\s*(for\s+[a-zA-Z_][a-zA-Z0-9_]*\s+in\s+[a-zA-Z_][a-zA-Z0-9_.]*|endfor)\s*%\}/', $source, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $offset = $match[0][1] + strlen($match[0][0]);
            if (str_starts_with(trim($match[1][0]), 'for ')) {
                ++$depth;
            } elseif (--$depth === 0) {
                return [substr($source, $start, $match[0][1] - $start), $offset];
            }
        }
        throw new LoomException('Unclosed loop block.');
    }

    /** @return array{string, int} */
    private function componentBody(string $source, int $offset): array
    {
        $start = $offset;
        $depth = 1;
        while (preg_match('/\{%\s*(component\b[^%]*|endcomponent)\s*%\}/', $source, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $offset = $match[0][1] + strlen($match[0][0]);
            if (str_starts_with($match[1][0], 'component')) {
                ++$depth;
            } elseif (--$depth === 0) {
                return [substr($source, $start, $match[0][1] - $start), $offset];
            }
        }
        throw new LoomException('Unclosed component block.');
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $layouts
     */
    private function renderComponent(string $name, string $bindings, string $slot, array $data, array $layouts, int &$iterBudget, int $loopDepth): string
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*(?:\.[a-zA-Z][a-zA-Z0-9_-]*)*$/D', $name)) {
            throw new LoomException('Invalid component name.');
        }
        $reference = 'component:'.$name;
        if (in_array($reference, $layouts, true)) {
            throw new LoomException('Cyclic component reference.');
        }
        if (count(array_filter($layouts, static fn (string $item): bool => str_starts_with($item, 'component:'))) >= 32) {
            throw new LoomException('Maximum component depth exceeded.');
        }
        if ($bindings !== '' && !preg_match('/^(?:[a-zA-Z_][a-zA-Z0-9_]*=[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)*(?: [a-zA-Z_][a-zA-Z0-9_]*=[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)*)*)$/D', $bindings)) {
            throw new LoomException('Invalid component property expression.');
        }
        $path = realpath($this->root.'components'.DIRECTORY_SEPARATOR.str_replace('.', DIRECTORY_SEPARATOR, $name).'.ork');
        if ($path === false || !str_starts_with($path, $this->root) || !is_file($path) || !is_readable($path)) {
            throw new LoomException('Component not found.');
        }
        $source = file_get_contents($path);
        if ($source === false || str_contains($source, '<?')) {
            throw new LoomException('Invalid template source.');
        }
        if (!preg_match('/^\{% props(?: ([a-zA-Z_][a-zA-Z0-9_]*(?: [a-zA-Z_][a-zA-Z0-9_]*)*))? %\}/', $source, $props)) {
            throw new LoomException('Component properties declaration required.');
        }
        $declared = !empty($props[1]) ? explode(' ', $props[1]) : [];
        if (count($declared) !== count(array_unique($declared))) {
            throw new LoomException('Duplicate component property declaration.');
        }
        $bound = [];
        if ($bindings) {
            foreach (explode(' ', $bindings) as $binding) {
                [$key, $expression] = explode('=', $binding, 2);
                if (array_key_exists($key, $bound)) {
                    throw new LoomException('Duplicate component property.');
                }
                if (!in_array($key, $declared, true)) {
                    throw new LoomException('Unknown component property.');
                }
                $value = $data;
                foreach (explode('.', $expression) as $part) {
                    if (!is_array($value) || !array_key_exists($part, $value)) {
                        throw new LoomException('Missing component property value.');
                    }
                    $value = $value[$part];
                }
                if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value) && $value !== null) {
                    throw new LoomException('Component property must be scalar or null.');
                }
                $bound[$key] = $value;
            }
        }
        if (array_diff($declared, array_keys($bound)) !== []) {
            throw new LoomException('Missing component property.');
        }
        $source = substr($source, strlen($props[0]));
        $slots = substr_count($source, '{% slot content %}') + substr_count($source, '{% endslot %}');
        if ($slots !== 0 && ($slots !== 2 || !preg_match('/\{% slot content %\}.*?\{% endslot %\}/s', $source))) {
            throw new LoomException('Invalid component content slot.');
        }
        if ($slots === 2) {
            $source = preg_replace('/\{% slot content %\}.*?\{% endslot %\}/s', '{% slot content %}', $source, 1) ?? $source;
        }
        $componentStack = [...$layouts, $reference];
        $renderedSlot = $this->compile($slot, $data, $componentStack, null, $iterBudget, $loopDepth);
        if ($slots === 0 && trim($slot) !== '') {
            throw new LoomException('Component does not accept content.');
        }
        return $this->compile($source, $bound, $componentStack, $renderedSlot, $iterBudget, $loopDepth);
    }
}

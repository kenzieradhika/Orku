<?php

declare(strict_types=1);

namespace Orku\Web;

final readonly class ViewContext
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, TrustedHtml> $slots
     */
    public function __construct(private ViewRenderer $renderer, private array $data, private array $slots = [])
    {
    }

    public function get(string $name): mixed
    {
        if (!array_key_exists($name, $this->data)) {
            throw new WebException('Missing template prop.');
        }

        return $this->data[$name];
    }

    public function escape(mixed $value): string
    {
        if (!is_scalar($value) && $value !== null) {
            throw new WebException('Escape accepts scalar values or null.');
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
    }

    public function string(string $name): string
    {
        $value = $this->get($name);
        if (!is_string($value)) {
            throw new WebException('Expected string prop.');
        }

        return $value;
    }

    public function url(string $url): string
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]|%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i', $url) || (!str_starts_with($url, '/') && !preg_match('~^https?://~i', $url)) || str_starts_with($url, '//')) {
            throw new WebException('Unsafe URL.');
        }
        if (!str_starts_with($url, '/') && filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new WebException('Invalid URL.');
        }

        return $this->escape($url);
    }

    public function raw(TrustedHtml $html): string
    {
        return $html->html;
    }

    public function slot(string $name): TrustedHtml
    {
        return $this->slots[$name] ?? TrustedHtml::fromTrustedString('');
    }

    /** @param array<string, mixed> $data */
    public function partial(string $name, array $data = []): string
    {
        return $this->renderer->render($name, $data);
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, TrustedHtml> $slots
     */
    public function component(string $name, array $props = [], array $slots = []): string
    {
        return $this->renderer->render('components.'.$name, $props, $slots);
    }
}

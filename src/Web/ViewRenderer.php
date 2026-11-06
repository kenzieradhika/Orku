<?php

declare(strict_types=1);

namespace Orku\Web;

final class ViewRenderer
{
    /** @var list<string> */
    private array $roots = [];
    /** @var array<string, string> */
    private array $paths = [];

    /** @param list<string> $directories */
    public function __construct(array $directories)
    {
        if ($directories === []) {
            throw new WebException('Configure at least one template directory.');
        }
        foreach ($directories as $directory) {
            $root = realpath($directory);
            if ($root === false || !is_dir($root)) {
                throw new WebException('Invalid template directory.');
            }
            $this->roots[] = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, TrustedHtml> $slots
     */
    public function render(string $name, array $data = [], array $slots = []): string
    {
        $path = $this->resolve($name);
        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $__path, ViewContext $view): void {
                require $__path;
            })($path, new ViewContext($this, $data, $slots));
            if (ob_get_level() !== $level + 1) {
                throw new WebException('Template changed output buffer depth.');
            }
            $html = ob_get_contents();
            if ($html === false) {
                throw new WebException('Template output unavailable.');
            }

            return $html;
        } catch (\Throwable $error) {
            throw new WebException('Template rendering failed.', previous: $error);
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, TrustedHtml> $slots
     */
    public function layout(string $layout, string $page, array $data = [], array $slots = []): string
    {
        return $this->render($layout, $data, ['content' => TrustedHtml::fromTrustedString($this->render($page, $data))] + $slots);
    }

    private function resolve(string $name): string
    {
        if (strlen($name) > 200 || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*(?:\.[a-zA-Z][a-zA-Z0-9_-]*)*$/D', $name) || str_ends_with(strtolower($name), '.php')) {
            throw new WebException('Invalid template name.');
        }
        if (isset($this->paths[$name])) {
            return $this->paths[$name];
        }
        foreach ($this->roots as $root) {
            $path = realpath($root.str_replace('.', DIRECTORY_SEPARATOR, $name).'.php');
            if ($path !== false && str_starts_with($path, $root) && is_file($path) && is_readable($path)) {
                return $this->paths[$name] = $path;
            }
        }
        throw new WebException('Template not found.');
    }
}

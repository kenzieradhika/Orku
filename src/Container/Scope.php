<?php

declare(strict_types=1);

namespace Orku\Container;

final class Scope
{
    /** @var array<string, object> */
    private array $instances = [];
    /** @var list<array{object, \Closure(object): void}> */
    private array $releases = [];
    private bool $closed = false;
    /** @var list<string> */
    private array $constructing = [];

    /** @internal */
    public function __construct(private readonly Container $container, private readonly bool $scoped = true)
    {
    }

    public function get(string $id): object
    {
        return $this->resolve($id, false);
    }

    private function resolve(string $id, bool $singletonOnly): object
    {
        if ($this->closed) {
            throw new \LogicException('Scope is closed.');
        }
        $definition = $this->container->definition($id);
        $lifetime = $definition['lifetime'];
        if (($singletonOnly && $lifetime !== 'singleton') || (!$this->scoped && $lifetime === 'request')) {
            throw new \LogicException('Invalid lifetime dependency: '.implode(' > ', [...$this->constructing, $id]));
        }
        if (in_array($id, $this->constructing, true)) {
            throw new \LogicException('Circular dependency: '.implode(' > ', [...$this->constructing, $id]));
        }
        if ($this->scoped && $lifetime === 'singleton') {
            return $this->container->get($id);
        }
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (!$this->scoped && $definition['release'] !== null) {
            throw new \LogicException('Cleanup requires an explicit scope.');
        }
        $this->constructing[] = $id;
        try {
            $resolver = new Resolver(fn (string $dependency): object => $this->resolve($dependency, $singletonOnly || $lifetime === 'singleton'));
            $value = ($definition['factory'])($resolver);
            if (!is_object($value)) {
                throw new \TypeError('Service factory must return an object.');
            }
        } finally {
            array_pop($this->constructing);
        }
        if ($lifetime !== 'transient') {
            $this->instances[$id] = $value;
        }
        if ($definition['release'] !== null) {
            $this->releases[] = [$value, $definition['release']];
        }

        return $value;
    }

    public function close(): void
    {
        if ($this->constructing !== []) {
            throw new \LogicException('Cannot close scope during service construction.');
        }
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $errors = [];
        try {
            foreach (array_reverse($this->releases) as [$value, $release]) {
                try {
                    $release($value);
                } catch (\Throwable $error) {
                    $errors[] = $error;
                }
            }
        } finally {
            $this->instances = [];
            $this->releases = [];
        }
        if ($errors !== []) {
            throw new CleanupException($errors);
        }
    }
}

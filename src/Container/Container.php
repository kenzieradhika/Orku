<?php

declare(strict_types=1);

namespace Orku\Container;

final class Container
{
    /** @var array<string, array{factory: \Closure(Resolver): mixed, lifetime: string, release: null|\Closure(object): void}> */
    private array $definitions = [];
    private bool $frozen = false;
    private readonly Scope $root;

    public function __construct()
    {
        $this->root = new Scope($this, false);
    }

    /**
     * @param callable(Resolver): object $factory
     * @param null|callable(object): void $release
     */
    public function bind(string $id, callable $factory, string $lifetime = 'transient', ?callable $release = null): void
    {
        if ($id === '' || !in_array($lifetime, ['singleton', 'request', 'transient'], true) || ($lifetime === 'singleton' && $release !== null)) {
            throw new \InvalidArgumentException('Invalid service registration.');
        }
        if ($this->frozen || isset($this->definitions[$id])) {
            throw new \LogicException('Registrations frozen or service already registered.');
        }
        $this->definitions[$id] = ['factory' => \Closure::fromCallable($factory), 'lifetime' => $lifetime, 'release' => $release === null ? null : \Closure::fromCallable($release)];
    }

    public function get(string $id): object
    {
        return $this->root->get($id);
    }

    public function scope(): Scope
    {
        $this->frozen = true;

        return new Scope($this);
    }

    /**
     * @internal
     * @return array{factory: \Closure(Resolver): mixed, lifetime: string, release: null|\Closure(object): void}
     */
    public function definition(string $id): array
    {
        $this->frozen = true;

        return $this->definitions[$id] ?? throw new \LogicException('Unknown service: '.$id);
    }
}

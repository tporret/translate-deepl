<?php
declare(strict_types=1);

namespace TranslateDeepL\Core;

if (! defined('ABSPATH')) {
    exit;
}

use Closure;
use TranslateDeepL\Core\Exception\ContainerException;
use TranslateDeepL\Core\Exception\NotFoundException;
use Psr\Container\ContainerInterface;

final class Container implements ContainerInterface
{
    /**
     * @var array<string, Closure(self): mixed>
     */
    private array $bindings = [];

    /**
     * @var array<string, object>
     */
    private array $instances = [];

    /**
     * @var array<string, bool>
     */
    private array $singletons = [];

    public function set(string $id, Closure $factory, bool $singleton = false): void
    {
        $this->bindings[$id] = $factory;

        if ($singleton) {
            $this->singletons[$id] = true;
        }
    }

    public function singleton(string $id, Closure $factory): void
    {
        $this->set($id, $factory, true);
    }

    public function has(string $id): bool
    {
        return isset($this->bindings[$id]) || isset($this->instances[$id]);
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     */
    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (! isset($this->bindings[$id])) {
            throw new NotFoundException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not direct browser output.
                sprintf('Service "%s" is not registered.', $id)
            );
        }

        try {
            $instance = ($this->bindings[$id])($this);
        } catch (\Throwable $throwable) {
            throw new ContainerException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not direct browser output.
                sprintf('Unable to resolve service "%s".', $id),
                previous: $throwable
            );
        }

        if (isset($this->singletons[$id])) {
            if (! is_object($instance)) {
                throw new ContainerException(
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not direct browser output.
                    sprintf('Singleton service "%s" must resolve to an object.', $id)
                );
            }

            $this->instances[$id] = $instance;
        }

        return $instance;
    }
}

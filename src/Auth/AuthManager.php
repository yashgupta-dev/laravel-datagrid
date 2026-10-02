<?php

namespace CodeCorner\Datagrid\Auth;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class AuthManager
{
    private ?Authorizer $resolved = null;

    public function __construct(private Container $app, private array $config) {}

    public function driverName(): string
    {
        return (string) ($this->config['driver'] ?? 'none');
    }

    public function authorizer(): Authorizer
    {
        return $this->resolved ??= match ($this->driverName()) {
            'none' => new NoneAuthorizer,
            'gate' => new GateAuthorizer,
            'callback' => new CallbackAuthorizer($this->requireCallback()),
            'custom' => $this->app->make($this->config['authorizer']
                ?? throw new InvalidArgumentException('datagrid.authorization.authorizer is required for the custom driver.')),
            default => throw new InvalidArgumentException("Unknown datagrid authorization driver [{$this->driverName()}]."),
        };
    }

    /**
     * Evaluate an ability declaration.
     *
     * - null        : no rule declared -> convention name if enabled (and a real driver is used), else allowed
     * - Closure     : explicit developer rule, called with ($actor, $context)
     * - string/enum : delegated to the configured Authorizer
     */
    public function allows(mixed $actor, mixed $ability, AuthContext $context, ?Authorizer $override = null): bool
    {
        if ($ability === null) {
            if ($this->driverName() === 'none' || empty($this->config['conventions']) || $context->key === null) {
                return true;
            }
            $ability = "{$context->grid}.{$context->key}.view";
        }

        if ($ability instanceof Closure) {
            return (bool) $ability($actor, $context);
        }

        if ($ability instanceof BackedEnum) {
            $ability = (string) $ability->value;
        }

        return ($override ?? $this->authorizer())->allows($actor, (string) $ability, $context);
    }

    private function requireCallback(): Closure
    {
        $cb = $this->config['callback'] ?? null;

        return $cb instanceof Closure
            ? $cb
            : throw new InvalidArgumentException('datagrid.authorization.callback must be a Closure for the callback driver.');
    }
}

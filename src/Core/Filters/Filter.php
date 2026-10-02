<?php

namespace CodeCorner\Datagrid\Core\Filters;

use BackedEnum;
use Closure;
use CodeCorner\Datagrid\Core\Operators\OperatorRegistry;
use Illuminate\Support\Str;

class Filter
{
    protected array|string|Closure|null $options = null;

    protected bool $multiple = false;

    /** @var list<string>|null */
    protected ?array $operators = null;

    protected ?string $defaultOperator = null;

    protected bool $quick = false;

    final public function __construct(protected string $type) {}

    public static function text(): static
    {
        return new static('text');
    }

    public static function number(): static
    {
        return new static('number');
    }

    public static function date(): static
    {
        return new static('date');
    }

    public static function boolean(): static
    {
        return new static('boolean');
    }

    public static function select(): static
    {
        return new static('select');
    }

    public static function relation(): static
    {
        return new static('relation');
    }

    /** @param array<int|string, mixed>|class-string<BackedEnum>|Closure $options */
    public function options(array|string|Closure $options): static
    {
        $this->options = $options;

        return $this;
    }

    public function multiple(bool $multiple = true): static
    {
        $this->multiple = $multiple;

        return $this;
    }

    /** @param list<string> $operators */
    public function operators(array $operators): static
    {
        $this->operators = $operators;

        return $this;
    }

    public function defaultOperator(string $operator): static
    {
        $this->defaultOperator = $operator;

        return $this;
    }

    public function quick(bool $quick = true): static
    {
        $this->quick = $quick;

        return $this;
    }

    public function type(): string
    {
        return $this->type;
    }

    /** @return list<string> */
    public function allowedOperators(OperatorRegistry $registry): array
    {
        if ($this->operators !== null) {
            return $this->operators;
        }

        $ops = $registry->forFilterType($this->type);

        // A multiselect should offer list operators first.
        if ($this->type === 'select' && $this->multiple) {
            return ['in', 'not_in', 'is_null', 'not_null'];
        }

        return $ops;
    }

    /** @return list<array{value: mixed, label: string}> */
    public function resolveOptions(): array
    {
        $raw = $this->options instanceof Closure ? ($this->options)() : $this->options;

        if ($raw === null) {
            return [];
        }

        if (is_string($raw) && enum_exists($raw)) {
            return array_map(fn ($case) => [
                'value' => $case instanceof BackedEnum ? $case->value : $case->name,
                'label' => method_exists($case, 'label') ? $case->label() : Str::headline($case->name),
            ], $raw::cases());
        }

        $out = [];
        foreach ((array) $raw as $key => $label) {
            $isList = array_is_list((array) $raw);
            $out[] = ['value' => $isList ? $label : $key, 'label' => (string) $label];
        }

        return $out;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function toSchema(OperatorRegistry $registry): array
    {
        $ops = $this->allowedOperators($registry);
        $options = $this->resolveOptions();

        return [
            'type' => $this->type,
            'multiple' => $this->multiple,
            'operators' => $ops,
            'defaultOperator' => $this->defaultOperator ?? ($ops[0] ?? null),
            'quick' => $this->quick,
            'options' => $options === [] ? null : $options,
            'optionsUrl' => null,
        ];
    }
}

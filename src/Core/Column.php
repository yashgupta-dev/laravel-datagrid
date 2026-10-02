<?php

namespace CodeCorner\Datagrid\Core;

use Closure;
use CodeCorner\Datagrid\Core\Filters\Filter;
use CodeCorner\Datagrid\Core\Operators\OperatorRegistry;
use CodeCorner\Datagrid\Exceptions\InvalidGridRequest;
use Illuminate\Support\Str;

class Column
{
    protected string $label;

    protected string $type = 'text';

    protected bool $sortable = false;

    protected bool $searchable = false;

    protected bool $visible = true;

    protected bool $toggleable = true;

    protected bool $hidden = false;

    protected bool $exportable = true;

    protected bool $importable = true;

    protected ?Filter $filter = null;

    protected ?string $relation = null;

    protected ?string $attribute = null;

    /** @var array{relation: string, fn: string, column: string}|null */
    protected ?array $aggregate = null;

    protected ?string $computed = null;

    protected ?Closure $using = null;

    protected ?Closure $filterUsing = null;

    protected ?Closure $exportAs = null;

    protected mixed $ability = null;

    protected mixed $editAbility = null;

    protected ?string $pinned = null;

    protected ?int $width = null;

    protected ?string $defaultSort = null;

    protected ?string $description = null;

    protected bool $copyable = false;

    protected bool $editable = false;

    /** @var string|array<int, mixed>|null */
    protected string|array|null $editRules = null;

    /** @var list<array{value: string, label: string}> */
    protected array $options = [];

    protected bool $multiple = false;

    protected ?int $imageWidth = null;

    protected ?int $imageHeight = null;

    final public function __construct(protected string $key)
    {
        $this->label = Str::headline(Str::afterLast($key, '.'));
    }

    public static function make(string $key): static
    {
        return new static($key);
    }

    // ---- identity & data source -------------------------------------------------

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function type(string $type): static
    {
        $this->type = $type;
        if ($this->isSensitive()) {
            $this->hidden = true;
            $this->visible = false;
            $this->sortable = false;
            $this->searchable = false;
            $this->exportable = false;
            $this->importable = false;
            $this->copyable = false;
            $this->editable = false;
            $this->editRules = null;
        }

        return $this;
    }

    public function description(string $text): static
    {
        $this->description = $text;

        return $this;
    }

    /** Real DB column when it differs from the key. */
    public function attribute(string $attribute): static
    {
        $this->attribute = $attribute;

        return $this;
    }

    /** Value comes from a to-one (BelongsTo/HasOne) or to-many relation of the grid model. */
    public function relation(string $relation): static
    {
        $this->relation = $relation;

        return $this;
    }

    /** Subselect aggregate, e.g. ->aggregate('orders', 'sum', 'amount'). */
    public function aggregate(string $relation, string $fn, string $column): static
    {
        if (! in_array($fn, ['count', 'sum', 'avg', 'min', 'max', 'exists'], true)) {
            throw new \InvalidArgumentException("Unsupported aggregate [{$fn}].");
        }
        $this->aggregate = ['relation' => $relation, 'fn' => $fn, 'column' => $column];

        return $this;
    }

    /** Raw SQL expression. Developer-defined only; never build it from request input. */
    public function computed(string $sql): static
    {
        $this->computed = $sql;

        return $this;
    }

    /** Custom display value: fn (Model $row): mixed. */
    public function using(Closure $callback): static
    {
        $this->using = $callback;

        return $this;
    }

    // ---- capabilities -----------------------------------------------------------

    public function sortable(bool $sortable = true): static
    {
        $this->sortable = $sortable;

        return $this;
    }

    public function searchable(bool $searchable = true): static
    {
        $this->searchable = $searchable;

        return $this;
    }

    public function visible(bool $visible = true): static
    {
        $this->visible = $visible;

        return $this;
    }

    public function toggleable(bool $toggleable = true): static
    {
        $this->toggleable = $toggleable;

        return $this;
    }

    /** Never sent to the client; still usable for filtering/export. */
    public function hidden(bool $hidden = true): static
    {
        $this->hidden = $hidden;

        return $this;
    }

    public function exportable(bool|Closure $exportable = true): static
    {
        if ($exportable instanceof Closure) {
            $this->exportAs = $exportable;
            $this->exportable = true;
        } else {
            $this->exportable = $exportable;
        }

        return $this;
    }

    public function importable(bool $importable = true): static
    {
        $this->importable = $importable;

        return $this;
    }

    /** Show a control that copies the displayed cell value. */
    public function copy(bool $copy = true): static
    {
        $this->copyable = $copy;

        return $this;
    }

    /**
     * Allow inline edit. Rules stay on the server.
     * Only direct, visible columns can be edited.
     *
     * @param  string|array<int, mixed>  $rules
     */
    public function editable(string|array $rules): static
    {
        $this->editable = true;
        $this->editRules = $rules;

        return $this;
    }

    /**
     * Values this column is allowed to hold. The cell menu offers these labels,
     * and the server rejects anything outside the list.
     *
     * @param  array<int|string, scalar>  $options  stored value => label
     */
    public function options(array $options): static
    {
        $normalized = [];
        $listed = array_is_list($options);
        foreach ($options as $value => $label) {
            if ($listed) {
                $value = $label;
                $label = Str::headline((string) $label);
            }
            if (! is_string($value) && ! is_int($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '' || strlen($value) > 100 || preg_match('/[\x00-\x1F\x7F]/', $value) || isset($normalized[$value])) {
                continue;
            }
            if (count($normalized) >= 50) {
                break;
            }
            $normalized[$value] = trim(strip_tags((string) $label));
        }
        $this->options = array_map(
            fn (string $value, string $label) => ['value' => $value, 'label' => $label !== '' ? $label : $value],
            array_keys($normalized),
            array_values($normalized),
        );
        $this->shareOptionsWithFilter();

        return $this;
    }

    /**
     * Draw the cell as an image. Width and height are display pixels, capped so a grid cannot stretch the row.
     */
    public function image(int $width = 40, int $height = 40): static
    {
        $this->type = 'image';
        $this->imageWidth = max(16, min(240, $width));
        $this->imageHeight = max(16, min(240, $height));
        $this->sortable = false;
        $this->searchable = false;

        return $this;
    }

    /** The cell holds several of the configured option values. */
    public function multiple(bool $multiple = true): static
    {
        $this->multiple = $multiple;
        $this->shareOptionsWithFilter();

        return $this;
    }

    public function pinned(?string $side): static
    {
        $this->pinned = $side;

        return $this;
    }

    public function width(?int $px): static
    {
        $this->width = $px;

        return $this;
    }

    public function defaultSort(string $direction = 'asc'): static
    {
        $this->defaultSort = $direction;
        $this->sortable = true;

        return $this;
    }

    /**
     * Enable filtering. Pass a Filter, a type name ('select'), or true to infer from the column type.
     * Custom logic: ->filterable(Filter::text(), using: fn ($q, $op, $value) => ...)
     */
    public function filterable(Filter|string|bool $filter = true, ?Closure $using = null): static
    {
        $this->filter = match (true) {
            $filter === false => null,
            $filter instanceof Filter => $filter,
            is_string($filter) => $this->makeFilter($filter),
            default => $this->makeFilter($this->inferFilterType()),
        };
        $this->filterUsing = $using;
        $this->shareOptionsWithFilter();

        return $this;
    }

    /** Who may see this column. Ability string, enum, or Closure(actor, AuthContext): bool. */
    public function can(mixed $ability): static
    {
        $this->ability = $ability;

        return $this;
    }

    /**
     * Who may change this column. Leave it unset and anyone who can see an editable column may edit it.
     * Ability string, enum, or Closure(actor, AuthContext): bool.
     */
    public function canEdit(mixed $ability): static
    {
        $this->editAbility = $ability;

        return $this;
    }

    // ---- introspection (used by the engine) ------------------------------------

    public function key(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isSortable(): bool
    {
        return $this->sortable && ! $this->isSensitive();
    }

    public function isSearchable(): bool
    {
        return $this->searchable && ! $this->isSensitive();
    }

    public function isVisibleByDefault(): bool
    {
        return $this->visible;
    }

    public function isHidden(): bool
    {
        return $this->hidden || $this->isSensitive();
    }

    public function isSensitive(): bool
    {
        return in_array($this->type, ['password', 'hidden', 'secret'], true);
    }

    public function isExportable(): bool
    {
        return $this->exportable && ! $this->isSensitive();
    }

    public function isImportable(): bool
    {
        return $this->importable && ! $this->isSensitive();
    }

    public function isCopyable(): bool
    {
        return $this->copyable && ! $this->isSensitive();
    }

    /** Inline edit is limited to a real attribute that is sent to the client. */
    public function isEditable(): bool
    {
        return $this->editable && ! $this->isHidden() && $this->kind() === 'direct' && $this->editRules !== null;
    }

    /** @return string|array<int, mixed>|null */
    public function editRules(): string|array|null
    {
        return $this->editRules;
    }

    public function hasOptions(): bool
    {
        return $this->options !== [];
    }

    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    /**
     * Reduce a submitted value to the allowed option list.
     *
     * @throws InvalidGridRequest
     */
    public function acceptedValue(mixed $value): mixed
    {
        $allowed = array_column($this->options, 'value');
        if (! $this->multiple) {
            if (is_array($value) || is_object($value) || $value === null) {
                throw new InvalidGridRequest("Field [{$this->key}] must be one of the allowed values.");
            }
            $value = trim((string) $value);
            if (! in_array($value, $allowed, true)) {
                throw new InvalidGridRequest("Field [{$this->key}] must be one of the allowed values.");
            }

            return $value;
        }

        $items = $this->multipleItems($value);
        if (count($items) > 20) {
            throw new InvalidGridRequest("Field [{$this->key}] accepts at most 20 values.");
        }
        foreach ($items as $item) {
            if (! in_array($item, $allowed, true)) {
                throw new InvalidGridRequest("Field [{$this->key}] contains a value that is not allowed.");
            }
        }

        return array_values(array_unique($items));
    }

    public function getFilter(): ?Filter
    {
        return $this->filter;
    }

    public function filterCallback(): ?Closure
    {
        return $this->filterUsing;
    }

    public function exportCallback(): ?Closure
    {
        return $this->exportAs;
    }

    public function valueCallback(): ?Closure
    {
        return $this->using;
    }

    public function getAbility(): mixed
    {
        return $this->ability;
    }

    public function getEditAbility(): mixed
    {
        return $this->editAbility;
    }

    public function getDefaultSort(): ?string
    {
        return $this->defaultSort;
    }

    public function relationName(): ?string
    {
        return $this->relation;
    }

    public function aggregateSpec(): ?array
    {
        return $this->aggregate;
    }

    public function computedSql(): ?string
    {
        return $this->computed;
    }

    /** direct | relation | aggregate | computed */
    public function kind(): string
    {
        return match (true) {
            $this->computed !== null => 'computed',
            $this->aggregate !== null => 'aggregate',
            $this->relation !== null => 'relation',
            default => 'direct',
        };
    }

    public function attributeName(): string
    {
        return $this->attribute ?? Str::afterLast($this->key, '.');
    }

    /** Safe SQL/result alias derived from the key. */
    public function alias(): string
    {
        return preg_replace('/\W+/', '__', $this->key);
    }

    // ---- schema -----------------------------------------------------------------

    public function toSchema(OperatorRegistry $operators): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'type' => $this->type,
            'visible' => $this->visible,
            'toggleable' => $this->toggleable,
            'sortable' => $this->sortable,
            'searchable' => $this->searchable,
            'exportable' => $this->exportable,
            'importable' => $this->importable && $this->kind() === 'direct' && ! $this->hidden,
            'copy' => $this->copyable,
            'editable' => $this->isEditable(),
            'options' => $this->options !== [] ? $this->options : null,
            'multiple' => $this->multiple,
            'image' => $this->type === 'image' ? [
                'width' => $this->imageWidth ?? 40,
                'height' => $this->imageHeight ?? 40,
            ] : null,
            'pinned' => $this->pinned,
            'width' => $this->width,
            'defaultSort' => $this->defaultSort,
            'filter' => $this->filter?->toSchema($operators),
        ];
    }

    private function makeFilter(string $type): Filter
    {
        return match ($type) {
            'text' => Filter::text(),
            'number' => Filter::number(),
            'date' => Filter::date(),
            'boolean' => Filter::boolean(),
            'select' => Filter::select(),
            'relation' => Filter::relation(),
            default => throw new \InvalidArgumentException("Unknown filter type [{$type}]."),
        };
    }

    private function shareOptionsWithFilter(): void
    {
        if (! $this->filter instanceof Filter || $this->options === []) {
            return;
        }
        if ($this->filter->getType() !== 'select') {
            $this->filter = Filter::select();
        }
        $map = [];
        foreach ($this->options as $option) {
            $map[$option['value']] = $option['label'];
        }
        $this->filter->options($map);
        if ($this->multiple) {
            $this->filter->multiple();
        }
    }

    /** @return list<string> */
    private function multipleItems(mixed $value): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }
            if (str_starts_with($trimmed, '[')) {
                $decoded = json_decode($trimmed, true);
                $value = is_array($decoded) ? $decoded : [$trimmed];
            } else {
                $value = explode('|', $trimmed);
            }
        }
        if (! is_array($value) || array_is_list($value) === false) {
            throw new InvalidGridRequest("Field [{$this->key}] must be a list of allowed values.");
        }

        $items = [];
        foreach ($value as $item) {
            if (is_array($item) || is_object($item) || $item === null) {
                throw new InvalidGridRequest("Field [{$this->key}] must be a list of allowed values.");
            }
            $item = trim((string) $item);
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function inferFilterType(): string
    {
        if ($this->options !== []) {
            return 'select';
        }

        return match ($this->type) {
            'number', 'money', 'integer', 'decimal' => 'number',
            'date', 'datetime' => 'date',
            'boolean' => 'boolean',
            'badge', 'enum', 'select' => 'select',
            default => 'text',
        };
    }
}

<?php

namespace CodeCorner\Datagrid\Auth;

final class AuthContext
{
    public const GRID = 'grid';

    public const COLUMN = 'column';

    public const EDIT = 'edit';

    public const ACTION = 'action';

    public const ROW = 'row';

    public const EXPORT = 'export';

    public const IMPORT = 'import';

    public function __construct(
        public readonly string $grid,
        public readonly string $scope,
        public readonly ?string $key = null,
        public readonly mixed $record = null,
    ) {}
}

<?php

namespace CodeCorner\Datagrid\Console;

use CodeCorner\Datagrid\Auth\AuthManager;
use CodeCorner\Datagrid\Core\GridRegistry;
use Illuminate\Console\Command;

class DoctorCommand extends Command
{
    protected $signature = 'datagrid:doctor';

    protected $description = 'Check the datagrid configuration for common problems';

    public function handle(AuthManager $auth, GridRegistry $registry): int
    {
        $problems = 0;
        $driver = $auth->driverName();
        $this->line("Authorization driver: <info>{$driver}</info>");

        if ($driver === 'none') {
            $hasAuthMiddleware = collect((array) config('datagrid.routes.middleware'))
                ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'auth'));

            if (app()->isProduction() && ! $hasAuthMiddleware) {
                $this->error('Driver is "none" in production and the routes have no auth middleware: grids are public.');
                $problems++;
            } elseif (! $hasAuthMiddleware) {
                $this->warn('Driver is "none" and no auth middleware is set on the routes.');
            }
        }

        foreach ($registry->all() as $key => $class) {
            $grid = $registry->find($key);
            $this->line(sprintf('Grid <info>%s</info> (%s): %d columns', $key, $class, count($grid->columnMap())));
        }

        if ($registry->all() === []) {
            $this->warn('No grids registered in config/datagrid.php.');
        }

        return $problems > 0 ? self::FAILURE : self::SUCCESS;
    }
}

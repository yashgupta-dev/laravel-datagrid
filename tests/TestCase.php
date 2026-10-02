<?php

namespace CodeCorner\Datagrid\Tests;

use CodeCorner\Datagrid\DatagridServiceProvider;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [DatagridServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('datagrid.authorization.driver', 'none');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('roles', function ($t) {
            $t->id();
            $t->string('name');
        });
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->string('salary')->nullable();
            $t->string('status')->default('active');
            $t->json('tags')->nullable();
            $t->unsignedBigInteger('role_id')->nullable();
            $t->timestamps();
        });
        Schema::create('posts', function ($t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('title');
            $t->integer('views')->default(0);
        });
        Schema::create('datagrid_saved_filters', function ($t) {
            $t->id();
            $t->string('actor_id');
            $t->string('grid');
            $t->string('name');
            $t->json('payload');
            $t->timestamps();
            $t->unique(['actor_id', 'grid', 'name']);
        });
    }
}

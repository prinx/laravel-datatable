<?php

namespace Prinx\Laravel\Datatable\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Prinx\Laravel\Datatable\ServiceProvider as RepositoryServiceProvider;

class TestCase extends BaseTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        //
    }

    public function createApplication()
    {
        //
    }

    protected function getPackageProviders($app)
    {
        return [
            RepositoryServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        //
    }
}

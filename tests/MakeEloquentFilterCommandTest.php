<?php

use Mockery as m;
use PHPUnit\Framework\TestCase;

class MakeEloquentFilterCommandTest extends TestCase
{
    protected $filesystem;

    protected $command;

    public function setUp()
    {
        $this->filesystem = m::mock(Illuminate\Filesystem\Filesystem::class);
        $this->command = m::mock('EloquentFilter\Commands\MakeEloquentFilter[argument]', [$this->filesystem]);
    }

    public function tearDown()
    {
        m::close();
    }

    /**
     * @dataProvider modelClassProvider
     */
    public function testMakeClassName($argument, $class)
    {
        $this->command->shouldReceive('argument')->andReturn($argument);
        $this->command->makeClassName();
        $this->assertEquals("App\\ModelFilters\\$class", $this->command->getClassName());
    }

    public function modelClassProvider()
    {
        return [
            ['User', 'UserFilter'],
            ['Admin\\User', 'Admin\\UserFilter'],
            ['UserFilter', 'UserFilter'],
            ['user-filter', 'UserFilter'],
            ['adminUser', 'AdminUserFilter'],
            ['admin-user', 'AdminUserFilter'],
            ['admin-user\\user-filter', 'AdminUser\\UserFilter'],
        ];
    }
}

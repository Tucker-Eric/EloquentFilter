<?php

use Mockery as m;
use PHPUnit\Framework\TestCase;
use EloquentFilter\TestClass\User;
use EloquentFilter\TestClass\Client;
use Illuminate\Database\Eloquent\Model;
use EloquentFilter\TestClass\UserFilter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Schema\Builder as SchemaBuilder;

class ModelFilterChildTest extends TestCase
{
    protected $model;

    /**
     * @var SchemaBuilder
     */
    protected $schema;

    /**
     * @var DatabaseManager
     */
    protected $db;

    public function setUp()
    {
        $this->model = new User;
        $this->dbSetup();
    }

    public function tearDown()
    {
        m::close();
    }

    public function testGetRelatedModel()
    {
        $userMock = m::mock(User::class);
        $userQueryMock = m::mock(Builder::class);
        $hasManyMock = m::mock(HasMany::class);

        $userQueryMock->shouldReceive('getModel')->once()->andReturn($userMock);

        $userMock->shouldReceive('clients')->once()->andReturn($hasManyMock);

        $hasManyMock->shouldReceive('getRelated')->once()->andReturn(new Client);

        $client = (new UserFilter($userQueryMock))->getRelatedModel('clients');

        $this->assertEquals($client, new Client);
    }

    public function testProvideFilter()
    {
        // Empty provide filter App\ModelFilters is the default namespace when empty
        $this->assertEquals($this->model->provideFilter(), App\ModelFilters\UserFilter::class);
        // Filter Value
        $this->assertEquals(
            $this->model->provideFilter(App\ModelFilters\DynamicFilter\TestModelFilter::class),
            App\ModelFilters\DynamicFilter\TestModelFilter::class
        );
    }

    public function testGetModelFilterClass()
    {
        $this->assertEquals($this->model->getModelFilterClass(), EloquentFilter\TestClass\UserFilter::class);
    }

    public function testPaginationWorksOnBelongsToMany()
    {
        $client = Client::query()->first();
        $managers = $client->managers()->filter()->paginateFilter();
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\Pivot::class, $managers->first()->pivot);
    }

    protected function dbSetup()
    {
        $config = [
            'database.fetch'       => PDO::FETCH_CLASS,
            'database.default'     => 'sqlite',
            'database.connections' => [
                'sqlite' => [
                    'driver'   => 'sqlite',
                    'database' => ':memory:',
                    'prefix'   => '',
                ],
            ],
        ];
        $container = m::mock(\Illuminate\Container\Container::class);
        $container->shouldReceive('bound')->andReturn(false);
        $container->shouldReceive('offsetGet')->with('config')->andReturn($config);
        $this->db = new DatabaseManager($container, new ConnectionFactory($container));
        Model::setConnectionResolver($this->db);
        $connection = $this->db->connection('sqlite');
        $connection->setSchemaGrammar(new \Illuminate\Database\Schema\Grammars\SQLiteGrammar);
        $connection->setQueryGrammar(new \Illuminate\Database\Query\Grammars\SQLiteGrammar);
        $this->schema = new SchemaBuilder($connection);
        $this->schema->create('users', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });
        $this->schema->create('client_user', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->integer('client_id');
            $table->integer('user_id');
        });
        $this->schema->create('clients', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->increments('id');
            // This is for the HasManyThroughRelation
            $table->integer('user_id')->nullable();
            $table->string('name');
        });
        $this->schema->create('locations', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->increments('id');
            $table->integer('client_id');
            $table->string('name');
        });

        $clients = [['name' => 'one'], ['name' => 'two'], ['name' => 'three'], ['name' => 'four']];
        foreach ($clients as $index => $data) {
            $client = Client::create($data);
            $user = User::create(['name' => 'Client'.$index]);
            $user->clients()->save($client);
            $client->managers()->save($user);
            $otherUser = User::create(['name' => 'Client'.++$index]);
            $client->managers()->save($otherUser);
        }
    }
}

<?php

use EloquentFilter\TestClass\Client;
use EloquentFilter\TestClass\Location;
use EloquentFilter\TestClass\User;
use EloquentFilter\TestClass\UserFilter;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Mockery as m;
use PHPUnit\Framework\TestCase;

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
        $filter = new UserFilter($this->model->newQuery());
        // Regular relation
        $this->assertInstanceOf(Client::class, $filter->getRelatedModel('clients'));
        // Nested relation
        $this->assertInstanceOf(Location::class, $filter->getRelatedModel('clients.locations'));
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

    public function testRelationDotNotation()
    {
        $users = $this->model->filter(['client_location' => 'one'])->get();
        $this->assertEquals(1, $users->count());
    }

    public function testFilterRelationsArrayAliases()
    {
        // client_name is defined as ['clients' => ['client_name' => 'name' ]];
        // This will forward and call ClientFilter::name
        $users = $this->model->filter(['client_name' => 'one'])->get();
        $this->assertEquals(1, $users->count());

        $client = new Client;
        $clients = $client->filter(['owner_name' => 'Client1'])->get();
        $this->assertEquals(1, $clients->count());
    }

    public function testPaginationWorksOnBelongsToMany()
    {
        if (method_exists(\Illuminate\Database\Eloquent\Relations\Relation::class, 'macro')) {
            $client = Client::query()->first();
            $managers = $client->managers()->filter()->paginateFilter();
            $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\Pivot::class, $managers->first()->pivot);
        } else {
            // Paginating relations will work before L5.4 but won't contain the pivot attribute
            $this->markTestSkipped(
                'Pagination is overwritten with a Relation macro to append the pivot to pivotable relations.'
                .' This was introduced in Laravel 5.4 when Relations implemented the Macroable trait.'
                .' https://github.com/illuminate/database/commit/4d13b0f80439bd17befb0fd646a117b818efdb14'
            );
        }
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
            /** @var Client $client */
            $client = Client::create($data);
            $client->locations()->create($data);
            /** @var User $user */
            $user = User::create(['name' => 'Client'.$index]);
            $user->clients()->save($client);
            $client->managers()->save($user);
            $otherUser = User::create(['name' => 'Client'.++$index]);
            $client->managers()->save($otherUser);
        }
    }
}

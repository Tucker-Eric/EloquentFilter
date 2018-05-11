<?php

use Mockery as m;
use EloquentFilter\ModelFilter;
use PHPUnit\Framework\TestCase;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

class ModelFilterTest extends TestCase
{
    /**
     * @var ModelFilter
     */
    protected $filter;

    /**
     * @var \Illuminate\Database\Eloquent\Builder
     */
    protected $builder;

    /**
     * @var array
     */
    protected $testInput;

    /**
     * @var array
     */
    protected $config;

    public function setUp()
    {
        $this->builder = m::mock(EloquentBuilder::class);
        $this->filter = new TestModelFilter($this->builder);
        $this->config = require __DIR__.'/config.php';
        $this->testInput = $this->config['test_input'];
    }

    public function tearDown()
    {
        m::close();
    }

    public function testRemoveEmptyInput()
    {
        $filteredInput = $this->filter->removeEmptyInput($this->testInput);
        // Remove empty strings from the input
        foreach ($filteredInput as $val) {
            $this->assertNotEquals($val, '');
        }
    }

    public function testPush()
    {
        // Test key/value pair
        $this->filter->push('name', 'er');
        $this->assertEquals($this->filter->input(), ['name' => 'er']);

        // Test with inserting array
        $this->filter->push([
            'company_id' => '2',
            'roles'      => ['1', '4', '7'],
        ]);

        $this->assertEquals($this->filter->input(), [
            'name'       => 'er',
            'company_id' => '2',
            'roles'      => ['1', '4', '7'],
        ]);
    }

    public function testDisableRelations()
    {
        // Default is true
        $this->assertEquals($this->filter->relationsEnabled(), true);

        // Set to false
        $this->filter->disableRelations();
        $this->assertEquals($this->filter->relationsEnabled(), false);

        // Set to true
        $this->filter->enableRelations();
        $this->assertEquals($this->filter->relationsEnabled(), true);
    }

    /**
     * @depends testPush
     * @depends testRemoveEmptyInput
     */
    public function testInputMethod()
    {
        $filteredInput = $this->filter->removeEmptyInput($this->testInput);

        // Push has already been tested
        $this->filter->push($filteredInput);

        // All keys are in tact
        foreach ($this->testInput as $key => $val) {
            $this->assertEquals($this->filter->input($key), $this->testInput[$key]);
        }

        // All input is in tact after filter
        $this->assertEquals($this->filter->input(), $filteredInput);

        // Passing a key that doesnt exist returns null
        $this->assertNull($this->filter->input('missing_key'));

        // Test default parameter
        $this->assertEquals($this->filter->input('missing_key', 'my_default'), 'my_default');
    }

    public function testGetFilterMethod()
    {
        $input = [
            'name'               => 'name',
            'first_name'         => 'firstName',
            'first_or_last_name' => 'firstOrLastName',
        ];

        foreach ($input as $key => $method) {
            $this->assertEquals($method, $this->filter->getFilterMethod($key));
        }
    }

    public function testGetFilterMethodWithIds()
    {
        $key = 'user_name_id';

        $this->filter->dropIdSuffix(true);
        $this->assertEquals('userName', $this->filter->getFilterMethod($key));

        $this->filter->dropIdSuffix(false);
        $this->assertEquals('userNameId', $this->filter->getFilterMethod($key));
    }

    public function testRelatedMethodBooleans()
    {
        $related = 'fakeRelation';
        $this->assertFalse($this->filter->relationIsLocal($related));

        $this->filter->related($related, function (EloquentBuilder $query) {
            return $query->whereRaw('1 = 1');
        });

        $this->assertTrue($this->filter->relationIsLocal($related));
    }

    public function testGetFilterInputForRelationsArray()
    {
        $this->filter->relations = [
            'roles' => ['roles'],
        ];
        $this->filter->push($this->testInput);

        $this->assertEquals($this->filter->getRelatedFilterInput('roles'), ['roles' => $this->filter->input('roles')]);
    }

    /**
     * @depends testRelatedMethodBooleans
     */
    public function testRelatedMethod()
    {
        $this->assertEquals($this->filter->getLocalRelation('testRelation'), []);

        // Define Closure
        $this->filter->related('testRelation', function (EloquentBuilder $query) {
            return $query->where('id', 1);
        });

        // Return closure
        $relatedClosure = $this->filter->getLocalRelation('testRelation')[0];

        $this->assertInternalType('callable', $relatedClosure);

        $query = m::mock(EloquentBuilder::class);

        $query->shouldReceive('where')->with('id', 1)->once();

        $relatedClosure($query);
    }

    /**
     * @depends testRelatedMethod
     */
    public function testWhereRelatedMethodWithoutValue()
    {
        $this->filter->related('fakeRelation', 'id', 1);

        $relatedClosure = $this->filter->getLocalRelation('fakeRelation')[0];

        $this->assertInternalType('callable', $relatedClosure);

        $query = m::mock(EloquentBuilder::class);

        $query->shouldReceive('where')->with('id', '=', 1, 'and')->once();

        $relatedClosure($query);
    }

    /**
     * @depends testRelatedMethod
     */
    public function testWhereRelatedMethodWithValue()
    {
        $this->filter->related('fakeRelation', 'id', '>=', 1, 'or');

        $relatedClosure = $this->filter->getLocalRelation('fakeRelation')[0];

        $this->assertInternalType('callable', $relatedClosure);

        $query = m::mock(EloquentBuilder::class);

        $query->shouldReceive('where')->with('id', '>=', 1, 'or')->once();

        $relatedClosure($query);
    }

    public function testCallsForwardToQueryBuilder()
    {
        $this->builder->shouldReceive('where')->with(1, '=', 1)->once()->andReturnSelf();
        $this->builder->shouldReceive('whereLike')->with(1, 1)->once()->andReturnSelf();
        $this->assertEquals($this->filter, $this->filter->where(1, '=', 1));
        $this->assertEquals($this->filter, $this->filter->whereLike(1, 1));
    }

    public function testCallsForwardedToBuilderReturnModelFilter()
    {
        $this->builder->shouldReceive('where')->with(1, '=', 1)->once()->andReturnSelf();
        $this->builder->shouldReceive('whereLike')->with(1, 1)->once()->andReturnSelf();
        $this->builder->shouldReceive('orWhere')->with(1, 1)->once()->andReturnSelf();
        $this->assertEquals($this->filter, $this->filter->where(1, '=', 1));
        $this->assertEquals($this->filter, $this->filter->whereLike(1, 1));
        $this->assertEquals($this->filter, $this->filter->orWhere(1, 1));
    }

    public function testHandleReturnsBuilder()
    {
        $this->assertEquals($this->builder, $this->filter->handle());
    }

    public function testSetupIsCalled()
    {
        $filter = m::mock('EloquentFilter\TestClass\UserFilter[setup]', [$this->builder]);
        $filter->shouldReceive('setup')->once();
        $this->assertInstanceOf(EloquentBuilder::class, $filter->handle());
    }

    public function testRelatedReturnsFilter()
    {
        $this->assertEquals($this->filter, $this->filter->related('relation', function () {
        }));
        $this->assertEquals($this->filter, $this->filter->addRelated('relation', function () {
        }));
        $this->assertEquals($this->filter, $this->filter->related('relation', 'param', 'val'));
    }

    public function testBlacklistAddingAndRemoving()
    {
        $method = 'questionableMethod';

        $this->assertFalse($this->filter->methodIsBlacklisted($method));
        $this->filter->blacklistMethod($method);
        $this->assertTrue($this->filter->methodIsBlacklisted($method));
        $this->filter->whitelistMethod($method);
        $this->assertFalse($this->filter->methodIsBlacklisted($method));
    }

    public function testParentClassMethodsCantBeCalledByInput()
    {
        $badMethod = 'whitelistMethod';
        $goodMethod = 'filterItem';

        $filter = m::mock("TestModelFilter[$badMethod,$goodMethod]", [$this->builder]);

        $filter->push($badMethod, 'something');
        $filter->push($goodMethod, 1);

        $filter->shouldNotReceive($badMethod);
        $filter->shouldReceive($goodMethod)->once();
        // We need to assert something to make phpunit happy so null it is!

        $this->assertNull($filter->filterInput());
    }

    public function testBlacklistWontBeCalled()
    {
        $badMethod = 'uncallable';
        $filter = m::mock("TestModelFilter[$badMethod]", [$this->builder]);

        $filter->push($badMethod, 'something');
        $filter->blacklistMethod($badMethod);
        // Assert we should not be called
        $this->assertTrue($filter->methodIsBlacklisted($badMethod));

        $filter->shouldNotReceive($badMethod);
        $filter->filterInput();

        $filter->whitelistMethod($badMethod);

        $filter->shouldReceive($badMethod)->once();

        $filter->filterInput();
    }
}

class TestModelFilter extends ModelFilter
{
    public function filterItem($item)
    {
        $this->where($item);
    }

    public function uncallable($doThangs)
    {
        $this->orderBy($doThangs);
    }
}

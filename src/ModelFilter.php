<?php namespace EloquentFilter;

use Illuminate\Database\Eloquent\Builder as QueryBuilder;


class ModelFilter
{
    /**
     * Related Models that have ModelFilters as well as the method on the ModelFilter
     * As [relatedModel => [method1, method2]]
     * 
     * @var array
     */
    public $relations = [];

    /**
     * Array of input to filter
     *
     * @var array
     */
    protected $input;

    /**
     * @var \Illuminate\Database\Eloquent\Builder
     */
    protected $query;

    private $_joinedTables = null;

    /**
     * ModelFilter constructor.
     *
     * @param $query
     * @param array $input
     */
    public function __construct($query, array $input)
    {
        $this->query = $query;
        $this->input = $this->removeEmptyInput($input);
    }

    /**
     * Handle calling methods on the query object
     *
     * @param $method
     * @param $args
     */
    public function __call($method, $args)
    {
        $class = method_exists($this, $method) ? $this : $this->query;

        return call_user_func_array([$class, $method], $args);
    }

    /**
     * Remove empty strings from the input array
     *
     * @param $input
     * @return array
     */
    public function removeEmptyInput($input)
    {
        return array_where($input, function ($key, $val)
        {
            return $val != '';
        });
    }

    /**
     * Handle all input filters
     *
     * @return QueryBuilder
     */
    public function handle()
    {
        foreach ($this->input as $key => $val)
        {
            // Call all local methods on filter
            $method = camel_case(preg_replace('/^(.*)_id$/', '$1', $key));

            if (method_exists($this, $method))
            {
                call_user_func([$this, $method], $val);
            }
        }

        // Set up all the whereHas and joins constraints
        $this->filterRelations();

        return $this->query;
    }

    /**
     * Filter relationships defined in $this->relations array
     * @return $this
     */
    public function filterRelations()
    {
        // No need to filer if we dont have any relations
        if (count($this->relations) === 0)
            return $this;

        foreach ($this->relations as $related => $fields)
        {
            if (count($filterableInput = array_only($this->input, $fields)) > 0)
            {
                if ($this->relationIsJoined($related))
                {
                    $this->filterJoinedRelation($related, $filterableInput);
                } else
                {
                    $this->filterUnjoinedRelation($related, $filterableInput);
                }
            }
        }

        return $this;
    }

    /**
     * Run the filter on models that already have their tables joined
     *
     * @param $related
     * @param $filterableInput
     */
    public function filterJoinedRelation($related, $filterableInput)
    {
        $relatedModel = $this->query->getModel()->{$related}()->getRelated();

        $filterClass = __NAMESPACE__ . '\\' . class_basename($relatedModel) . 'Filter';

        with(new $filterClass($this->query, $filterableInput))->handle();
    }

    /**
     * Gets all the joined tables
     *
     * @return array
     */
    public function getJoinedTables()
    {
        $joins = [];

        if (is_array($queryJoins = $this->query->getQuery()->joins))
        {
            $joins = array_map(function ($join)
            {
                return $join->table;
            }, $queryJoins);
        }

        return $joins;
    }

    /**
     * Checks if the relation to filter's table is already joined
     *
     * @param $relation
     * @return boolean
     */
    public function relationIsJoined($relation)
    {
        if (is_null($this->_joinedTables))
            $this->_joinedTables = $this->getJoinedTables();

        return in_array($this->getRelatedTable($relation), $this->_joinedTables);
    }

    /**
     * Get the table name from a relationship
     *
     * @param $relation
     * @return string
     */
    public function getRelatedTable($relation)
    {
        return $this->query->getModel()->{$relation}()->getRelated()->getTable();
    }

    /**
     * Filters by a relationship that isnt joined by using that relation's ModelFilter
     *
     * @param $related
     * @param $filterableInput
     */
    public function filterUnjoinedRelation($related, $filterableInput)
    {
        $this->query->whereHas($related, function ($q) use ($filterableInput)
        {
            return $q->filter($filterableInput);
        });
    }

    /**
     * Retrieve input by key or all input as array
     * 
     * @param null $key
     * @return array|mixed|null
     */
    public function input($key = null)
    {
        if (is_null($key))
            return $this->input;

        return isset($this->input[$key]) ? $this->input[$key] : null;
    }
}
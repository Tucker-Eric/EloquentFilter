<?php

namespace EloquentFilter;

use Illuminate\Database\Eloquent\Builder as QueryBuilder;

/**
 * @method  QueryBuilder where(string $column, string $operator = null, mixed $value = null, string $boolean = 'and')
 * @method  QueryBuilder doesntHave(string $relation, string $boolean = 'and', \Closure $callback = null)
 * @method  QueryBuilder whereHas(string $relation, \Closure $callback, string $operator = '>=', int $count = 1)
 * @method  QueryBuilder whereDoesntHave(string $relation, \Closure $callback = null)
 * @method  QueryBuilder selectRaw(string $expression, array $bindings = [])
 * @method  QueryBuilder selectSub(\Closure|QueryBuilder|string $query, string $as)
 * @method  QueryBuilder whereRaw(string $sql, mixed $bindings = [], string $boolean = 'and')
 * @method  QueryBuilder whereBetween(string $column, array $values, string $boolean = 'and', bool $not = false)
 * @method  QueryBuilder whereNotBetween(string $column, array $values, string $boolean = 'and')
 * @method  QueryBuilder whereNested(\Closure $callback, string $boolean = 'and')
 * @method  QueryBuilder whereExists(\Closure $callback, string $boolean = 'and', bool $not = false)
 * @method  QueryBuilder whereIn(string $column, mixed $values, string $boolean = 'and', bool $not = false)
 * @method  QueryBuilder whereNotIn(string $column, mixed $values, string $boolean = 'and')
 * @method  QueryBuilder whereNotNull(string $column, string $boolean = 'and')
 * @method  QueryBuilder whereDate(string $column, string $operator, int $value, string $boolean = 'and')
 * @method  QueryBuilder whereDay(string $column, string $operator, int $value, string $boolean = 'and')
 * @method  QueryBuilder whereMonth(string $column, string $operator, int $value, string $boolean = 'and')
 * @method  QueryBuilder whereYear(string $column, string $operator, int $value, string $boolean = 'and')
 * @method  QueryBuilder orderBy(string $column, string $direction = 'asc')
 * @method  QueryBuilder limit(int $value)
 * @method  QueryBuilder withTrashed()
 */
class ModelFilter
{
    /**
     * Related Models that have ModelFilters as well as the method on the ModelFilter
     * As [relatedModel => [input_key1, input_key2]].
     *
     * @var array
     */
    public $relations = [];

    /**
     * Container to hold all relation queries defined as closures as ['relation' => [\Closure, \Closure]].
     * (This allows us to not be required to define a filter for the related models).
     *
     * @var array
     */
    protected $localRelatedFilters = [];

    /**
     * Container for all relations (local and related ModelFilters).
     * @var array
     */
    protected $allRelations = [];

    /**
     * Array of input to filter.
     *
     * @var array
     */
    protected $input;

    /**
     * @var \Illuminate\Database\Eloquent\Builder
     */
    protected $query;

    /**
     * Drop `_id` from the end of input keys when referencing methods.
     *
     * @var bool
     */
    protected $drop_id = true;

    /**
     * This is to be able to bypass relations if we are filtering a joined table.
     *
     * @var bool
     */
    protected $relationsEnabled;

    /**
     * Tables already joined in the query to filter by the joined column instead of using
     *  ->whereHas to save a little bit of resources.
     *
     * @var null
     */
    private $_joinedTables;

    /**
     * ModelFilter constructor.
     *
     * @param $query
     * @param array $input
     * @param bool $relationsEnabled
     */
    public function __construct($query, array $input = [], $relationsEnabled = true)
    {
        $this->query = $query;
        $this->input = $this->removeEmptyInput($input);
        $this->relationsEnabled = $relationsEnabled;
    }

    /**
     * Handle calling methods on the query object.
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
     * Remove empty strings from the input array.
     *
     * @param array $input
     * @return array
     */
    public function removeEmptyInput($input)
    {
        $filterableInput = [];

        foreach ($input as $key => $val) {
            if ($val !== '') {
                $filterableInput[$key] = $val;
            }
        }

        return $filterableInput;
    }

    /**
     * Handle all filters.
     *
     * @return QueryBuilder
     */
    public function handle()
    {
        // Filter global methods
        if (method_exists($this, 'setup')) {
            $this->setup();
        }

        // Run input filters
        $this->filterInput();
        // Set up all the whereHas and joins constraints
        $this->filterRelations();

        return $this->query;
    }

    /**
     * Locally defines a relation filter method that will be called in the context of the related model.
     *
     * @param $relation
     * @param \Closure $closure
     */
    public function related($relation, \Closure $closure)
    {
        $this->localRelatedFilters[$relation][] = $closure;
    }

    public function whereRelated($relation, $column, $operator = null, $value = null, $boolean = 'and')
    {
        // Just in case we pass a closure this is an alias for the related() method
        if ($column instanceof \Closure) {
            $closure = $column;
        } else {
            if ($value === null) {
                $value = $operator;
                $operator = '=';
            }

            $closure = function ($query) use ($column, $operator, $value, $boolean) {
                return $query->where($column, $operator, $value, $boolean);
            };
        }

        $this->related($relation, $closure);
    }

    /**
     * @param $key
     * @return string
     */
    public function getFilterMethod($key)
    {
        return camel_case($this->drop_id ? preg_replace('/^(.*)_id$/', '$1', $key) : $key);
    }

    /**
     * Filter with input array.
     */
    public function filterInput()
    {
        foreach ($this->input as $key => $val) {
            // Call all local methods on filter
            $method = $this->getFilterMethod($key);

            if (method_exists($this, $method)) {
                $this->{$method}($val);
            }
        }
    }

    /**
     * Filter relationships defined in $this->relations array.
     *
     * @return $this
     */
    public function filterRelations()
    {
        // Verify we can filter by relations and there are relations to filter by
        if ($this->relationsEnabled()) {
            foreach ($this->getAllRelations() as $related => $filterable) {
                // Make sure we have filterable input
                if (count($filterable) > 0) {
                    if ($this->relationIsJoined($related)) {
                        $this->filterJoinedRelation($related);
                    } else {
                        $this->filterUnjoinedRelation($related);
                    }
                }
            }
        }

        return $this;
    }

    /**
     * Returns all local relations and relations requiring other Model's Filter's.
     * @return array
     */
    public function getAllRelations()
    {
        if (count($this->allRelations) === 0) {
            $allRelations = array_merge(array_keys($this->relations), array_keys($this->localRelatedFilters));

            foreach ($allRelations as $related) {
                $this->allRelations[$related] = array_merge($this->getLocalRelation($related), $this->getRelatedFilterInput($related));
            }
        }

        return $this->allRelations;
    }

    /**
     * Get all input to pass through related filters and local closures as an array.
     *
     * @param string $relation
     * @return array
     */
    public function getRelationConstraints($relation)
    {
        return array_key_exists($relation, $this->allRelations) ? $this->allRelations[$relation] : [];
    }

    public function callRelatedLocalSetup($related, $query)
    {
        if (method_exists($this, $method = camel_case($related).'Setup')) {
            $this->{$method}($query);
        }
    }

    /**
     * Run the filter on models that already have their tables joined.
     *
     * @param $related
     */
    public function filterJoinedRelation($related)
    {
        // Apply any relation based scope to avoid method duplication
        $this->callRelatedLocalSetup($related, $this->query);

        foreach ($this->getLocalRelation($related) as $closure) {
            // If a relation is defined locally in a method AND is joined
            // Then we call those defined relation closures on this query
            $closure($this->query);
        }

        // Check if we have input we need to pass through a related Model's filter
        // Then filter by that related model's filter
        if (count($relatedFilterInput = $this->getRelatedFilterInput($related)) > 0) {
            $filterClass = $this->getRelatedFilter($related);

            // Disable querying joined relations on filters of joined tables.
            (new $filterClass($this->query, $this->getRelatedFilterInput($related), false))->handle();
        }
    }

    /**
     * Gets all the joined tables.
     *
     * @return array
     */
    public function getJoinedTables()
    {
        $joins = [];

        if (is_array($queryJoins = $this->query->getQuery()->joins)) {
            $joins = array_map(function ($join) {
                return $join->table;
            }, $queryJoins);
        }

        return $joins;
    }

    /**
     * Checks if the relation to filter's table is already joined.
     *
     * @param $relation
     * @return bool
     */
    public function relationIsJoined($relation)
    {
        if ($this->_joinedTables === null) {
            $this->_joinedTables = $this->getJoinedTables();
        }

        return in_array($this->getRelatedTable($relation), $this->_joinedTables);
    }

    /**
     * Get an empty instance of a related model.
     *
     * @param $relation
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function getRelatedModel($relation)
    {
        return $this->query->getModel()->{$relation}()->getRelated();
    }

    /**
     * Get the table name from a relationship.
     *
     * @param $relation
     * @return string
     */
    public function getRelatedTable($relation)
    {
        return $this->getRelatedModel($relation)->getTable();
    }

    /**
     * Get the model filter of a related model.
     *
     * @param $relation
     * @return mixed
     */
    public function getRelatedFilter($relation)
    {
        return $this->getRelatedModel($relation)->getModelFilterClass();
    }

    /**
     * Filters by a relationship that isn't joined by using that relation's ModelFilter.
     *
     * @param $related
     * @param $filterableInput
     */
    public function filterUnjoinedRelation($related)
    {
        $this->query->whereHas($related, function ($q) use ($related) {
            $this->callRelatedLocalSetup($related, $q);

            // If we defined it locally then we're running the closure on the related model here right.
            foreach ($this->getLocalRelation($related) as $closure) {
                // Run in context of the related model locally
                $closure($q);
            }

            if (count($filterableRelated = $this->getLocalRelation($related)) > 0) {
                $q->filter($filterableRelated);
            }

            return $q;
        });
    }

    /**
     * Get input to pass to a related Model's Filter.
     *
     * @param $related
     * @return array
     */
    public function getRelatedFilterInput($related)
    {
        return array_key_exists($related, $this->relations) ? array_only($this->input, $this->relations[$related]) : [];
    }

    /**
     * Check to see if there is input or locally defined methods for the given relation.
     *
     * @param $relation
     * @return bool
     */
    public function relationIsFilterable($relation)
    {
        return $this->relationUsesFilter($relation) || $this->relationIsLocal($relation);
    }

    /**
     * Checks if there is input that should be passed to a related Model Filter.
     *
     * @param $related
     * @return bool
     */
    public function relationUsesFilter($related)
    {
        return count($this->getRelatedFilterInput($related)) > 0;
    }

    /**
     * Checks to see if there are locally defined relations to filter.
     *
     * @param $related
     * @return bool
     */
    public function relationIsLocal($related)
    {
        return count($this->getLocalRelation($related)) > 0;
    }

    /**
     * @param string $related
     * @return array
     */
    public function getLocalRelation($related)
    {
        return array_key_exists($related, $this->localRelatedFilters) ? $this->localRelatedFilters[$related] : [];
    }

    /**
     * Retrieve input by key or all input as array.
     *
     * @param null $key
     * @param null $default
     * @return array|mixed|null
     */
    public function input($key = null, $default = null)
    {
        if ($key === null) {
            return $this->input;
        }

        return array_key_exists($key, $this->input) ? $this->input[$key] : $default;
    }

    /**
     * Disable querying relations (Mainly for joined tables as the related model isn't queried).
     *
     * @return $this
     */
    public function disableRelations()
    {
        $this->relationsEnabled = false;

        return $this;
    }

    /**
     * Enable querying relations.
     *
     * @return $this
     */
    public function enableRelations()
    {
        $this->relationsEnabled = true;

        return $this;
    }

    /**
     * Checks if filtering by relations is enabled.
     *
     * @return bool
     */
    public function relationsEnabled()
    {
        return $this->relationsEnabled;
    }

    /**
     * Add values to filter by if called in setup().
     * Will ONLY filter relations if called on additional method.
     *
     * @param $key
     * @param null $value
     */
    public function push($key, $value = null)
    {
        if (is_array($key)) {
            $this->input = array_merge($this->input, $key);
        } else {
            $this->input[$key] = $value;
        }
    }

    /**
     * Set to drop `_id` from input. Mainly for testing.
     *
     * @param null $bool
     *
     * @return bool
     */
    public function dropIdSuffix($bool = null)
    {
        if ($bool === null) {
            return $this->drop_id;
        }

        return $this->drop_id = $bool;
    }
}

<?php

namespace EloquentFilter;

use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * @mixin QueryBuilder
 */
abstract class ModelFilter
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
     * Array of method names that should not be called.
     * @var array
     */
    protected $blacklist = [];

    /**
     * Array of input to filter.
     *
     * @var array
     */
    protected $input;

    /**
     * @var QueryBuilder
     */
    protected $query;

    /**
     * Drop `_id` from the end of input keys when referencing methods.
     *
     * @var bool
     */
    protected $drop_id = true;

    /**
     * Convert input keys to camelCase
     * Ex: my_awesome_key will be converted to myAwesomeKey($value).
     *
     * @var bool
     */
    protected $camel_cased_methods = true;

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
        $this->registerMacros();
    }

    /**
     * @param $method
     * @param $args
     * @return mixed
     */
    public function __call($method, $args)
    {
        $resp = call_user_func_array([$this->query, $method], $args);

        // Only return $this if query builder is returned
        // We don't want to make actions to the builder unreachable
        return $resp instanceof QueryBuilder ? $this : $resp;
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
            if ($val !== '' && $val !== null) {
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
     * @return $this
     */
    public function addRelated($relation, \Closure $closure)
    {
        $this->localRelatedFilters[$relation][] = $closure;

        return $this;
    }

    /**
     * Add a where constraint to a relationship.
     *
     * @param $relation
     * @param $column
     * @param null $operator
     * @param null $value
     * @param string $boolean
     * @return $this
     */
    public function related($relation, $column, $operator = null, $value = null, $boolean = 'and')
    {
        if ($column instanceof \Closure) {
            return $this->addRelated($relation, $column);
        }

        // If there is no value it is a where = ? query and we set the appropriate params
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        return $this->addRelated($relation, function ($query) use ($column, $operator, $value, $boolean) {
            return $query->where($column, $operator, $value, $boolean);
        });
    }

    /**
     * @param $key
     * @return string
     */
    public function getFilterMethod($key)
    {
        // Remove '.' chars in methodName
        $methodName = str_replace('.', '', $this->drop_id ? preg_replace('/^(.*)_id$/', '$1', $key) : $key);

        // Convert key to camelCase?
        return $this->camel_cased_methods ? Str::camel($methodName) : $methodName;
    }

    /**
     * Filter with input array.
     */
    public function filterInput()
    {
        foreach ($this->input as $key => $val) {
            // Call all local methods on filter
            $method = $this->getFilterMethod($key);

            if ($this->methodIsCallable($method)) {
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

    /**
     * Call setup method for relation before filtering on it.
     *
     * @param $related
     * @param $query
     */
    public function callRelatedLocalSetup($related, $query)
    {
        if (method_exists($this, $method = Str::camel($related).'Setup')) {
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
            (new $filterClass($this->query, $relatedFilterInput, false))->handle();
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

        return in_array($this->getRelatedTable($relation), $this->_joinedTables, true);
    }

    /**
     * Get an empty instance of a related model.
     *
     * @param $relation
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function getRelatedModel($relation)
    {
        if (strpos($relation, '.') !== false) {
            return $this->getNestedRelatedModel($relation);
        }

        return $this->query->getModel()->{$relation}()->getRelated();
    }

    /**
     * @param $relationString
     * @return QueryBuilder|\Illuminate\Database\Eloquent\Model
     */
    protected function getNestedRelatedModel($relationString)
    {
        $parts = explode('.', $relationString);
        $related = $this->query->getModel();

        do {
            $relation = array_shift($parts);
            $related = $related->{$relation}()->getRelated();
        } while (! empty($parts));

        return $related;
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

            if (count($filterableRelated = $this->getRelatedFilterInput($related)) > 0) {
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
        $output = [];

        if (array_key_exists($related, $this->relations)) {
            foreach ((array) $this->relations[$related] as $alias => $name) {
                // If the alias is a string that is what we grab from the input
                // Then use the name for the output so we can alias relations
                if ($value = Arr::get($this->input, is_string($alias) ? $alias : $name)) {
                    $output[$name] = $value;
                }
            }
        }

        return $output;
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

    /**
     * Convert input to camel_case. Mainly for testing.
     *
     * @param null $bool
     *
     * @return bool
     */
    public function convertToCamelCasedMethods($bool = null)
    {
        if ($bool === null) {
            return $this->camel_cased_methods;
        }

        return $this->camel_cased_methods = $bool;
    }

    /**
     * Add method to the blacklist so disable calling it.
     * @param string $method
     * @return $this
     */
    public function blacklistMethod($method)
    {
        $this->blacklist[] = $method;

        return $this;
    }

    /**
     * Remove a method from the blacklist.
     * @param string $method
     * @return $this
     */
    public function whitelistMethod($method)
    {
        $this->blacklist = array_filter($this->blacklist, function ($name) use ($method) {
            return $name !== $method;
        });

        return $this;
    }

    /**
     * @param $method
     * @return bool
     */
    public function methodIsBlacklisted($method)
    {
        return in_array($method, $this->blacklist, true);
    }

    /**
     * Check if the method is not blacklisted and callable on the extended class.
     * @param $method
     * @return bool
     */
    public function methodIsCallable($method)
    {
        return ! $this->methodIsBlacklisted($method) &&
            method_exists($this, $method) &&
            ! method_exists(ModelFilter::class, $method);
    }

    /**
     * Register paginate and simplePaginate macros on relations
     * BelongsToMany overrides the QueryBuilder's paginate to append the pivot.
     */
    private function registerMacros()
    {
        if (
            method_exists(Relation::class, 'hasMacro') &&
            method_exists(Relation::class, 'macro') &&
            ! Relation::hasMacro('paginateFilter') &&
            ! Relation::hasMacro('simplePaginateFilter')
        ) {
            Relation::macro('paginateFilter', function () {
                $paginator = call_user_func_array([$this, 'paginate'], func_get_args());
                $paginator->appends($this->getRelated()->filtered);

                return $paginator;
            });
            Relation::macro('simplePaginateFilter', function () {
                $paginator = call_user_func_array([$this, 'simplePaginate'], func_get_args());
                $paginator->appends($this->getRelated()->filtered);

                return $paginator;
            });
        }
    }
}

<?php

namespace EloquentFilter;

trait Filterable
{
    /**
     * Array of input used to filter the query.
     *
     * @var array
     */
    protected $filtered = [];

    /**
     * Creates local scope to run the filter.
     *
     * @param $query
     * @param  array  $input
     * @param  null|string|ModelFilter  $filter
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFilter($query, array $input = [], $filter = null)
    {
        // Resolve the current Model's filter
        if ($filter === null) {
            $filter = $this->getModelFilterClass();
        }

        // Create the model filter instance
        $modelFilter = new $filter($query, $input);

        // Set the input that was used in the filter (this will exclude empty strings)
        $this->filtered = $modelFilter->input();

        // Return the filter query
        return $modelFilter->handle();
    }

    /**
     * Paginate the given query with url query params appended.
     *
     * @param  int  $perPage
     * @param  array  $columns
     * @param  string  $pageName
     * @param  int|null  $page
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     *
     * @throws \InvalidArgumentException
     */
    public function scopePaginateFilter($query, $perPage = null, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $perPage = $perPage ?: config('eloquentfilter.paginate_limit');
        $paginator = $query->paginate($perPage, $columns, $pageName, $page);
        $paginator->appends($this->filtered);

        return $paginator;
    }

    /**
     * Paginate the given query with url query params appended.
     *
     * @param  int  $perPage
     * @param  array  $columns
     * @param  string  $pageName
     * @param  int|null  $page
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     *
     * @throws \InvalidArgumentException
     */
    public function scopeSimplePaginateFilter($query, $perPage = null, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $perPage = $perPage ?: config('eloquentfilter.paginate_limit');
        $paginator = $query->simplePaginate($perPage, $columns, $pageName, $page);
        $paginator->appends($this->filtered);

        return $paginator;
    }

    /**
     * Returns ModelFilter class to be instantiated.
     *
     * @param  null|string  $filter
     * @return string
     */
    public function provideFilter($filter = null)
    {
        if ($filter === null) {
            $filter = config('eloquentfilter.namespace', 'App\\ModelFilters\\').class_basename($this).'Filter';
        }

        return $filter;
    }

    /**
     * Returns the ModelFilter for the current model.
     *
     * @return string
     */
    public function getModelFilterClass()
    {
        return method_exists($this, 'modelFilter') ? $this->modelFilter() : $this->provideFilter();
    }

    /**
     * WHERE $column LIKE %$value% query.
     *
     * @param $query
     * @param $column
     * @param $value
     * @param  string  $boolean
     * @return mixed
     */
    public function scopeWhereLike($query, $column, $value, $boolean = 'and')
    {
        return $query->where($column, 'LIKE', "%$value%", $boolean);
    }

    /**
     * WHERE $column LIKE $value% query.
     *
     * @param $query
     * @param $column
     * @param $value
     * @param  string  $boolean
     * @return mixed
     */
    public function scopeWhereBeginsWith($query, $column, $value, $boolean = 'and')
    {
        return $query->where($column, 'LIKE', "$value%", $boolean);
    }

    /**
     * WHERE $column LIKE %$value query.
     *
     * @param $query
     * @param $column
     * @param $value
     * @param  string  $boolean
     * @return mixed
     */
    public function scopeWhereEndsWith($query, $column, $value, $boolean = 'and')
    {
        return $query->where($column, 'LIKE', "%$value", $boolean);
    }
}

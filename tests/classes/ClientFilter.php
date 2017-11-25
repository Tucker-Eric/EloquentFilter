<?php

namespace EloquentFilter\TestClass;

use EloquentFilter\ModelFilter;

class ClientFilter extends ModelFilter
{
    /**
     * Related Models that have ModelFilters as well as the method on the ModelFilter
     * As [relatedModel => [input_key1, input_key2]].
     *
     * @var array
     */
    public $relations = [
        'user' => ['owner_name'],
    ];
}

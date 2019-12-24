<?php

namespace EloquentFilter\TestClass;

use EloquentFilter\ModelFilter;

class UserFilter extends ModelFilter
{
    /**
     * Related Models that have ModelFilters as well as the method on the ModelFilter
     * As [relatedModel => [input_key1, input_key2]].
     *
     * @var array
     */
    public $relations = [
        'clients' => ['client_name' => 'name'],
    ];

    public function setup()
    {
        return $this;
    }

    public function clientsSetup()
    {
        return $this;
    }

    public function clientLocation($location)
    {
        $this->related('clients.locations', 'name', $location);
    }

    public function ownerName($name)
    {
        $this->where('name', '=', $name);
    }
}

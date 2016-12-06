<?php

namespace EloquentFilter\TestClass;

use EloquentFilter\Filterable;
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    use Filterable;

    public function clients()
    {
        return $this->hasMany(Client::class);
    }

    public function modelFilter()
    {
        return $this->provideFilter(UserFilter::class);
    }
}

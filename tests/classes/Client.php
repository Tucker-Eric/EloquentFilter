<?php

namespace EloquentFilter\TestClass;

use EloquentFilter\Filterable;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use Filterable;

    public function agent()
    {
        return $this->belongsTo(User::class);
    }

    public function modelFilter()
    {
        return $this->provideFilter(ClientFilter::class);
    }
}

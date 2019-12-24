<?php

namespace EloquentFilter\TestClass;

use EloquentFilter\Filterable;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use Filterable;

    protected $fillable = ['name'];

    public $timestamps = false;

    public function agent()
    {
        return $this->belongsTo(User::class);
    }

    public function modelFilter()
    {
        return $this->provideFilter(ClientFilter::class);
    }

    public function managers()
    {
        return $this->belongsToMany(User::class);
    }

    public function locations()
    {
        return $this->hasMany(Location::class);
    }
}

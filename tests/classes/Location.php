<?php

namespace EloquentFilter\TestClass;

use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    protected $fillable = ['name'];

    public $timestamps = false;

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}

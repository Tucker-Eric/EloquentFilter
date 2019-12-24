# Eloquent Filter

[![Latest Stable Version](https://poser.pugx.org/tucker-eric/eloquentfilter/v/stable)](https://packagist.org/packages/tucker-eric/eloquentfilter)
[![Total Downloads](https://poser.pugx.org/tucker-eric/eloquentfilter/downloads)](https://packagist.org/packages/tucker-eric/eloquentfilter)
[![Daily Downloads](https://poser.pugx.org/tucker-eric/eloquentfilter/d/daily)](https://packagist.org/packages/tucker-eric/eloquentfilter)
[![License](https://poser.pugx.org/tucker-eric/eloquentfilter/license)](https://packagist.org/packages/tucker-eric/eloquentfilter)
[![StyleCI](https://styleci.io/repos/53163405/shield)](https://styleci.io/repos/53163405/)
[![Build Status](https://travis-ci.org/Tucker-Eric/EloquentFilter.svg?branch=master)](https://travis-ci.org/Tucker-Eric/EloquentFilter)
[![PHPUnit Status](https://github.com/Tucker-Eric/EloquentFilter/workflows/PHPUnit/badge.svg?branch=master)](https://github.com/Tucker-Eric/EloquentFilter/actions?query=branch%3Amaster)

An Eloquent way to filter Eloquent Models and their relationships

## Introduction
Lets say we want to return a list of users filtered by multiple parameters. When we navigate to:

`/users?name=er&last_name=&company_id=2&roles[]=1&roles[]=4&roles[]=7&industry=5`

`$request->all()` will return:

```php
[
    'name'       => 'er',
    'last_name'  => '',
    'company_id' => '2',
    'roles'      => ['1','4','7'],
    'industry'   => '5'
]
```

To filter by all those parameters we would need to do something like:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests;
use App\User;

class UserController extends Controller
{

    public function index(Request $request)
    {
        $query = User::where('company_id', $request->input('company_id'));

        if ($request->has('last_name'))
        {
            $query->where('last_name', 'LIKE', '%' . $request->input('last_name') . '%');
        }

        if ($request->has('name'))
        {
            $query->where(function ($q) use ($request)
            {
                return $q->where('first_name', 'LIKE', $request->input('name') . '%')
                    ->orWhere('last_name', 'LIKE', '%' . $request->input('name') . '%');
            });
        }

        $query->whereHas('roles', function ($q) use ($request)
        {
            return $q->whereIn('id', $request->input('roles'));
        })
            ->whereHas('clients', function ($q) use ($request)
            {
                return $q->whereHas('industry_id', $request->input('industry'));
            });

        return $query->get();
    }

}
```

To filter that same input With Eloquent Filters:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests;
use App\User;

class UserController extends Controller
{

    public function index(Request $request)
    {
        return User::filter($request->all())->get();
    }

}
```

## Configuration
### Install Through Composer
```
composer require tucker-eric/eloquentfilter
```

There are a few ways to define the filter a model will use:

- [Use EloquentFilter's Default Settings](#default-settings)
- [Use A Custom Namespace For All Filters](#with-configuration-file-optional)
- [Define A Model's Default Filter](#define-the-default-model-filter-optional)
- [Dynamically Select A Model's Filter](#dynamic-filters)


#### Default Settings
The default namespace for all filters is `App\ModelFilters\` and each Model expects the filter classname to follow the `{$ModelName}Filter` naming convention regardless of the namespace the model is in.  Here is an example of Models and their respective filters based on the default naming convention.

|Model|ModelFilter|
|-----|-----------|
|`App\User`|`App\ModelFilters\UserFilter`|
|`App\FrontEnd\PrivatePost`|`App\ModelFilters\PrivatePostFilter`|
|`App\FrontEnd\Public\GuestPost`|`App\ModelFilters\GuestPostFilter`|

#### Laravel

##### With Configuration File (Optional)

> Registering the service provider will give you access to the `php artisan model:filter {model}` command as well as allow you to publish the configuration file.  Registering the service provider is not required and only needed if you want to change the default namespace or use the artisan command

After installing the Eloquent Filter library, register the `EloquentFilter\ServiceProvider::class` in your `config/app.php` configuration file:

```php
'providers' => [
    // Other service providers...

    EloquentFilter\ServiceProvider::class,
],
```

Copy the package config to your local config with the publish command:

```bash
php artisan vendor:publish --provider="EloquentFilter\ServiceProvider"
```

In the `config/eloquentfilter.php` config file.  Set the namespace your model filters will reside in:

```php
'namespace' => "App\\ModelFilters\\",
```

#### Lumen

##### Register The Service Provider (Optional)

>This is only required if you want to use the `php artisan model:filter` command.

In `bootstrap/app.php`:

```php
$app->register(EloquentFilter\LumenServiceProvider::class);
```

##### Change The Default Namespace

In `bootstrap/app.php`:

```php
config(['eloquentfilter.namespace' => "App\\Models\\ModelFilters\\"]);
```

#### Define The Default Model Filter (optional)

> The following is optional. If no `modelFilter` method is found on the model the model's filter class will be resolved by the [default naming conventions](#default-settings)

Create a public method `modelFilter()` that returns `$this->provideFilter(Your\Model\Filter::class);` in your model.

```php
<?php

namespace App;

use EloquentFilter\Filterable;
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    use Filterable;

    public function modelFilter()
    {
        return $this->provideFilter(\App\ModelFilters\CustomFilters\CustomUserFilter::class);
    }

    //User Class
}
```
#### Dynamic Filters

You can define the filter dynamically by passing the filter to use as the second parameter of the `filter()` method.  Defining a filter dynamically will take precedent over any other filters defined for the model.

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests;
use App\User;
use App\ModelFilters\Admin\UserFilter as AdminFilter;
use App\ModelFilters\User\UserFilter as BasicUserFilter;
use Auth;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $userFilter = Auth::user()->isAdmin() ? AdminFilter::class : BasicUserFilter::class;

        return User::filter($request->all(), $userFilter)->get();
    }
}

```

### Generating The Filter
> Only available if you have registered `EloquentFilter\ServiceProvider::class` in the providers array in your `config/app.php'

You can create a model filter with the following artisan command:

```bash
php artisan model:filter User
```

Where `User` is the Eloquent Model you are creating the filter for.  This will create `app/ModelFilters/UserFilter.php`

The command also supports psr-4 namespacing for creating filters.  You just need to make sure you escape the backslashes in the class name.  For example:

```bash
php artisan model:filter AdminFilters\\User
```

This would create `app/ModelFilters/AdminFilters/UserFilter.php`

## Usage

### Defining The Filter Logic
Define the filter logic based on the camel cased input key passed to the `filter()` method.

- Empty strings and null values are ignored
- If a `setup()` method is defined it will be called once before any filter methods regardless of input
- `_id` is dropped from the end of the input key to define the method so filtering `user_id` would use the `user()` method
    - (can be changed with by definining `protected $drop_id = false;` on a filter)
- Input without a corresponding filter method are ignored
- The value of the key is injected into the method
- All values are accessible through the `$this->input()` method or a single value by key `$this->input($key)`
- All Eloquent Builder methods are accessible in `$this` context in the model filter class.

To define methods for the following input:

```php
[
    'company_id'   => 5,
    'name'         => 'Tuck',
    'mobile_phone' => '888555'
]
```

You would use the following methods:

```php

use EloquentFilter\ModelFilter;

class UserFilter extends ModelFilter
{
    protected $blacklist = ['secretMethod'];
    
    // This will filter 'company_id' OR 'company'
    public function company($id)
    {
        return $this->where('company_id', $id);
    }

    public function name($name)
    {
        return $this->where(function($q) use ($name)
        {
            return $q->where('first_name', 'LIKE', "%$name%")
                ->orWhere('last_name', 'LIKE', "%$name%");
        });
    }

    public function mobilePhone($phone)
    {
        return $this->where('mobile_phone', 'LIKE', "$phone%");
    }

    public function setup()
    {
        $this->onlyShowDeletedForAdmins();
    }

    public function onlyShowDeletedForAdmins()
    {
        if(Auth::user()->isAdmin())
        {
            $this->withTrashed();
        }
    }
    
    public function secretMethod($secretParameter)
    {
        return $this->where('some_column', true);
    }
}
```

> **Note:**  In the above example if you do not want `_id` dropped from the end of the input you can set `protected $drop_id = false` on your filter class.  Doing this would allow you to have a `company()` filter method as well as a `companyId()` filter method.


> **Note:**  In the above example if you do not want `mobile_phone` to be mapped to `mobilePhone()` you can set `protected $camel_cased_methods = false` on your filter class. Doing this would allow you to have a `mobile_phone()` filter method instead of `mobilePhone()`. By default, `mobilePhone()` filter method can be called thanks to one of the following input key: `mobile_phone`, `mobilePhone`, `mobile_phone_id`

> **Note:** In the example above all methods inside `setup()` will be called every time `filter()` is called on the model

#### Blacklist

Any methods defined in the `blackist` array will not be called by the filter. Those methods are normally used for internal filter logic.

The `blacklistMethod()` and `whitelistMethod()` methods can be used to dynamically blacklist and whitelist methods.

In the example above `secretMethod()` will not be called, even if there is a `secret_method` key in the input array. In order to call this method it would need to be whitelisted dynamically:

Example:
```php
public function setup()
{
    if(Auth::user()->isAdmin()) {
        $this->whitelistMethod('secretMethod');
    }
}
```


#### Additional Filter Methods

The `Filterable` trait also comes with the below query builder helper methods:

|EloquentFilter Method|QueryBuilder Equivalent|
|---|---|
|`$this->whereLike($column, $string)`|`$query->where($column, 'LIKE', '%'.$string.'%')`|
|`$this->whereBeginsWith($column, $string)`|`$query->where($column, 'LIKE', $string.'%')`|
|`$this->whereEndsWith($column, $string)`|`$query->where($column, 'LIKE', '%'.$string)`|

Since these methods are part of the `Filterable` trait they are accessible from any model that implements the trait without the need to call in the Model's EloquentFilter.


### Applying The Filter To A Model

Implement the `EloquentFilter\Filterable` trait on any Eloquent model:

```php
<?php

namespace App;

use EloquentFilter\Filterable;
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    use Filterable;

    //User Class
}
```

This gives you access to the `filter()` method that accepts an array of input:

```php
class UserController extends Controller
{
    public function index(Request $request)
    {
        return User::filter($request->all())->get();
    }
}
```

## Filtering By Relationships
>There are two ways to filter by related models.  Using the `$relations` array to define the input to be injected into the related Model's filter.  If the related model doesn't have a model filter of it's own or you just want to define how to filter that relationship locally instead of adding the logic to that Model's filter then use the `related()` method to filter by a related model that doesn't have a ModelFilter.  You can even combine the 2 and define which input fields in the `$relations` array you want to use that Model's filter for as well as use the `related()` method to define local methods on that same relation.  Both methods nest the filter constraints into the same `whereHas()` query on that relation.

For both examples we will use the following models:

A `App\User` that `hasMany` `App\Client::class`:

```php
class User extends Model
{
    use Filterable;

    public function clients()
    {
        return $this->hasMany(Client::class);
    }
}
```

And each `App\Client` belongs to `App\Industry::class`:

```php
class Client extends Model
{
    use Filterable;

    public function industry()
    {
        return $this->belongsTo(Industry::class);
    }
    
    public function scopeHasRevenue($query)
    {
        return $query->where('total_revenue', '>', 0);
    }
}
```


We want to query our users and filter them by the industry and volume potential of their clients that have done revenue in the past.

Input used to filter:

```php
$input = [
    'industry'         => '5',
    'potential_volume' => '10000'
];
```

### Setup

Both methods will invoke a setup query on the relationship that will be called EVERY time this relationship is queried.  The setup methods signature is `{$related}Setup()` and is injected with an instance of that relations query builder.  For this example let's say when querying users by their clients I only ever want to show agents that have clients with revenue. Without choosing wich method to put it in (because sometimes we may not have all the input and miss the scope all together if we choose the wrong one) and to avoid query duplication by placing that constraint on ALL methods for that relation we call the related setup method in the `UserFilter` like:

```php
class UserFilter extends ModelFilter
{
    public function clientsSetup($query)
    {
        return $query->hasRevenue();
    }
}
```
This will prepend the query to the `clients()` relation with `hasRevenue()` whenever the `UserFilter` runs any constriants on the `clients()` relationship. If there are no queries to the `clients()` relationship then this method will not be invoked.

> You can learn more about scopes [here](https://laravel.com/docs/master/eloquent#local-scopes)


### Ways To Filter Related Models 

- [With The `related()` Method](#filter-related-models-with-the-related-method)
- [Using The `$relations` Array](#filter-related-models-using-the-relations-array)
- [With Both Methods](#filter-related-models-with-both-methods)

#### Filter Related Models With The `related()` Method:

The `related()` method is a little easier to setup and is great if you aren't going to be using the related Model's filter to ever filter that Model explicitly.  The `related()` method takes the same parameters as the `Eloquent\Builder`'s `where()` method except for the first parameter being the relationship name.

##### Example:


`UserFilter` with an `industry()` method that uses the `ModelFilter`'s `related()` method

```php
class UserFilter extends ModelFilter
{
    public function industry($id)
    {
        return $this->related('clients', 'industry_id', '=', $id);
        
        // This would also be shorthand for the same query
        // return $this->related('clients', 'industry_id', $id);
    }
    
    public function potentialVolume($volume)
    {
        return $this->related('clients', 'potential_volume', '>=', $volume);
    }
}
```

Or you can even pass a closure as the second argument which will inject an instance of the related model's query builder like:

```php
    $this->related('clients', function($query) use ($id) {
        return $query->where('industry_id', $id);
    });
```

#### Filter Related Models Using The `$relations` Array:

Add the relation in the `$relations` array with the name of the relation as referred to on the model as the key and an array of input keys that was passed to the `filter()` method.

The related model **MUST** have a ModelFilter associated with it.  We instantiate the related model's filter and use the input values from the `$relations` array to call the associated methods.

This is helpful when querying multiple columns on a relation's table while avoiding multipe `whereHas()` calls for the same relationship.  For a single column using a `$this->whereHas()` method in the model filter works just fine.  In fact, under ther hood the model filter applies all constraints in the `whereHas()` method.

##### Example:

`UserFilter` with the relation defined so it's able to be queried.

```php
class UserFilter extends ModelFilter
{
    public $relations = [
        'clients' => ['industry', 'potential_volume'],
    ];
}
```

`ClientFilter` with the `industry` method that's used to filter:
> **Note:** The `$relations` array should identify the relation and the input key to filter by that relation. Just as the `ModelFilter` works, this will access the camelCased method on that relation's filter. If the above example was using the key `industry_type` for the input the relations array would be `$relations = ['clients' => ['industry_type']]` and the `ClientFilter` would have the method `industryType()`.

```php
class ClientFilter extends ModelFilter
{
    public $relations = [];

    public function industry($id)
    {
        return $this->where('industry_id', $id);
    }
    
    public function potentialVolume($volume)
    {
        return $this->where('potential_volume', '>=', $volume);
    }
}
```
##### `$relations` array alias support
The `$relations` array supports aliases. This is used when the input doesn't match the related model's filter method.
This will transform the input keys being passed to the related model filter's input.

##### Example:
```php
class UserFilter extends ModelFilter
{
    public $relations = [
        'clients' => [
            'client_industry'  => 'industry',
            'client_potential' => 'potential_volume'
        ]
    ];
}
```

The above will receive an array like:
```php
[
    'client_industry' => 1,
    'client_potential' => 100000
]
```
And the `ClientFilter` will receive it as:
```php
[
    'industry'         => 1,
    'potential_volume' => 100000
]
```
Allowing for more descriptive input names without filters needing to match. Allowing for more reuse of the same filters.

#### Filter Related Models With Both Methods
You can even use both together and it will produce the same result and only query the related model once.  An example would be:

If the following array is passed to the `filter()` method:

```php
[
    'name'             => 'er',
    'last_name'        => ''
    'company_id'       => 2,
    'roles'            => [1,4,7],
    'industry'         => 5,
    'potential_volume' => '10000'
]
```

In `app/ModelFilters/UserFilter.php`:

```php
<?php namespace App\ModelFilters;

use EloquentFilter\ModelFilter;

class UserFilter extends ModelFilter
{
    public $relations = [
        'clients' => ['industry'],
    ];
    
    public function clientsSetup($query)
    {
        return $query->hasRevenue();
    }

    public function name($name)
    {
        return $this->where(function($q)
        {
            return $q->where('first_name', 'LIKE', $name . '%')->orWhere('last_name', 'LIKE', '%' . $name.'%');
        });
    }
    
    public function potentialVolume($volume)
    {
        return $this->related('clients', 'potential_volume', '>=', $volume);
    }

    public function lastName($lastName)
    {
        return $this->where('last_name', 'LIKE', '%' . $lastName);
    }

    public function company($id)
    {
        return $this->where('company_id',$id);
    }

    public function roles($ids)
    {
        return $this->whereHas('roles', function($query) use ($ids)
        {
            return $query->whereIn('id', $ids);
        });
    }
}
```

##### Adding Relation Values To Filter

Sometimes, based on the value of a parameter you may need to push data to a relation filter.  The `push()` method does just this.
It accepts one argument as an array of key value pairs or to arguments as a key value pair `push($key, $value)`.
Related models are filtered AFTER all local values have been executed you can use this method in any filter method.
This avoids having to query a related table more than once.  For Example:

```php
public $relations = [
    'clients' => ['industry', 'status'],
];

public function statusType($type)
{
    if($type === 'all') {
        $this->push('status', 'all');
    }
}
```

The above example will pass `'all'` to the `status()` method on the `clients` relation of the model.
> Calling the `push()` method in the `setup()` method will allow you to push values to the input for filter it's called on

#### Pagination

If you want to paginate your query and keep the url query string without having to use:

```php
{!! $pages->appends(Input::except('page'))->render() !!}
```

The `paginateFilter()` and `simplePaginateFilter()` methods accept the same input as [Laravel's paginator](https://laravel.com/docs/master/pagination#basic-usage) and returns the respective paginator.

```php
class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::filter($request->all())->paginateFilter();

        return view('users.index', compact('users'));
    }
```

OR:

```php
    public function simpleIndex(Request $request)
    {
        $users = User::filter($request->all())->simplePaginateFilter();

        return view('users.index', compact('users'));
    }
}
```

In your view `$users->render()` will return pagination links as it normally would but with the original query string with empty input ignored.


# Contributing
Any contributions welcome!

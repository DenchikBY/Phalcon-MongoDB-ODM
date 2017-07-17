Phalcon MongoDB ODM
===============

[![Latest Stable Version](https://poser.pugx.org/denchikby/phalcon-mongodb-odm/v/stable?format=flat-square)](https://packagist.org/packages/denchikby/phalcon-mongodb-odm)
[![Total Downloads](https://poser.pugx.org/denchikby/phalcon-mongodb-odm/downloads?format=flat-square)](https://packagist.org/packages/denchikby/phalcon-mongodb-odm)
[![Latest Unstable Version](https://poser.pugx.org/denchikby/phalcon-mongodb-odm/v/unstable?format=flat-square)](https://packagist.org/packages/denchikby/phalcon-mongodb-odm)
[![License](https://poser.pugx.org/denchikby/phalcon-mongodb-odm/license?format=flat-square)](https://packagist.org/packages/denchikby/phalcon-mongodb-odm)

Tiny, simple and functional MongoDB ODM library for Phalcon framework for new mongodb php extension
-----

Installation
------------

Make sure you have the MongoDB PHP driver installed. You can find installation instructions at http://php.net/manual/en/mongodb.installation.php

Install the latest stable version using composer:

```
composer require denchikby/phalcon-mongodb-odm
```

or

```
{
    "require": {
        "denchikby/phalcon-mongodb-odm": "dev-master"
    }
}
```

Configuration
-------------

Add settings and service to DI:

```php
$di->set('config', function () {
    return new \Phalcon\Config([
        'mongodb' => [
            'host'     => 'localhost',
            'port'     => 27017,
            'database' => 'auto'
        ]
    ]);
}, true);

$di->set('mongo', function () use ($di) {
    $config  = $di->get('config')->mongodb;
    $manager = new \MongoDB\Driver\Manager('mongodb://' . $config->host . ':' . $config->port);
    return $manager;
}, true);
```

Creating Model
-------------

```php
use DenchikBY\MongoDB\Model;

class User extends Model {}
```

in this case will be used 'user' collection with same name as model.

To specify another collection name use getSource method:

```php
use DenchikBY\MongoDB\Model;

class User extends Model
{
    public static function getSource()
    {
        return 'users';
    }
}
```

Initialize Model
-------------

To initialize a new model instead of

```php
$user = new User;
```

use

```php
$user = User::init();
```

Initialize filled model:

```php
$user = User::init(['name' => 'DenchikBY']);
```

or

```php
$user = User::init()->fill(['name' => 'DenchikBY']);
```

or init and save in db

```php
$user = User::create(['name' => 'DenchikBY']);
```

Methods
---------

To array:

```php
$ad = Ads::init()->first();
var_dump($ad->toArray()); // array of all fields
var_dump($ad->toArray(['include' => ['id', 'name']])); // array of specified fields
var_dump($ad->toArray(['exclude' => ['user_id']])); // array of all fields except specified
```

Unset field:

```php
$ad = Ads::init()->first();
$ad->unsetField('counters.views');
```

Attributes Casting
-------------

It allow you to modify attribute type on setting/filling:

It help to save fields to db with need type, what is very important, cause mongo don't casting types in queries.

Supported types: integer, float, boolean, string, array, object, id

```php
class User extends Model
{
    protected static $casts = [
        'age' => 'integer'
    ];
}

$user->age = '20';

var_dump($user->age); => int(20)
```

Casts also work in where methods of builder:

```php
User::where('age', '20')->get();
```

age will converted to integer and query will load normally.

Relations
-------------

There are two types of relations: one and many;

Definition:

field => [related model, type, local field, foreign field]

```php
public static $relations = [
    'user'     => [Users::class, 'one', 'user_id', '_id'],
    'comments' => [Comments::class, 'many', '_id', 'ad_id']
];
```

Relations can be loaded by two ways:

By one query:

```php
Ads::where('views', '>', 1000)->join('user')->join('comments')->get()
```

it will use $lookup operator of aggregation framework.

By several queries, just call attribute with name of key in relations array:

```php
$user = User::where('name', 'DenchikBY')->first();
var_dump($user->comments);
```

Scopes
-------------

Scopes help to put common queries to methods:

```php
/**
 * @method $this active()
 */
class BaseModel extends Model
{
    public scopeActive($builder)
    {
        return $builder->where('active', 1);
    }
}

$users = User::active()->get();
$ads   = Ads::active()->get();
```

Global scopes
-----

This scope will binded to any query of model:

```php
class Ads extends Model
{
    public static $globalScopes = ['notDeleted'];
    
    public function notDeleted($builder)
    {
        return $builder->where('deleted', 0);
    }
}
```

Mutators (getters/setters)
-------------

Mutators allow modify attributes when you getting, setting or filling it.

For example, when you creating user and set the password, hashing may be defined in model:

```php
$user = User::create([
    'name'     => 'DenchikBY',
    'password' => '1234'
]);
```

```php
class User extends Model
{
    public function getName($value)
    {
        return ucfirst($value);
    }

    public function setPassword($value)
    {
        return Di::getDefault()->get('security')->hash($value);
    }
}
```

Events
-------------

Existed events before/after for actions save, create, update, delete.

```php
class User extends Model
{
    public function afterCreate()
    {
        Email::send($this->email, 'emails.succeddfull_registration', ['user' => $this]);
    }
}
```

Query Builder
-------------

Query builder could be called clearly or implicitly.

```php
$users = User::query()->get();
```

similar

```php
$users = User::get();
```

Usage
------

```php
$builder = User::query();

//allowed operators in where =, !=, >, <, >=, <=

$builder->where('name', '=', 'DenchikBY');
//similar
$builder->where('name', 'DenchikBY');

$builder->orWhere('name', 'Denis');

$builder->betweenWhere('age', 20, 30);

$builder->notBetweenWhere('age', 20, 30);

$builder->inWhere('name', ['DenchikBY', 'Denis']);

$builder->notInWhere('name', ['DenchikBY', 'Denis']);

$builder->orderBy('created_at', 'desc');

$builder->limit(2, 1);

//Closing methods:

$users = $builder->get(); // return collection of models

$user = $builder->first(); // return first model without collection

$count = $builder->count(); // run count command, which return int of counted documents

$count = $builder->increment('coins', 10); // increase field in founded documents, return count of them

$count = $builder->decrement('coins', 10);

$count = $builder->update(['banned' => 1]); // update founded documents with specified fields, return count of them

$count = $builder->delete(); // delete founded documents, return count of them

$age = $builder->max('age');

$age = $builder->min('age');

$age = $builder->avg('age');

$total = $builder->sum('age');

$builder->unsetField('counters.views');
```

Advanced Wheres
-----

For grouping where conditions:

```php
$query = Ads::query()->where('auto_id', '567153ea43946846683e77ff')->where(function (Builder $query) {
    $query->where('body', 1)->orWhere('capacity', 2);
});
```

Query Result Collection
-------------

Every select query will return iterated collection class of models.

```php
$collection = Comments::where('ad_id', new \MongoDB\BSON\ObjectId($id))->get();
```

It could be iterated with foreach, or used as array $collection[0]->name;

Methods
------

Size of collection:

```php
$collection->count();
```

Will return array of assocs of each model:

```php
$collection->toArray();
```

Return json of array, created by toArray method:

```php
$collection->toJson();
```

Eager loading, similar as join:

Will load all for all comments by single query and put necessary into every document.

```php
$collection->eager(Users::class, 'user', 'user_id', '_id');
```

Grouping documents to arrays by specific field:

```php
$collection->groupBy('user');
```

Keys the collection by the given key (unlike groupBy that value will single model, in groupBy array of models):

```php
$collection->keyBy('user');
```

Return array of values specific field of collection:

```php
$collection->pluck('user_id');
```

Return associated array of specified key => value fields:

```php
$collection->combine('_id', 'text');
```

Return array of chunked collection by specified size:

```php
$collection->chunk(10);
```
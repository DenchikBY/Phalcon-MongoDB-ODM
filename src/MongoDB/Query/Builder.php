<?php

namespace DenchikBY\MongoDB\Query;

class Builder
{

    protected $_model, $_modelObject;

    protected $_match = [];

    protected $_options = [];

    protected static $_operations = [
        '=' => '$eq',
        '!=' => '$ne',
        '>' => '$gt',
        '<' => '$lt',
        '>=' => '$gte',
        '<=' => '$lte'
    ];

    public function __construct($model)
    {
        $this->_model = $model;
        $this->_modelObject = $model::init();
        foreach ($model::$globalScopes as $name) {
            $this->_modelObject->{$name}($this);
        }
    }

    public function columns(array $fields)
    {
        $this->_options[] = ['$project' => array_fill_keys($fields, 1)];
        return $this;
    }

    public function join($relationName)
    {
        $model = $this->_model;
        if (isset($model::$relations[$relationName])) {
            $settings = $model::$relations[$relationName];
            $this->_options[] = ['$lookup' => [
                'from' => $settings[0]::getSource(),
                'localField' => $settings[2],
                'foreignField' => $settings[3],
                'as' => $relationName
            ]];
        }
        return $this;
    }

    public function where($field, $operation, $value = null)
    {
        if ($value == null) {
            $value = $operation;
            $operation = '=';
        }
        $expr = [$field => [self::$_operations[$operation] => $value]];
        if (!$this->_match) {
            $this->_match = $expr;
        } else {
            if (!isset($this->_match['$and']) && !isset($this->_match['$or'])) {
                $this->_match = ['$and' => [$this->_match, $expr]];
            } else {
                array_push($this->_match['$and'], $expr);
            }
        }
        return $this;
    }

    public function orWhere($field, $operation, $value = null)
    {
        if ($value == null) {
            $value = $operation;
            $operation = '=';
        }
        $expr = [$field => [self::$_operations[$operation] => $value]];
        if (!$this->_match) {
            $this->_match = $expr;
        } else {
            if (!isset($this->_match['$and']) && !isset($this->_match['$or'])) {
                $this->_match = ['$or' => [$this->_match, $expr]];
            } else {
                array_push($this->_match['$or'], $expr);
            }
        }
        return $this;
    }

    public function betweenWhere($field, $minimum, $maximum)
    {
        return $this->inWhereCommon([$field => ['$gte' => $minimum, '$lte' => $maximum]]);
    }

    public function notBetweenWhere($field, $minimum, $maximum)
    {
        return $this->inWhereCommon([$field => ['$not' => ['$gte' => $minimum, '$lte' => $maximum]]]);
    }

    public function inWhere($field, array $values)
    {
        return $this->inWhereCommon([$field => ['$in' => $values]]);
    }

    public function notInWhere($field, array $values)
    {
        return $this->inWhereCommon([$field => ['$nin' => $values]]);
    }

    protected function inWhereCommon($expr)
    {
        if (!$this->_match) {
            $this->_match = $expr;
        } else {
            if (!isset($this->_match['$and']) && !isset($this->_match['$or'])) {
                $this->_match = ['$and' => [$this->_match, $expr]];
            } else {
                array_push($this->_match['$and'], $expr);
            }
        }
        return $this;
    }

    public function orderBy($orderBy, $direction = 'asc')
    {
        $this->_options[] = ['$sort' => [$orderBy => ($direction == 'asc' ? 1 : -1)]];
        return $this;
    }

    public function limit($limit, $offset = null)
    {
        $this->_options[] = ['$limit' => $limit];
        if ($offset > 0) {
            $this->_options[] = ['$skip' => $offset];
        }
        return $this;
    }

    public function groupBy($group)
    {
        $this->_options[] = ['$group' => ['_id' => '$' . $group]];
        return $this;
    }

    public function get()
    {
        return $this->_modelObject->aggregate($this->getQuery());
    }

    public function first()
    {
        return $this->_modelObject->findFirst($this->_match);
    }

    public function count()
    {
        return $this->_modelObject->count($this->_match, $this->_options);
    }

    public function increment($field, $value = 1)
    {
        return $this->_modelObject->updateMany($this->_match, ['$inc' => [$field => $value]]);
    }

    public function decrement($field, $value = 1)
    {
        return $this->increment($field, -$value);
    }

    public function update(array $attributes)
    {
        return $this->_modelObject->updateMany($this->_match, ['$set' => $attributes]);
    }

    public function delete()
    {
        return $this->_modelObject->deleteMany($this->_match);
    }

    public function getQuery()
    {
        $query = [['$match' => count($this->_match) > 0 ? $this->_match : ['_id' => ['$exists' => true]]]];
        if (count($this->_options) > 0) {
            $query = array_merge($query, $this->_options);
        }
        return $query;
    }

    public function __call($name, $arguments)
    {
        if (method_exists($this->_modelObject, 'scope' . ucfirst($name))) {
            array_unshift($arguments, $this);
            return call_user_func_array([$this->_modelObject, 'scope' . ucfirst($name)], $arguments);
        }
        throw new \BadMethodCallException();
    }

}

<?php

namespace DenchikBY\MongoDB\Query;

use DenchikBY\MongoDB\Collection;
use DenchikBY\MongoDB\Model;
use MongoDB\DeleteResult;
use MongoDB\UpdateResult;

class Builder
{
    protected static $_operations = [
        '='  => '$eq',
        '!=' => '$ne',
        '>'  => '$gt',
        '<'  => '$lt',
        '>=' => '$gte',
        '<=' => '$lte',
        '%'  => '$regex',
    ];

    /** @var Model $_model */
    protected $_model;
    /** @var Model $_modelObject */
    protected $_modelObject;
    protected $_match = [];
    protected $_options = [];

    /**
     * @param string|Model $model
     */
    public function __construct($model)
    {
        $this->_model       = $model;
        $this->_modelObject = $model::init();
        foreach ($model::$globalScopes as $name) {
            $this->_modelObject->{$name}($this);
        }
    }

    /**
     * @param string[] $fields
     * @return $this
     */
    public function columns(array $fields)
    {
        $this->_options['$project'] = array_fill_keys($fields, 1);

        return $this;
    }

    /**
     * @param string $relationName
     * @return $this
     */
    public function join($relationName)
    {
        $relations = $this->_modelObject->getRelations();
        if (isset($relations[$relationName])) {
            $settings                    = $relations[$relationName];
            /** @var Model $relationClass */
            $relationClass               = $settings[0];
            $this->_options['$lookup'][] = [
                'from'         => $relationClass::getSource(),
                'localField'   => $settings[2],
                'foreignField' => $settings[3],
                'as'           => $relationName,
            ];
        }
        return $this;
    }

    /**
     * @param string $field
     * @param string $operation
     * @param string $value
     * @return $this
     */
    public function where($field, $operation = null, $value = null)
    {
        return $this->andOrWhereCommon($field, $operation, $value);
    }

    /**
     * @param string $field
     * @param string $operation
     * @param string $value
     * @return $this
     */
    public function orWhere($field, $operation = null, $value = null)
    {
        return $this->andOrWhereCommon($field, $operation, $value, '$or');
    }

    /**
     * @param string $field
     * @param int $minimum
     * @param int $maximum
     * @return $this
     */
    public function betweenWhere($field, $minimum, $maximum)
    {
        return $this->inWhereCommon([$field => ['$gte' => $minimum, '$lte' => $maximum]]);
    }

    /**
     * @param string $field
     * @param int $minimum
     * @param int $maximum
     * @return $this
     */
    public function notBetweenWhere($field, $minimum, $maximum)
    {
        return $this->inWhereCommon([$field => ['$not' => ['$gte' => $minimum, '$lte' => $maximum]]]);
    }

    /**
     * @param string $field
     * @param string[]|int[] $values
     * @return $this
     */
    public function inWhere($field, array $values)
    {
        return $this->inWhereCommon([$field => ['$in' => $values]]);
    }

    /**
     * @param string $field
     * @param string[]|int[] $values
     * @return $this
     */
    public function notInWhere($field, array $values)
    {
        return $this->inWhereCommon([$field => ['$nin' => $values]]);
    }

    /**
     * @param string $orderBy
     * @param string $direction
     * @return $this
     */
    public function orderBy($orderBy, $direction = 'asc')
    {
        $this->_options['$sort'] = [$orderBy => ($direction == 'asc' ? 1 : -1)];

        return $this;
    }

    /**
     * @param int $limit
     * @param int|null $offset
     * @return $this
     */
    public function limit($limit, $offset = null)
    {
        $this->_options['$limit'] = $limit;
        if ($offset > 0) {
            $this->_options['$skip'] = $offset;
        }

        return $this;
    }

    /**
     * @param string $group
     * @return $this
     */
    public function groupBy($group)
    {
        $this->_options['$group'] = ['_id' => '$' . $group];

        return $this;
    }

    /**
     * @return Collection
     */
    public function get()
    {
        return $this->_modelObject->aggregate($this->getQuery());
    }

    /**
     * @return Model
     */
    public function first()
    {
        return $this->_modelObject->findFirst($this->_match);
    }

    /**
     * @return int
     */
    public function count()
    {
        return $this->_modelObject->count($this->_match);
    }

    /**
     * @param string $field
     * @param int $value
     * @return UpdateResult
     */
    public function increment($field, $value = 1)
    {
        return $this->_modelObject->updateMany($this->_match, ['$inc' => [$field => $value]]);
    }

    /**
     * @param $field
     * @param int $value
     * @return UpdateResult
     */
    public function decrement($field, $value = 1)
    {
        return $this->increment($field, -$value);
    }

    /**
     * @param array $attributes
     * @return UpdateResult
     */
    public function update(array $attributes)
    {
        return $this->_modelObject->updateMany($this->_match, ['$set' => $attributes]);
    }

    /**
     * @return DeleteResult
     */
    public function delete()
    {
        return $this->_modelObject->deleteMany($this->_match);
    }

    /**
     * @param string $field
     * @return int
     */
    public function max($field)
    {
        $this->_options['$group'] = ['_id' => null, 'result' => ['$max' => '$' . $field]];
        return $this->_modelObject->aggregate($this->getQuery(), [], false)[0]->result;
    }

    /**
     * @param string $field
     * @return int
     */
    public function min($field)
    {
        $this->_options['$group'] = ['_id' => null, 'result' => ['$min' => '$' . $field]];
        return $this->_modelObject->aggregate($this->getQuery(), [], false)[0]->result;
    }

    /**
     * @param string $field
     * @return int
     */
    public function avg($field)
    {
        $this->_options['$group'] = ['_id' => null, 'result' => ['$avg' => '$' . $field]];
        return $this->_modelObject->aggregate($this->getQuery(), [], false)[0]->result;
    }

    /**
     * @param string $field
     * @return int
     */
    public function sum($field)
    {
        $this->_options['$group'] = ['_id' => null, 'result' => ['$sum' => '$' . $field]];
        return $this->_modelObject->aggregate($this->getQuery(), [], false)[0]->result;
    }

    /**
     * @param $field
     * @return UpdateResult
     */
    public function unsetField($field)
    {
        return $this->_modelObject->updateMany($this->_match, ['$unset' => [$field => '']]);
    }

    /**
     * @return array
     */
    public function getQuery()
    {
        $query = [['$match' => count($this->_match) > 0 ? $this->_match : ['_id' => ['$exists' => true]]]];
        if (count($this->_options) > 0) {
            $query = array_merge($query, $this->getOptions());
        }
        return $query;
    }

    /**
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        if (method_exists($this->_modelObject, 'scope' . ucfirst($name))) {
            array_unshift($arguments, $this);
            return call_user_func_array([$this->_modelObject, 'scope' . ucfirst($name)], $arguments);
        }
        throw new \BadMethodCallException();
    }

    /**
     * @return array
     */
    protected function getOptions()
    {
        $result = [];
        foreach ($this->_options as $key => $value) {
            if ($key == '$lookup') {
                foreach ($value as $join) {
                    $result[] = ['$lookup' => $join];
                }
            } else {
                $result[] = [$key => $value];
            }
        }
        return $result;
    }

    /**
     * @param array $expr
     * @return $this
     */
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

    /**
     * @param string $field
     * @param string $operation
     * @param string $value
     * @param string $operator
     * @return $this
     */
    protected function andOrWhereCommon($field, $operation, $value, $operator = '$and')
    {
        if ($field instanceof \Closure) {
            $q = new static($this->_model);
            $field($q);
            $expr = $q->getQuery()[0]['$match'];
        } else {
            if ($value == null) {
                $value     = $operation;
                $operation = '=';
            }
            $value = $this->_modelObject->castAttribute($field, $value);
            $expr  = [$field => [self::$_operations[$operation] => $value]];
        }
        if (!$this->_match) {
            $this->_match = $expr;
        } else {
            if (!isset($this->_match['$and']) && !isset($this->_match['$or'])) {
                $this->_match = [$operator => [$this->_match, $expr]];
            } else {
                array_push($this->_match[$operator], $expr);
            }
        }
        return $this;
    }
}

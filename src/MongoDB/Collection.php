<?php

namespace DenchikBY\MongoDB;

use ArrayAccess;
use Countable;
use Iterator;
use JsonSerializable;
use Phalcon\Text;

class Collection implements Iterator, ArrayAccess, Countable, JsonSerializable
{

    private $position = 0;

    private $array = [];

    public function __construct($array)
    {
        $this->array = (array)$array;
    }

    public function current()
    {
        return $this->array[$this->position];
    }

    public function next()
    {
        ++$this->position;
    }

    public function key()
    {
        return $this->position;
    }

    public function valid()
    {
        return isset($this->array[$this->position]);
    }

    public function rewind()
    {
        $this->position = 0;
    }

    public function toArray()
    {
        if (gettype($this->array[0]) == 'object') {
            $this->array = array_map(function ($item) {
                return $item->toArray();
            }, $this->array);
            return $this->array;
        } else {
            return $this->array;
        }
    }

    public function toJson()
    {
        return json_encode($this->toArray());
    }

    public function count()
    {
        return count($this->array);
    }

    public function eager($model, $field = null, $localKey = null, $foreignKey = '_id')
    {
        if ($field == null || $localKey == null) {
            $className = strtolower((new \ReflectionClass($model))->getShortName());
            if (Text::endsWith($className, 's')) {
                $className = substr($className, 0, -1);
            }
        }
        if ($field == null) $field = $className;
        if ($localKey == null) $localKey = $className . '_id';
        $keys = [];
        foreach ($this->array as $item) {
            if (!in_array($item->{$localKey}, $keys)) {
                $keys[] = $item->{$localKey};
            }
        }
        $result = $model::init()->find([$foreignKey => ['$in' => $keys]])->keyBy('_id');
        foreach ($this->array as $item) {
            $item->setRelation($field, $result[(string)$item->{$localKey}]);
        }
        return $this;
    }

    public function groupBy($field)
    {
        $results = [];
        foreach ($this->array as $key => $value) {
            if (gettype($value) == 'object') {
                if ($field == '_id') {
                    $results[$value->getId()][] = $value;
                } else {
                    if ($value->{$field} != null) {
                        $results[$value->{$field}][] = $value;
                    }
                }
            } else {
                if (isset($value[$field])) {
                    $results[$value[$field]][] = $value;
                }
            }
        }
        return $results;
    }

    public function keyBy($field)
    {
        $results = [];
        foreach ($this->array as $key => $value) {
            if (gettype($value) == 'object') {
                if ($field == '_id') {
                    $results[$value->getId()] = $value;
                } else {
                    if ($value->{$field} != null) {
                        $results[$value->{$field}] = $value;
                    }
                }
            } else {
                if (isset($value[$field])) {
                    $results[$value[$field]] = $value;
                }
            }
        }
        return $results;
    }

    public function pluck($field)
    {
        return array_map(function ($item) use ($field) {
            return $item->{$field};
        }, $this->array);
    }

    public function combine($key, $value)
    {
        $results = [];
        foreach ($this->array as $item) {
            $results[$item->{$key}] = $item->{$value};
        }
        return $results;
    }

    public function chunk($size)
    {
        return array_chunk($this->array, $size);
    }

    public function __toString()
    {
        if (gettype($this->array[0]) == 'object') {
            return json_encode($this->toArray());
        } else {
            return json_encode($this->array);
        }
    }

    public function offsetExists($offset)
    {
        return isset($this->array[$offset]);
    }

    public function offsetGet($offset)
    {
        return isset($this->array[$offset]) ? $this->array[$offset] : null;
    }

    public function offsetSet($offset, $value)
    {
        if (is_null($offset)) {
            $this->array[] = $value;
        } else {
            $this->array[$offset] = $value;
        }
    }

    public function offsetUnset($offset)
    {
        unset($this->array[$offset]);
    }

    public function jsonSerialize()
    {
        return $this->toJson();
    }

}

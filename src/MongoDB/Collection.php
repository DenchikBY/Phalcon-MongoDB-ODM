<?php

namespace DenchikBY\MongoDB;

use ArrayAccess;
use Countable;
use Iterator;
use JsonSerializable;
use Phalcon\Text;

class Collection implements Iterator, ArrayAccess, Countable, JsonSerializable
{
    /**
     * @var int
     *
     * Iterable position of current element.
     */
    private $position = 0;

    /**
     * @var array
     *
     * Collection data items.
     */
    private $array = [];

    /**
     * @param array $array
     */
    public function __construct($array)
    {
        $this->array = (array)$array;
    }

    /**
     * @return mixed
     */
    public function current()
    {
        return $this->array[$this->position];
    }

    public function next()
    {
        ++$this->position;
    }

    /**
     * @return int
     */
    public function key()
    {
        return $this->position;
    }

    /**
     * @return bool
     */
    public function valid()
    {
        return isset($this->array[$this->position]);
    }

    public function rewind()
    {
        $this->position = 0;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        if (is_object($this->array[0])) {
            $this->array = array_map(function (Model $item) {
                return $item->toArray();
            }, $this->array);
            return $this->array;
        } else {
            return $this->array;
        }
    }

    /**
     * @return string
     */
    public function toJson()
    {
        return json_encode($this->toArray());
    }

    /**
     * @return int
     */
    public function count()
    {
        return count($this->array);
    }

    /**
     * @param Model $model
     * @param string|null $field
     * @param string|null $localKey
     * @param string $foreignKey
     * @return $this
     */
    public function eager(Model $model, $field = null, $localKey = null, $foreignKey = '_id')
    {
        if ($field == null || $localKey == null) {
            $className = strtolower((new \ReflectionClass($model))->getShortName());
            if (Text::endsWith($className, 's')) {
                $className = substr($className, 0, -1);
            }
            if ($field == null) {
                $field = $className;
            }
            if ($localKey == null) {
                $localKey = $className . '_id';
            }
        }
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

    /**
     * @param string $field
     * @return Model[][]
     */
    public function groupBy($field)
    {
        $results = [];
        foreach ($this->array as $key => $value) {
            if (is_object($value)) {
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

    /**
     * @param string $field
     * @return array
     */
    public function keyBy($field)
    {
        $results = [];
        foreach ($this->array as $key => $value) {
            if (is_object($value)) {
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

    /**
     * @param string $field
     * @return array
     */
    public function pluck($field)
    {
        return array_map(function ($item) use ($field) {
            return $item->{$field};
        }, $this->array);
    }

    /**
     * @param string $key
     * @param string $value
     * @return array
     */
    public function combine($key, $value)
    {
        $results = [];
        foreach ($this->array as $item) {
            $results[$item->{$key}] = $item->{$value};
        }
        return $results;
    }

    /**
     * @param int $size
     * @return Model[][]
     */
    public function chunk($size)
    {
        return array_chunk($this->array, $size);
    }

    /**
     * @return string
     */
    public function __toString()
    {
        if (isset($this->array[0]) && is_object($this->array[0])) {
            return json_encode($this->toArray());
        } else {
            return json_encode($this->array);
        }
    }

    /**
     * @param int $offset
     * @return bool
     */
    public function offsetExists($offset)
    {
        return isset($this->array[$offset]);
    }

    /**
     * @param int $offset
     * @return Model|null
     */
    public function offsetGet($offset)
    {
        return isset($this->array[$offset]) ? $this->array[$offset] : null;
    }

    /**
     * @param int $offset
     * @param mixed $value
     */
    public function offsetSet($offset, $value)
    {
        if (is_null($offset)) {
            $this->array[] = $value;
        } else {
            $this->array[$offset] = $value;
        }
    }

    /**
     * @param int $offset
     */
    public function offsetUnset($offset)
    {
        unset($this->array[$offset]);
    }

    /**
     * @return string
     */
    public function jsonSerialize()
    {
        return $this->toJson();
    }
}

<?php

namespace DenchikBY\MongoDB;

use DenchikBY\MongoDB\Query\Builder;
use Phalcon\Di;
use Phalcon\Text;

abstract class Model extends \MongoDB\Collection
{

    protected $_id, $_attributes = [], $_relations = [];

    protected static $casts = [], $_db;

    public static $relations = [], $globalScopes = [];

    /**
     * @param array $attributes
     * @return Collection
     */
    public static function init($attributes = [])
    {
        $model = (new static(Di::getDefault()->get('mongo'), static::getDbName() . '.' . static::getSource()));
        if (count($attributes) > 0) {
            $model->fill($attributes);
        }
        return $model;
    }

    public static function create(array $attributes)
    {
        return static::init($attributes)->save();
    }

    public static function findById($id)
    {
        $result = static::init()->findOne(['_id' => new \MongoDB\BSON\ObjectId($id)]);
        return $result ? static::init((array)$result) : null;
    }

    public static function findFirst(array $params = [])
    {
        return static::init($params)->fill(static::init()->findOne($params));
    }

    public static function destroy($id)
    {
        return static::init()->deleteOne(['_id' => new \MongoDB\BSON\ObjectId($id)]);
    }

    public static function getSource()
    {
        return strtolower((new \ReflectionClass(static::class))->getShortName());
    }

    public static function getDbName()
    {
        if (!isset(self::$_db)) {
            self::$_db = Di::getDefault()->get('config')->mongodb->database;
        }
        return self::$_db;
    }

    public function find($filter = [], array $options = [], $fillModels = true)
    {
        return $this->getQueryResult(parent::find($filter, $options), $fillModels);
    }

    public function aggregate(array $pipeline, array $options = [], $fillModels = true)
    {
        return $this->getQueryResult(parent::aggregate($pipeline, $options), $fillModels);
    }

    protected function getQueryResult($result, $fillModels = true)
    {
        if ($fillModels) {
            $collections = [];
            foreach ($result as $row) {
                $collections[] = static::init($row);
            }
            return new \App\Library\Collection($collections);
        } else {
            return $result->toArray();
        }
    }

    public function getId($asString = true)
    {
        return $asString ? (string)$this->_id : $this->_id;
    }

    public function fill($data)
    {
        $data = (array)$data;
        if (isset($data['_id'])) {
            $this->_id = $data['_id'];
            unset($data['_id']);
        }
        foreach (static::$relations as $name => $settings) {
            if (isset($data[$name])) {
                if ($settings[1] == 'one') {
                    $value = $settings[0]::init($data[$name][0]);
                } else {
                    $value = [];
                    foreach ($data[$name] as $row) {
                        $value[] = $settings[0]::init($row);
                    }
                }
                $this->setRelation($name, new \App\Library\Collection($value));
                unset($data[$name]);
            }
        }
        $this->_attributes = array_merge($this->_attributes, $this->castArrayAttributes($data));
        return $this;
    }

    public function save(array $attributes = null)
    {
        if ($attributes != null) {
            $this->fill($attributes);
        }
        $this->beforeSave();
        if (isset($this->_id)) {
            $this->beforeUpdate();
            $this->updateOne(['_id' => $this->_id], ['$set' => $this->_attributes]);
        } else {
            $this->beforeCreate();
            $result = $this->insertOne($this->_attributes);
            return $this->fill($result);
        }
        return $this;
    }

    public function update(array $attributes)
    {
        $this->beforeSave();
        $this->beforeUpdate();
        $this->fill($attributes);
        $this->updateOne(['_id' => $this->_id], ['$set' => $attributes]);
        return $this;
    }

    public function increment($argument, $value = 1)
    {
        $this->{$argument} += $value;
        $this->updateOne(['_id' => $this->_id], ['$set' => [$argument => $this->{$argument}]]);
        return $this;
    }

    public function decrement($argument, $value = 1)
    {
        $this->{$argument} -= $value;
        $this->updateOne(['_id' => $this->_id], ['$set' => [$argument => $this->{$argument}]]);
        return $this;
    }

    public function delete()
    {
        return $this->deleteOne(['_id' => $this->getId(false)]);
    }

    public function beforeCreate()
    {
        $this->created_at = new \MongoDB\BSON\UTCDateTime(round(microtime(true) * 1000) . '');
    }

    public function beforeUpdate()
    {
        $this->updated_at = new \MongoDB\BSON\UTCDateTime(round(microtime(true) * 1000) . '');
    }

    public function beforeSave()
    {
    }

    protected function castArrayAttributes(array $data)
    {
        foreach ($data as $param => $value) {
            $methodName = 'set' . Text::camelize($param);
            $data[$param] = method_exists($this, $methodName) ? $this->{$methodName}($value) : $this->castAttribute($param, $value);
        }
        return $data;
    }

    protected function castAttribute($param, $value)
    {
        if (isset(static::$casts[$param])) {
            $type = static::$casts[$param];
            if ($type == 'integer') return (int)$value;
            else if ($type == 'float') return (float)$value;
            else if ($type == 'boolean') return (bool)$value;
            else if ($type == 'string') return (string)$value;
            else if ($type == 'array') return (array)$value;
            else if ($type == 'object') return (object)$value;
            else if ($type == 'id') return ($value instanceof \MongoDB\BSON\ObjectId) ? $value : new \MongoDB\BSON\ObjectId((string)$value);
        }
        return $value;
    }

    public function setRelation($name, $value)
    {
        $this->_relations[$name] = $value;
    }

    protected function hasOne($model, $field, $localKey = null, $foreignKey = '_id')
    {
        if ($localKey == null) {
            $localKey = $this->getIdFieldName($model);
        }
        $result = $model::init()->findFirst([$foreignKey => ($localKey == '_id' ? $this->getId(false) : $this->{$localKey})]);
        $this->setRelation($field, $result);
        return $result;
    }

    protected function hasMany($model, $field, $localKey = '_id', $foreignKey = null)
    {
        if ($foreignKey == null) {
            $foreignKey = $this->getIdFieldName($this);
        }
        $result = $model::init()->find([$foreignKey => ($localKey == '_id' ? $this->getId(false) : $this->{$localKey})]);
        $this->setRelation($field, $result);
        return $result;
    }

    protected function loadRelation($name)
    {
        $settings = static::$relations[$name];
        if ($settings[1] == 'one') {
            return $this->hasOne($settings[0], $name, $settings[2], $settings[3]);
        } else {
            return $this->hasMany($settings[0], $name, $settings[2], $settings[3]);
        }
    }

    protected function getIdFieldName($model)
    {
        $className = strtolower((new \ReflectionClass($model))->getShortName());
        if (Text::endsWith($className, 's')) {
            $className = substr($className, 0, -1);
        }
        return $className . '_id';
    }

    public function toArray()
    {
        $this->_attributes = array_map(function ($item) {
            if (gettype($item) == 'object') {
                return (string)$item;
            }
            return $item;
        }, $this->_attributes);
        $this->_relations = array_map(function ($item) {
            if (gettype($item) == 'object') {
                return $item->toArray();
            } else if (gettype($item) == 'array') {
                return array_map(function ($item1) {
                    return $item1->toArray();
                }, $item);
            }
            return $item;
        }, $this->_relations);
        $result = array_merge($this->_attributes, $this->_relations);
        $result['id'] = (string)$this->_id;
        return $result;
    }

    public static function query()
    {
        return new Builder(static::class);
    }

    public static function __callStatic($name, $arguments)
    {
        if (method_exists(static::class, 'scope' . ucfirst($name))) {
            array_unshift($arguments, static::query());
            return call_user_func_array([static::init(), 'scope' . ucfirst($name)], $arguments);
        }
        return call_user_func_array([static::query(), $name], $arguments);
    }

    public function __get($name)
    {
        $methodName = 'get' . Text::camelize($name);
        return isset($this->_attributes[$name]) ? (method_exists($this, $methodName) ? $this->{$methodName}($this->_attributes[$name]) : $this->_attributes[$name])
            : (isset($this->_relations[$name]) ? $this->_relations[$name]
                : (isset(static::$relations[$name]) ? $this->loadRelation($name) : null));
    }

    public function __set($name, $value)
    {
        $methodName = 'set' . Text::camelize($name);
        $this->_attributes[$name] = method_exists($this, $methodName) ? $this->{$methodName}($value) : $this->castAttribute($name, $value);
    }

    public function __toString()
    {
        return json_encode($this->toArray());
    }

}

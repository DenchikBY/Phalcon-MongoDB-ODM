<?php

namespace DenchikBY\MongoDB;

use DenchikBY\MongoDB\Query\Builder;
use MongoDB\BSON\ObjectID;
use MongoDB\BSON\UTCDateTime;
use Phalcon\Di;
use Phalcon\Text;

/**
 * @property UTCDateTime created_at
 * @property UTCDateTime updated_at
 */
class Model extends \MongoDB\Collection
{
    const EVENT_BEFORE_SAVE   = 'beforeSave';
    const EVENT_AFTER_SAVE    = 'afterSave';
    const EVENT_BEFORE_CREATE = 'beforeCreate';
    const EVENT_AFTER_CREATE  = 'afterCreate';
    const EVENT_BEFORE_UPDATE = 'beforeUpdate';
    const EVENT_AFTER_UPDATE  = 'afterUpdate';
    const EVENT_BEFORE_DELETE = 'beforeDelete';
    const EVENT_AFTER_DELETE  = 'afterDelete';

    public static $relations = [];
    public static $globalScopes = [];

    protected static $casts = [];
    protected static $_db;

    /** @var ObjectID */
    protected $_id;
    protected $_attributes = [];
    protected $_relations = [];

    /**
     * @param array $attributes
     * @return static
     */
    public static function init($attributes = [])
    {
        $model = (new static(Di::getDefault()->get('mongo'), static::getDbName(), static::getSource()));
        if (count($attributes) > 0) {
            $model->fill($attributes);
        }
        return $model;
    }

    /**
     * @param array $attributes
     * @return $this
     */
    public static function create(array $attributes)
    {
        return static::init($attributes)->save();
    }

    /**
     * @param string $id
     * @return static|null
     */
    public static function findById($id)
    {
        $result = static::init()->findOne(['_id' => new ObjectID($id)]);
        return $result ? static::init((array)$result) : null;
    }

    /**
     * @param array $params
     * @return $this
     */
    public static function findFirst(array $params = [])
    {
        return static::init($params)->fill(static::init()->findOne($params));
    }

    /**
     * @param string $id
     * @return bool
     */
    public static function destroy($id)
    {
        return static::init()->deleteOne(['_id' => new ObjectID($id)]);
    }

    /**
     * @return string
     */
    public static function getSource()
    {
        return strtolower((new \ReflectionClass(static::class))->getShortName());
    }

    /**
     * @return string
     */
    public static function getDbName()
    {
        if (!isset(self::$_db)) {
            self::$_db = Di::getDefault()->get('config')->mongodb->database;
        }
        return self::$_db;
    }

    /**
     * @return UTCDateTime
     */
    public static function mongoTime()
    {
        return new UTCDateTime(round(microtime(true) * 1000) . '');
    }

    /**
     * @return Builder
     */
    public static function query()
    {
        return new Builder(static::class);
    }

    /**
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public static function __callStatic($name, $arguments)
    {
        if (method_exists(static::class, 'scope' . ucfirst($name))) {
            array_unshift($arguments, static::query());
            return call_user_func_array([static::init(), 'scope' . ucfirst($name)], $arguments);
        }
        return call_user_func_array([static::query(), $name], $arguments);
    }

    /**
     * @param array $filter
     * @param array $options
     * @param bool $fillModels
     * @return Collection
     */
    public function find($filter = [], array $options = [], $fillModels = true)
    {
        return $this->getQueryResult(parent::find($filter, $options), $fillModels);
    }

    /**
     * @param array $pipeline
     * @param array $options
     * @param bool $fillModels
     * @return Collection
     */
    public function aggregate(array $pipeline, array $options = [], $fillModels = true)
    {
        return $this->getQueryResult(parent::aggregate($pipeline, $options), $fillModels);
    }

    /**
     * @param bool $asString
     * @return ObjectID|string
     */
    public function getId($asString = true)
    {
        return $asString ? (string)$this->_id : $this->_id;
    }

    /**
     * @param array $data
     * @return $this
     */
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
                    $this->setRelation($name, $value);
                } else {
                    $value = [];
                    foreach ($data[$name] as $row) {
                        $value[] = $settings[0]::init($row);
                    }
                    $this->setRelation($name, new Collection($value));
                }
                unset($data[$name]);
            }
        }
        $this->_attributes = array_merge($this->_attributes, $this->castArrayAttributes($data));

        return $this;
    }

    /**
     * @param array|null $attributes
     * @return $this
     */
    public function save(array $attributes = null)
    {
        if ($attributes != null) {
            $this->fill($attributes);
        }
        $this->event(self::EVENT_BEFORE_SAVE);
        if (isset($this->_id)) {
            $this->event(self::EVENT_BEFORE_UPDATE);
            $this->updateOne(['_id' => $this->_id], ['$set' => $this->_attributes]);
            $this->event(self::EVENT_AFTER_UPDATE);
        } else {
            $this->event(self::EVENT_BEFORE_CREATE);
            $insertResult = $this->insertOne($this->_attributes);
            $this->_id    = $insertResult->getInsertedId();
            $this->event(self::EVENT_AFTER_CREATE);
        }
        $this->event(self::EVENT_AFTER_SAVE);

        return $this;
    }

    /**
     * @param array $attributes
     * @return $this
     */
    public function update(array $attributes)
    {
        $this->event(self::EVENT_BEFORE_SAVE);
        $this->event(self::EVENT_BEFORE_UPDATE);
        $this->fill($attributes);
        $this->updateOne(['_id' => $this->_id], ['$set' => $attributes]);
        $this->event(self::EVENT_AFTER_UPDATE);
        $this->event(self::EVENT_AFTER_SAVE);

        return $this;
    }

    /**
     * @param string $argument
     * @param int $value
     * @return $this
     */
    public function increment($argument, $value = 1)
    {
        $this->{$argument} += $value;
        $this->updateOne(['_id' => $this->_id], ['$set' => [$argument => $this->{$argument}]]);

        return $this;
    }

    /**
     * @param string $argument
     * @param int $value
     * @return $this
     */
    public function decrement($argument, $value = 1)
    {
        $this->{$argument} -= $value;
        $this->updateOne(['_id' => $this->_id], ['$set' => [$argument => $this->{$argument}]]);

        return $this;
    }

    /**
     * @return $this
     */
    public function delete()
    {
        $this->event(self::EVENT_BEFORE_DELETE);
        $this->deleteOne(['_id' => $this->getId(false)]);
        $this->event(self::EVENT_AFTER_DELETE);

        return $this;
    }

    /**
     * @param string $field
     * @return bool
     */
    public function unsetField($field)
    {
        $path     = explode('.', $field);
        $lastPart = end($path);
        if (count($path) > 1) {
            $ref = $this->getAttrRef($field, 1);
        } else {
            $ref = &$this->_attributes;
        }
        if ($ref != false) {
            $type = gettype($ref);
            if ($type == 'object' && isset($ref->{$lastPart})) {
                unset($ref->{$lastPart});
            } else if ($type == 'array' && isset($ref[$lastPart])) {
                unset($ref[$lastPart]);
            } else {
                return false;
            }
            $this->updateOne(['_id' => $this->_id], ['$unset' => [$field => '']]);
            return true;
        }
        return false;
    }

    /**
     * Event EVENT_BEFORE_CREATE handler
     */
    public function beforeCreate()
    {
        $this->created_at = self::mongoTime();
    }


    /**
     * Event EVENT_AFTER_CREATE handler
     */
    public function afterCreate()
    {
    }

    /**
     * Event EVENT_BEFORE_UPDATE handler
     */
    public function beforeUpdate()
    {
        $this->updated_at = self::mongoTime();
    }

    /**
     * Event EVENT_AFTER_UPDATE handler
     */
    public function afterUpdate()
    {
    }

    /**
     * Event EVENT_BEFORE_SAVE handler
     */
    public function beforeSave()
    {
    }

    /**
     * Event EVENT_AFTER_SAVE handler
     */
    public function afterSave()
    {
    }

    /**
     * Event EVENT_BEFORE_DELETE handler
     */
    public function beforeDelete()
    {
    }

    /**
     * Event EVENT_AFTER_DELETE handler
     */
    public function afterDelete()
    {
    }

    /**
     * @param string $param
     * @param mixed $value
     * @return ObjectID|null
     */
    public function castAttribute($param, $value)
    {
        if (isset(static::$casts[$param])) {
            $type = static::$casts[$param];
            if ($type == 'id') {
                if (!($value instanceof ObjectID)) {
                    try {
                        return new ObjectID((string)$value);
                    } catch (\Exception $e) {
                        return null;
                    }
                }
                return $value;
            } else if (in_array($type, ['integer', 'float', 'boolean', 'string', 'array', 'object'])) {
                settype($value, $type);
            }
        }
        return $value;
    }

    /**
     * @param string $name
     * @param mixed $value
     */
    public function setRelation($name, $value)
    {
        $this->_relations[$name] = $value;
    }

    /**
     * @param array $params
     * @return array
     */
    public function toArray($params = [])
    {
        $attributes = array_merge(['id' => (string)$this->_id], $this->_attributes);
        if (isset($params['include']) || isset($params['exclude'])) {
            $attributes = array_filter($attributes, function ($value, $key) use ($params) {
                if (isset($params['include'])) {
                    return in_array($key, $params['include']);
                }
                return !in_array($key, $params['exclude']);
            }, ARRAY_FILTER_USE_BOTH);
        }
        $attributes = array_map(function ($item) {
            if (is_object($item)) {
                if ($item instanceof ObjectID) {
                    return (string)$item;
                } elseif ($item instanceof UTCDateTime) {
                    return $item->toDateTime()->format(DATE_ISO8601);
                } else {
                    return (array)$item;
                }
            }
            return $item;
        }, $attributes);
        $relations  = array_map(function ($item) {
            if (is_object($item)) {
                return $item->toArray();
            } else if (is_array($item)) {
                return array_map(function ($item1) {
                    return $item1->toArray();
                }, $item);
            }
            return $item;
        }, $this->_relations);
        return array_merge($attributes, $relations);
    }

    /**
     * @param string $name
     * @return mixed|null
     */
    public function __get($name)
    {
        $methodName = 'get' . Text::camelize($name);
        return isset($this->_attributes[$name]) ? (method_exists($this,
            $methodName) ? $this->{$methodName}($this->_attributes[$name]) : $this->_attributes[$name])
            : (isset($this->_relations[$name]) ? $this->_relations[$name]
                : (isset(static::$relations[$name]) ? $this->loadRelation($name) : null));
    }

    /**
     * @param string $name
     * @param mixed $value
     */
    public function __set($name, $value)
    {
        $methodName               = 'set' . Text::camelize($name);
        $this->_attributes[$name] = method_exists($this,
            $methodName) ? $this->{$methodName}($value) : $this->castAttribute($name, $value);
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return json_encode($this->toArray());
    }

    /**
     * @param Collection $result
     * @param bool $fillModels
     * @return Collection|array
     */
    protected function getQueryResult($result, $fillModels = true)
    {
        if ($fillModels) {
            $collections = [];
            foreach ($result as $row) {
                $collections[] = static::init($row);
            }
            return new Collection($collections);
        } else {
            return $result->toArray();
        }
    }

    /**
     * @param array $data
     * @return array
     */
    protected function castArrayAttributes(array $data)
    {
        foreach ($data as $param => $value) {
            $methodName   = 'set' . Text::camelize($param);
            $data[$param] = method_exists($this,
                $methodName) ? $this->{$methodName}($value) : $this->castAttribute($param, $value);
        }
        return $data;
    }

    /**
     * @param string $model
     * @param string $field
     * @param string|null $localKey
     * @param string $foreignKey
     * @return Model|null
     */
    protected function hasOne($model, $field, $localKey = null, $foreignKey = '_id')
    {
        if ($localKey == null) {
            $localKey = $this->getIdFieldName($model);
        }
        $result = $model::init()->findFirst([$foreignKey => ($localKey == '_id' ? $this->getId(false) : $this->{$localKey})]);
        $this->setRelation($field, $result);
        return $result;
    }

    /**
     * @param string $model
     * @param string $field
     * @param string $localKey
     * @param string|null $foreignKey
     * @return Model[]|null
     */
    protected function hasMany($model, $field, $localKey = '_id', $foreignKey = null)
    {
        if ($foreignKey == null) {
            $foreignKey = $this->getIdFieldName($this);
        }
        $result = $model::init()->find([$foreignKey => ($localKey == '_id' ? $this->getId(false) : $this->{$localKey})]);
        $this->setRelation($field, $result);
        return $result;
    }

    /**
     * @param string $name
     * @return Model|Model[]|null
     */
    protected function loadRelation($name)
    {
        $settings = static::$relations[$name];
        if ($settings[1] == 'one') {
            return $this->hasOne($settings[0], $name, $settings[2], $settings[3]);
        } else {
            return $this->hasMany($settings[0], $name, $settings[2], $settings[3]);
        }
    }

    /**
     * @param string $model
     * @return string
     */
    protected function getIdFieldName($model)
    {
        $className = strtolower((new \ReflectionClass($model))->getShortName());
        if (Text::endsWith($className, 's')) {
            $className = substr($className, 0, -1);
        }
        return $className . '_id';
    }

    /**
     * @param string $name
     */
    protected function event($name)
    {
        if (method_exists($this, $name)) {
            $this->{$name}();
        }
    }

    /**
     * @param string $path
     * @param int $rightOffset
     * @return array|bool|mixed
     */
    protected function getAttrRef($path, $rightOffset = 0)
    {
        $path   = explode('.', $path);
        $length = count($path) - $rightOffset;
        $return = &$this->_attributes;
        for ($i = 0; $i <= $length - 1; ++$i) {
            if (isset($return->{$path[$i]})) {
                if ($i == $length - 1) {
                    return $return->{$path[$i]};
                } else {
                    $return = &$return->{$path[$i]};
                }
            } else if (isset($return[$path[$i]])) {
                if ($i == $length - 1) {
                    return $return[$path[$i]];
                } else {
                    $return = &$return[$path[$i]];
                }
            } else {
                return false;
            }
        }
        return $return;
    }
}

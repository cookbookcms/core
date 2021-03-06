<?php 
/*
 * This file is part of the Congraph package.
 *
 * (c) Nikola Plavšić <nikolaplavsic@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Congraph\Core\Repositories;

use stdClass;
use ArrayAccess;
use Exception;
use Closure;
use Congraph\Core\Facades\Resolver;
use Congraph\Core\Facades\Trunk;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Carbon\Carbon;

/**
 * Abstract object class used for data transfer
 * 
 * @author  	Nikola Plavšić <nikolaplavsic@gmail.com>
 * @copyright  	Nikola Plavšić <nikolaplavsic@gmail.com>
 * @package 	congraph/core
 * @since 		0.1.0-alpha
 * @version  	0.1.0-alpha
 */
abstract class DataTransferObject implements ArrayAccess, Arrayable, Jsonable
{
	protected $counter = 0;
	/**
	 * Main transfer data
	 * 
	 * @var array
	 */
	protected $data;

	/**
	 * Meta data
	 * 
	 * @var array
	 */
	protected $meta = [];

	/**
	 * Data parameters
	 * 
	 * @var array
	 */
	protected $params = [];

	/**
	 * Whether data is a collection or item
	 * 
	 * @var boolean
	 */
	protected $isCollection;

	/**
	 * List of relations to be included in result
	 * 
	 * @var array
	 */
	protected $relations = [];

	/**
	 * List of included objects
	 * 
	 * @var array
	 */
	protected $included = [];

	/**
	 * Load queue
	 * 
	 * @var array
	 */
	protected static $loadQueue = [];


	/**
	 * Creates new DataTransferObject
	 * 
	 * @param stdClass|array $data
	 */
	public function __construct($data)
	{
		$this->setData($data);
		Trunk::put($this);
	}

	/**
	 * Set transfer data
	 * 
	 * @param mixed $data object or collection of objects
	 */
	abstract public function setData($data);


	/**
	 * Get transfer data
	 * 
	 * @return  mixed
	 */
	public function getData()
	{
		return $this->data;
	}

	/**
	 * Set meta data
	 * 
	 * @param string|array 	$key
	 * @param mixed 		$value
	 */
	public function setMeta($key, $value = null)
	{
		if(is_array($key))
		{
			$this->meta = array_merge($this->meta, $key);
			return $this;
		}

		$this->meta[$key] = $value;

		return $this;
	}

	/**
	 * Get meta data
	 * 
	 * @param string|array 	$key
	 * @param mixed 		$value
	 */
	public function getMeta($key = null)
	{
		if(is_null($key))
		{
			return $this->meta;
		}
		if( isset($this->meta[$key]) )
		{
			return $this->meta[$key];
		}
		return null;
	}

	/**
	 * Set parameter data
	 * 
	 * @param string|array 	$key
	 * @param mixed 		$value
	 */
	public function setParams($key, $value = null)
	{
		if(is_array($key))
		{
			$this->params = array_merge_recursive($this->params, $key);
			return $this;
		}

		$this->params[$key] = $value;

		return $this;
	}


	/**
	 * Get params
	 * 
	 * @param string|array 	$key
	 * @param mixed 		$value
	 */
	public function getParams($key = null)
	{
		if(is_null($key))
		{
			return $this->params;
		}

		return $this->params[$key];
	}

	/**
	 * Preload relationships
	 * 
	 * @param  array $relations
	 * @return void
	 */
	public function load($relations = [])
	{
		$this->addRelations($relations);
		
		$this->clearQueue();

		$this->queueUnresolvedObjects($this->data, $this->relations);

		$this->loadQueue();
	}

	/**
	 * Add relation properties
	 * 
	 * @param array | string $relations
	 * 
	 * @return void
	 */
	public function addRelations($relations)
	{
		if(is_numeric($relations))
		{
			
			$relations = intval($relations);
			$this->relations = $relations;
		}
		else
		{
			$relations = ( is_array($relations) ) ? $relations : explode(',', strval($relations));
			foreach ($relations as $prop)
			{
				if( ! in_array($prop, $this->relations) )
				{
					$this->relations[] = trim($prop);
				}
			}
		}

		if($this instanceof Collection)
		{
			foreach ($this->data as $model)
			{
				$model->addRelations($this->relations);
			}
		}
	}

	protected function clearQueue()
	{
		self::$loadQueue = [];
	}

	public function queueUnresolvedObjects($data, $relations)
	{
		if( is_object($data) )
		{

			if( $data instanceof Model )
			{
				$data = $data->getData();
			}
			if( ! $this->resolved($data) )
			{
				$this->addToQueue($data, $relations);
				return;
			}

			$data = get_object_vars($data);

		}

		if( is_array($data) )
		{
			foreach ($data as $key => $value)
			{
				if(is_int($key))
				{
					$this->queueUnresolvedObjects($value, $relations);
				}
				if($this->hasRelation($key, $value, $relations))
				{
					$nestedRelations = $this->getNestedRelations($key, $relations);
					$this->queueUnresolvedObjects($value, $nestedRelations);
				}
			}
		}
	}

	/**
	 * Check if object is resolved
	 * @return object $obj
	 */
	protected function resolved($obj)
	{
		if( ! is_object($obj) )
		{
			return true;
		}

		$data = get_object_vars($obj);
		if( count($data) == 2
			&& array_key_exists('id', $data)
			&& array_key_exists('type', $data)
		)
		{
			return false;
		}

		return true;
	}

	/**
	 * Check if object is resolved
	 * @return object $obj
	 */
	protected function isUnresolvedAsset($obj)
	{
		if( ! is_object($obj) )
		{
			return false;
		}

		$data = get_object_vars($obj);
		if( count($data) == 2
			&& array_key_exists('id', $data)
			&& array_key_exists('type', $data)
			&& $data['type'] == 'file'
		)
		{
			return true;
		}

		return false;
	}

	

	protected function addToQueue($object, $relations)
	{
		if( ! array_key_exists($object->type, self::$loadQueue) )
		{
			self::$loadQueue[$object->type] = [];
		}
		$relationsKey = base64_encode(json_encode($relations));
		if( ! array_key_exists($relationsKey, self::$loadQueue[$object->type]) )
		{
			self::$loadQueue[$object->type][$relationsKey] = [ 'type' => $object->type, 'ids' => [], 'relations' => $relations ];
		}
		self::$loadQueue[$object->type][$relationsKey]['ids'][] = $object->id;

	}

	/**
	 * Check if relation exists
	 * 
	 * @param  string	$key
	 * @param  mixed 	$value
	 * @param  array	$relations
	 * 
	 * @return boolean
	 */
	protected function hasRelation($key, $value, $relations)
	{
		if(is_numeric($relations)) {
			if($relations > 0) {
				return true;
			}
			return false;
		}

		foreach ($relations as $prop)
		{
			if($prop == 'assets')
			{
				if( $key === 'fields' || (! $this->resolved($value) && is_object($value) && property_exists($value, 'type') && $value->type == 'file'))
				{
					return true;
				}
				if(is_array($value) && ! empty($value))
				{
					$isAsset = true;
					foreach ($value as $subValue)
					{
						if(!$this->isUnresolvedAsset($subValue)) {
							$isAsset = false;
							break;
						}
					}
					if($isAsset) return true;
				}
			}
			if($prop === $key || 0 === strpos($prop, $key.'.'))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Get nested relations for field
	 * 
	 * @param  string	$prop
	 * @param  array	$relations
	 * 
	 * @return boolean
	 */
	protected function getNestedRelations($key, $relations)
	{
		if(is_numeric($relations))
		{
			if($key == 'fields')
			{
				return $relations;
			}
			if($relations - 1 > 0)
			{
				return $relations - 1;
			}
			return [];
		}

		$nestedRelations = [];
		foreach ($relations as $prop)
		{
			if($prop === 'assets') {
				$nestedRelations[] = $prop;
				continue;
			}
			if(0 === strpos($prop, $key.'.'))
			{
				$newRelation = substr($prop, strlen($key) + 1);
				if( strlen($newRelation) !== 0)
				{
					$nestedRelations[] = $newRelation;
				}
			}
		}
		$nestedRelations = array_unique($nestedRelations);
		return $nestedRelations;
	}

	/**
	 * Load objects from queue
	 * 
	 * @return void
	 */
	protected function loadQueue()
	{
		$loadQueue = self::$loadQueue;

		foreach ($loadQueue as $type => $queries)
		{
			foreach ($queries as $query)
			{
				$ids = [];
				foreach ($query['ids'] as $id)
				{
					if( Trunk::has([$id, $query['relations']], $type) )
					{
						$object = Trunk::get([$id, $query['relations']], $type);
						$this->addIncludes($object);
						continue;
					}

					$ids[] = $id;
				}

				if( empty($ids) )
				{
					continue;
				}

				$locale = null;

				if( ! empty($this->meta['locale']) )
				{
					$locale = $this->meta['locale'];
				}

				$result = Resolver::resolve($query['type'], $query['ids'], $query['relations'], $locale);

				Trunk::put($result);
				
				$this->addIncludes($result);
			}
		}
	}

	/**
	 * Add items to include
	 * 
	 * @param mixed $includes
	 *
	 * @return void
	 */
	public function addIncludes($includes)
	{
		if($includes instanceof Model)
		{
			$this->addItemToInclude($includes);
		}
		else
		{
			foreach ($includes as $item)
			{
				$this->addItemToInclude($item);
			}
		}
		
		if($includes instanceof DataTransferObject)
		{
			$this->addIncludes($includes->getIncludes());
		}

		if($this instanceof Collection)
		{
			foreach ($this->data as $model)
			{
				$model->addIncludes($includes);
			}
		}
	}

	public function addItemToInclude($item)
	{
		$this->included[$this->objectKey($item)] = $item;
	}

	public function getIncludes($keyed = false)
	{
		$includes = [];

		foreach ($this->included as $key => $object)
		{
			if( ! array_key_exists($key, $includes) )
			{
				$includes[$key] = $object;
				$objIncludes = $object->getIncludes(true);
				$includes = $includes + $objIncludes;
			}
		}

		if( ! $keyed )
		{
			$includes = array_values($includes);
			$includes = array_unique($includes);
		}

		return $includes;
	}

	// ArrayAccess functions
	

	/**
	 * Set data at specific offset
	 * 
	 * @param  string $offset
	 * @param  string $value
	 * @return mixed
	 */
	public function offsetSet($offset, $value)
	{
		if (is_null($offset))
		{
			if( ! $this->isCollection)
			{
				throw new Exception('You need to specify a valid key for model.');
			}

			$this->data[] = $value;
		} 
		else 
		{
			if($this->isCollection)
			{
				$this->data[$offset] = $value;
				return;
			}

			$this->data->{$offset} = $value;
		}
	}

	/**
	 * Check if provided offset exists
	 * 
	 * @param  string $offset
	 * 
	 * @return boolean
	 */
	public function offsetExists($offset) {
		return ($this->isCollection)?
				 isset($this->data[$offset])
				:isset($this->data->{$offset});
	}

	/**
	 * Unset the provided offset
	 * 
	 * @param  string $offset
	 * 
	 * @return boolean
	 */
	public function offsetUnset($offset) {
		if($this->isCollection)
		{
			unset($this->data[$offset]);
		}
		else
		{
			unset($this->data->{$offset});
		}
	}

	/**
	 * Get the provided offset
	 * 
	 * @param  string $offset
	 * 
	 * @return boolean
	 */
	public function offsetGet($offset)
	{
		if($this->isCollection)
		{
			return isset($this->data[$offset]) ? $this->data[$offset] : null;
		}
		else
		{
			return isset($this->data->{$offset}) ? $this->data->{$offset} : null;
		}
		
	}

	// Arrayable functions
	
	/**
	 * Get the instance as an array.
	 *
	 * @param  boolean $includeMetaData
	 *
	 * @return array
	 */
	public function toArray($includeMetaData = false, $nestedInclude = true, $callback = null)
	{
		$data = $this->transformToArray($this->data, $nestedInclude, [], $callback);
		
		if( ! $includeMetaData )
		{
			return $data;
		}

		$result = [];
		$result['data'] = $data;
		
		if( ! empty($this->included) && ! $nestedInclude && $includeMetaData)
		{
			$result['included'] = $this->transformToArray($this->getIncludes(), false, [], $callback);
		}

		if( $includeMetaData && ! empty($this->meta) )
		{
			$result['meta'] = $this->meta;
		}

		

		return $result;
	}

	// Jsonable functions
	
	/**
	 * Get the instance as an json string.
	 *
	 * @param  int $optinos json options
	 * @param  boolean $includeMetaData
	 *
	 * @return string
	 */
	public function toJson($options = 0, $includeMetaData = false, $nestedInclude = true, $callback = null)
	{
		$data = $this->toArray($includeMetaData, $nestedInclude, $callback);
		return json_encode($data, $options);
	}

	public function __toString()
	{
		return $this->toJson(0, false, false);
	}


	// JsonSerializable functions

	/**
     * Convert the object into something JSON serializable.
     *
     * @return array
     */
    public function jsonSerialize()
    {
        return $this->toArray();
    }
	
	/**
	 * Convert Object to array deep
	 * 
	 * @param  object $data
	 * 
	 * @return array
	 */
	public function transformToArray($data, $nestedInclude = true, $extraIncludes = [], $callback = null)
	{

		if (is_object($data))
		{

			if( $nestedInclude && ! $this->resolved($data) )
			{
				$objectKey = $this->objectKey($data);
				
				if(array_key_exists($objectKey, $extraIncludes))
				{
					$data = $extraIncludes[$objectKey];
				}
				else
				{
					$data = $this->getIncluded($data);
				}
				
			}

			if($data instanceof DataTransferObject)
			{
				// $data->addIncludes($this->included);
				$data = $data->transformToArray($data->getData(), $nestedInclude, $this->included + $extraIncludes);
			}
			elseif($data instanceof Carbon)
			{
				$data = $data->tz('UTC')->toIso8601String();
			}
			else
			{
				$data = get_object_vars($data);
			}
		}


		
		if ( is_array($data) && ! empty($data) )
		{

			foreach ($data as $key => &$value)
			{
				$this->transformAttribute($data, $key, $value, $nestedInclude, $this->included + $extraIncludes, $callback);
			}
			
		}

		if(is_callable($callback))
		{
			$data = call_user_func_array($callback, [[], 0, $data, $nestedInclude, $extraIncludes]);
		}

		return $data;
	}

	protected function transformAttribute(&$array, $key, $value, $nestedInclude, $extraIncludes, $callback = null)
	{
		// // echo $key . ' = ' . $value . ' ';
		// $this->counter++;
		// if($this->counter > 10) {
		// 	die();
		// }
		if (is_object($value))
		{

			if( $nestedInclude && ! $this->resolved($value) )
			{
				$objectKey = $this->objectKey($value);
				
				if(array_key_exists($objectKey, $extraIncludes))
				{
					$value = $extraIncludes[$objectKey];
				}
				else
				{
					$value = $this->getIncluded($value);
				}
				
			}

			if($value instanceof DataTransferObject)
			{
				// $data->addIncludes($this->included);
				$value = $value->transformToArray($value->getData(), $nestedInclude, $this->included + $extraIncludes);
			}
			elseif($value instanceof Carbon)
			{
				$value = $value->tz('UTC')->toIso8601String();
			}
			else
			{
				$value = get_object_vars($value);
			}
		}
		
		if ( is_array($value) && ! empty($value) )
		{

			foreach ($value as $k => $v)
			{
				$this->transformAttribute($value, $k, $v, $nestedInclude, $this->included + $extraIncludes, $callback);
			}
			
		}


		if(is_callable($callback))
		{
			$value = call_user_func_array($callback, [$array, $key, $value, $nestedInclude, $extraIncludes]);
		}
		
		$array[$key] = $value;

	}

	/**
	 * Get resolved object form trunk if possible
	 * 
	 * @param  object $obj
	 * 
	 * @return object
	 */
	protected function resolve($obj)
	{
		if(Trunk::has($obj->id, $obj->type))
		{
			return $data = Trunk::get($obj->id, $obj->type);
		}

		return $obj;
	}

	/**
	 * Get resolved object from included
	 * 
	 * @param  object $obj
	 * 
	 * @return object
	 */
	public function getIncluded($obj)
	{
		$includes = $this->getIncludes(true);
		$objectKey = $this->objectKey($obj);
		
		if(array_key_exists($objectKey, $includes))
		{
			return $includes[$objectKey];
		}

		return $obj;
	}

	public function clearIncluded()
	{
		$this->included = [];
	}

	/**
	 * Make base64 key from object
	 * 
	 * @param  object $object
	 * 
	 * @return string
	 */
	protected function objectKey($object)
	{
		return base64_encode(json_encode(['id' => $object->id, 'type' => $object->type, 'relations' => $this->relations]));
	}
}
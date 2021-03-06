<?php
namespace nitm\search;

use yii\helpers\ArrayHelper;
use nitm\models\DB;

/**
 * IndexerElastisearch class
 * Can be extended later but by default
 * will include base methods for adding 
 * data to a search database and automatic
 * indexing of that information
 * 
 * There will also be the ability to
 * add search information
 * There will be the ability to update keywords
 * and such
*/
	
class IndexerElasticsearch extends BaseElasticSearch
{
	use traits\BaseIndexerTrait;
	
	public $deleteIndexes;
	
	/**
	 * Schedule for the river 
	 * Example:  = "05 00 * * * ?" runs every 5 minutes
	 */
	public $schedule;
	/**
	 * By default use node 0
	 */
	public $node = 0;
	/**
	 * The URL for the elasticsearch server
	 */
	public $url;
	/**
	 * The JDBC infomration if necessary. The following format is supported:
	 * [
	 *		'url' => URL of the database server including port (jdbc:mysql:localhost:3306)
	 * 		'username' => Username,
	 *		'password' => Password,
	 * 		'options' => [] Options for the PUT request
	 * ]
	 */
	public $jdbc;
	
	public $nestedMapping = [];
	
	protected $columns = [];
	
	const MODE_FEEDER = 'feeder';
	const MODE_RIVER = 'river';
	
	public function init()
	{
		parent::init();
		$this->setIndex(!isset($this->_database) ? static::getDbModel()->getDbName() : $this->_database);
		$this->url = !isset($this->url) ? \Yii::$app->elasticsearch->nodes[$this->node] : $this->url;
		$this->initEvents();
	}
	
	public function behaviors()
	{
		$behaviors = [
			'BaseIndexer' => [
				'class' => BaseIndexer::className()
			]
		];
		return array_merge(parent::behaviors(), $behaviors);
	}
	
	protected function initEvents()
	{
		/**
		 * Handle certain before index functions
		 */
		$this->on(BaseIndexer::BEFORE_SEARCH_INDEX, function ($event) {
			$getter = 'get'.$event->sender->getSource();
			if($event->sender->hasMethod($getter))
			{
				$allInfo = $event->sender->$getter();
				switch($event->sender->getSource())
				{
					case 'classes':
					$options = $allInfo[$this->namespace][$this->properClassName($event->sender->type())];
					break;
					
					case 'tables':
					break;
					
					default:
					return;
					break;
				}
				$this->columns = $event->sender->attributes();
				$attributes = array_keys($this->columns);
				if(is_array($options) && isset($options['withThese']))
					$attributes = array_merge($attributes, $options['withThese']);
					
				/*
				 * Update the mapping
				 */
				$this->updateMapping($event->sender->getMapping(), $attributes, $options);
			}
			$event->handled = true;
		});
		/**
		 * Delete the ammping after deleting an index
		 */
		$this->on(BaseIndexer::AFTER_SEARCH_DELETE, function ($event) {
			$this->apiInternal('delete', ['url' => '_mapping']);
			$event->handled = true;
		});
	}
	
	/**
	 * Update the mapping for an elsasticsearch index
	 */
	public function updateMapping($mapping, $attributes, $options=[])
	{
		$mapping = count($mapping) == 0 ? [] : $mapping;
		$mapping[$this->index()]['mappings'][$this->type()]['_all'] = ['enabled' => true];
		foreach($attributes as $attribute)
		{
			switch(isset($options['withThese']) && in_array($attribute, (array)$options['withThese']))
			{
				case true:
				$class = $this->namespace.$this->properClassName($this->type());
				$primaryModel = new $class;
				$relationGetter = 'get'.$attribute;
				$relatedQuery = $primaryModel->$relationGetter();
				$linkModelClass = $relatedQuery->modelClass;
				$relatedColumns = $linkModelClass::getTableSchema()->columns;
				$relatedAttributes = is_array($relatedQuery->select) ? $relatedQuery->select : array_keys($relatedColumns);
				@$mapping[$this->index()]['mappings'][$this->type()]['properties'][$attribute] = [
					'type' => 'nested',
					'include_in_all' => true,
					'include_in_parent' => true
				];
				foreach($relatedAttributes as $relatedProperty)
				{
					@$mapping[$this->index()]['mappings'][$this->type()]['properties'][$attribute]['properties'][$relatedProperty] = $this->getFieldAttributes($attribute, $relatedColumns[$relatedProperty], true);
				}
				break;
				
				default:
				@$mapping[$this->index()]['mappings'][$this->type()]['properties'][$attribute] = $this->getFieldAttributes($attribute, $this->columns[$attribute]);
				break;
			}
		}
		$put = [
			'url' => '_mapping?ignore_conflicts=true',
			json_encode($mapping[$this->index()]['mappings']), 
			true
		];
		if($this->reIndex)
			$this->apiInternal('delete', ['url' => '_mapping']);
		$this->apiInternal('put', $put);
	}
	
	protected function getFieldAttributes($field, $info, $all = false)
	{
		$info = \yii\helpers\ArrayHelper::toArray($info);
		$ret_val = [
			'include_in_all' => $all,
			'type' => $info['phpType'],
			'null_value' => 0
		];
		$baseType = array_shift(explode('(', $info['dbType']));
		switch($baseType)
		{
			case 'timestamp':
			$ret_val['null_value'] = '0000-00-00 00:00:00';
			$ret_val['type'] = 'date';
			$ret_val['format'] = "yyyy-MM-dd HH:mm:ss";
			$ret_val['boost'] = 2;
			$ret_val['store'] = true;
			$ret_val['ignore_malformed'] = true;
			$ret_val['include_in_all'] = true;
			break;
			
			case 'tinyint':
			switch($info['dbType'])
			{
				case 'tinyint(1)':
				$ret_val['type'] = 'boolean';
				$ret_val['null_value'] = false;
				break;
				
				default:
				$ret_val['type'] = 'integer';
				$ret_val['null_value'] = 0;
				break;
			}
			$ret_val['store'] = true;
			$ret_val['include_in_all'] = true;
			break;
			
			case 'bigint':
			case 'int':
			$ret_val['store'] = true;
			$ret_val['include_in_all'] = true;
			$ret_val['type'] = 'long';
			break;
			
			case 'smallint':
			$ret_val['type'] = 'integer';
			$ret_val['store'] = true;
			$ret_val['include_in_all'] = true;
			break;
			
			case 'resource':
			case 'binary':
			case 'blob':
			$ret_val['type'] = 'string';
			$ret_val['store'] = 'no';
			$ret_val['index'] = 'no';
			break;
			
			case 'text':
			case 'varchar':
			$ret_val['type'] = 'string';
			$ret_val['store'] = true;
			$ret_val['index'] = 'analyzed';
			$ret_val['boost'] = 2;
			$ret_val['include_in_all'] = true;
			break;
		}
		$userMapping = isset($this->module->settings['elasticsearch']['mapping'][$field]) ? $this->module->settings['elasticsearch']['mapping'][$field] : null;
		$ret_val = is_null($userMapping) ? $ret_val : array_merge($ret_val, $userMapping);
		return $ret_val;
	}
	
	/**
	 * Get the mapping from the ElasticSearch server
	 * @return array
	 */
	public function getMapping()
	{
		return static::api('get', ['url' => '_mapping', null, false]);
	}
	
	public function operation($operation, $options=[])
	{
		$operation = strtolower($operation);
		switch($operation)
		{
			case 'index':
			case 'delete':
			case 'update':
			switch($operation)
			{
				case 'update':
				$options = [
					'queryFilters' => [
						'indexby' => 'primaryKey'
					]
				];
				break;
				
				case 'delete':
				$options = [
					'queryFilters' => [
						'select' => 'primaryKey',
					]
				];
				break;
				
				default:
				$options = [];
				break;
			}
			$this->prepare($operation, $options);
			$this->run();
			$this->finish();
			break;
			
			case 'stats':
			$options['url'] = '_search';
			$oldJdbcOptions = $this->jdbc['options'];
			$this->jdbc['options'] = [
				'q' => '*',
				'search_type' => 'count'
			];
			$originalMock = $this->mock;
			$this->mock = false;
			$ret_val = $this->apiInternal('get', $options);
			$this->mock = $originalMock;
			$this->jdbc['options'] = $oldJdbcOptions;
			return $ret_val;
			break;
			
			default:
			echo "\n\tUnknown operation: $operation. Exiting...";
			break;
		}
	}
	
	/**
	 * Prepare data to be indexed/checked
	 * @param int $mode
	 * @param boolean $useClasses Use the namespaced calss to pull data?
	 * @return bool
	 */
	public function prepare($operation='index', $queryFilters=[])
	{
		$this->type = 'prepare';
		$this->_operation = 'operation'.ucfirst($operation);
		switch($this->mode)
		{
			case self::MODE_FEEDER:
			switch(is_array($this->_classes) && !empty($this->_classes))
			{
				case true:
				$prepare = 'FromClasses';
				$dataSource = '_classes';
				break;
				
				default:
				$prepare = 'FromTables';
				$dataSource = '_tables';
				break;
			}
			break;
			
			default:
			if(!isset($this->jdbc))
			{
				//By default use components value
				$this->jdbc = static::getJdbcOptions();
			}
			$prepare = 'FromSql';
			$dataSource = '_tables';
			break;
		}
		if(is_array($this->$dataSource) && empty($this->$dataSource))
			return false;
		$prepare = 'prepare'.$prepare;
		$this->$prepare($queryFilters);
		$this->type = $operation;
	}
	
	protected static function getJdbcOptions()
	{
		return [
			'options' => [],
			'url' => 'mysql:'.static::getDbModel()->host,
			'username' => static::getDbModel()->username,
			'password' => static::getDbModel()->getPassword()
			
		];
	}
	
	/**
	 * Use SQL to push using a PUT command
	 */
	public function prepareFromSql()
	{
		if(empty($this->_tables))
			return;
		$success = false;
		foreach($this->_tables as $table)
		{
			$this->log("\tDoing SQl River Push: ".static::index()."->$table Items: ".$this->tableInfo('Rows')."\n");
			$this->stack($table, [
				'worker' => [$this, 'parse'],
				'args' => [
					$model, 
					function ($query, $self) {
						$query->select()
							->limit($self->limit, $self->offset)
							->build();
						$sql = $query->getSql();
						$self->bulkSet($self->type, $sql);
						return $self->pushRiver($sql);
					}
				]
			]);
		}
		
		if($success == true)
		{
			$this->updateIndexed();
		}
	}
	
	/**
	 * Use SQL to push using a PUT command
	 * @param string $sql SQL
	 */
	public function pushRiver($sql)
	{
		$options = [
			'type' => 'jdbc',
			'jdbc' => [
				'url' => $this->jdbc['url'].'/'.static::index(),
				'user' => $this->jdbc['username'],
				'password' => $this->jdbc['password'],
				'sql' => $sql,
				'index' => static::index(),
				'type' => static::type(),
			]
		];
		if(isset($this->schedule))
			$options['jdbc']['schedule'] = $this->schedule;
		if(!$this->mock)
			static::getDb()->post(['_river', static::index(), static::type()], $this->jdbc['options'], json_encode($options), true);
		else
			$this->log(json_encode($options, JSON_PRETTY_PRINT));
	}
	
	/**
	 * Perform an operation
	 * @param string $operation
	 * @param array $data
	 */
	protected function apiInternal($operation, $options)
	{
		$options['mock'] = $this->mock;
		$options['index'] = isset($options['index']) ? $options['index'] : $this->index();
		$options['type'] = isset($options['type']) ? $options['type'] : $this->type();
		$this->log("\n\t\tUrl is ".strtoupper($operation)." ".implode('/', array_filter((array)$options['url'])), 3);
		$this->log("\n\t\t".var_export($options, true), 5);
		return static::api($operation, $options);
	}
	
	public static function api($operation, $options)
	{
		//print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3));
		//exit;
		switch(isset($options['mock']) && ($options['mock'] === true))
		{
			case true:
			return true;
			break;
			
			default:
			$url = isset($options['index']) ? [$options['index']] : [static::index()];
			$url[] = isset($options['type']) ? $options['type'] : static::type();
			if(isset($options['url']))
				array_push($url, $options['url']);
			else
				$options['url'] = [$url];
			unset($options['index'], $options['type'], $options['url']);
			$apiOptions = isset($options['apiOptions']) ? $options['apiOptions'] : [];
			array_unshift($options, implode('/', array_filter($url)), (array)$apiOptions);
			return call_user_func_array([static::getDb(), $operation], $options);
			break;
		}
	}
	
	protected function runOperation()
	{
		$result = call_user_func([$this, $this->_operation]);
		$resultArray = json_decode($result, true);
		$this->log("\n\t\t\t"."Result: Took \e[1m".$resultArray['took']."ms\e[0m Errors: ".($resultArray['errors'] ? "\e[31myes" : "\e[32mno")."\e[0m");
		$this->log("\n\t\t\t".($this->verbose >= 2 ? "Debug: ".var_export(@$result, true) : ''), 2);
	}
	
	public final function operationIndex()
	{
		$ret_val = [
			'success' => false,
		];
		$now = strtotime('now');
		$index_update = [];
		if(($this->mode != 'river') && (sizeof($this->bulkSize('index')) >= 1))
		{
			$create = [];
			$this->log("\n\t\tIndexing :");
			foreach($this->bulk('index') as $idx=>$item)
			{
				$this->normalize($item);
				$this->progress('index', null, null, null, true);
				$create[] = json_encode(['index' => ['_type' => static::type(), '_id' => $item['_id']]]);
				$item['_md5'] = $this->fingerprint($item);
				$create[] = json_encode($item);
				$this->totals['current']++;
			};
			$create[] = "{}";
			$options = [
				'url' => '_bulk', 
				implode("\n", $create), 
				true
			];
			if(sizeof($create) >= 1 && ($result = $this->apiInternal('post', $options)))
			{
				$this->bulkLog('index');
			}
			else
			{
				$this->log("\n\t\tNothing to Index\n");
			}
			$ret_val = $result;
		}
		if(isset($put) && $put == true)
		{
			$this->updateIndexed();
		}
		$this->bulk[$this->type] = [];
		return $ret_val;
	}
	
	public function operationUpdate()
	{
		if(($this->mode != 'river') && ($this->bulkSize('update') >= 1))
		{
			$update = [];
			$delete = [];
			/**
			 * First get all of the ids and fingerprints
			 */
			$this->jdbc['options']['ids'] = array_map(function ($value) {
				return $value['_id'];
			}, $this->bulk('update'));
			$existing = $this->apiInternal('get', [
				'url' => '_mget',
			]);
			$this->log("\n\t\tUpdating :");
			foreach((array)$existing as $item=>$idx) 
			{
				$this->normalize($item);
				$this->progress('update', null, null, null, true);
				if(array_key_exists($item['_id'], $this->bulk('update')))
				{
					if($self->bulk('update', $item['_id'])['_md5'] != $item['_md5'])
					{
						$update[] = ['update' => ['_id' => $item['_id']]];
						unset($item['_id']);
						$item['_md5'] = $this->fingerprint($this->bulk('update', $item['_id']));
						$update[] = $item;
						$sel->totals['current']++;
					}
				}
				else
				{
					$delete[] = $item['_id'];
				}
			};
			$url = [$this->index(), $this->type(), '_bulk'];
			if((sizeof($update) >= 1) && ($result = $this->apiInternal('post', [
				'url' => '_bulk',
				implode("\n", json_encode($udpate))
			])))
			{
				$this->log("\n\\t\tUpdated: ".$this->totals['current']." out of ".$this->tableInfo('Rows')." entries\n");
				$this->bulkLog('update');
			}
			else
			{
				$this->log("\n\t\tNothing to Update\n");
			}
			$this->log("\n\tDebug: ".var_export($result, true)."\n", 2);
			$this->log("\n");
			$ret_val = $result;
			if(sizeof($delete) >= 1)
			{
				$this->bulkSet('delete', $delete);
				$this->operationDelete();
			}
		}
	}
	
	public function operationDeleteIndex()
	{
		
	}
	
	public function operationDelete()
	{
		$ret_val = false;
		if($this->bulkSize('delete') >= 1)
		{
			if(!$this->mock)
			{
				$this->log("\n\t\tDeleting :");
				foreach($this->bulk('delete') as $idx=>$item)
				{
					$this->progress('delete', null, null, null, true);
					if(isset($item['_id']) && !is_null($item['_id']))
					{
						$this->bulkSet('delete', $idx, [
							'delete' => [
								'_id' => $item['_id'],
								'_type' => static::type(),
								'_index' => static::index()
							]
						]);
						$this->totals['current']++;
					}
				}
				$options = [
					'url' => '_bulk', 
					implode("\n", array_map('json_encode', $this->bulk('delete'))),
				];
				if($result = $this->apiInternal('post', $options))
				{
					$this->log("\n\t\tDeleted: ".$this->totals['current']." entries\n");
					$this->bulkLog('delete');
				}
				$this->log("\n\tDebug: ".var_export($result, true)."\n", 2);
				$this->totals['total'] -= $this->totals['current'];
				$ret_val = $result;
			}
			else
			{
				$ret_val = '{"took":"1ms","Errors":false}';
			}
		}
		
	}
}
?>
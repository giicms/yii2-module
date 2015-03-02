<?php
namespace nitm\traits;

use yii\helpers\ArrayHelper;
/**
 * Traits defined for expanding query scopes until yii2 resolves traits issue
 */
trait Data {
	
	public $queryFilters = [];
	public $withThese = [];
	
	protected $_count;
	protected $is;
	protected static $_is;
	protected static $tableName;
	protected static $_flags = [];
	
	/*
	 * What does this claim to be?
	 */
	public function isWhat()
	{
		$purify = function ($value) {
			$stack = explode('\\', $value);
			return strtolower(implode('-', preg_split('/(?=[A-Z])/', array_pop($stack), -1, PREG_SPLIT_NO_EMPTY)));
		};
		switch(debug_backtrace(false, 1)[0]['type'])
		{
			case '->':
			//If it's a model then get the instantiated $is value
			return isset($this->is) ? $this->is : $purify(static::className());
			break;
			
			default:
			//Otherwise get the instantiated class value
			$class = static::className();
			if(!isset($class::$_is)) {
				$class::$_is = $purify($class);
			}
			return $class::$_is;
			break;
		}
	}
	
	/**
	 * Get the unique ID of this object
	 * @return string|int
	 */
	public function getId()
	{
		$key = $this->primaryKey();
		return (int)(string)$this->$key[0];
	}
	
	/*
	 * Return a string imploded with ucfirst characters
	 * @param string $name
	 * @return string
	 */
	public static function properName($value=null)
	{
		$ret_val = is_null($value) ?  static::isWhat() : preg_replace('/[\-\_]/', " ", $value);
		return implode(' ', array_map('ucfirst', explode(' ', $ret_val)));
	}
	
	/*
	 * Return a string imploded with ucfirst characters
	 * @param string $name
	 * @return string
	 */
	public static function properClassName($value=null)
	{
		$ret_val = is_null($value) ?  static::className() : preg_replace('/[\-\_]/', " ", $value);
		return implode('', array_map('ucfirst', explode(' ', static::properName($ret_val))));
	}
	
	public static function getNamespace()
	{
		return (new \ReflectionClass(static::className()))->getNamespaceName();
	}
	
	public function flagId($flag)
	{
		if(isset($this) && $this instanceof \nitm\models\Data)
			return self::getNamespace().'\\'.$this->getId();
		else
			return self::getNamespace().'\\';
	}
	
	/**
	 * Some support for setting custom flags at runtime
	 */	
	public function setFlag($flag, $value)
	{
		self::$_flags[self::flagId($flag)] = $value;
	}
	
	public function getFlag($flag)
	{
		return ArrayHelper::getValue(self::$_flags, self::flagId($flag), null);
	}
	
	public static function unsetFlag($flag)
	{
		return ArrayHelper::remove(self::$_flags, self::flagId($flag), null);
	}
	
	public function addWith($with)
	{
		$with = is_array($with) ? $with : [$with];
		$this->withThese = array_merge($this->withThese, $with);
	}

    /**
	 * This is here to allow base classes to modify the query before finding the count
     * @return \yii\db\ActiveQuery
     */
    public function getCount($link)
    {
		$primaryKey = $this->primaryKey()[0];
		$link = is_array($link) ? $link : [$primaryKey => $primaryKey];
		$tableName = static::tableName();
		$tableNameAlias = $tableName.'_alias';
        return $this->hasOne(static::className(), $link)
			->select([
				'_count' => "COUNT(".$primaryKey.")",
			])
			->andWhere($this->queryFilters);
    }
	
	public function count()
	{
		return $this->hasProperty('count') && isset($this->count) ? $this->count->_count : 0;
	}

	/*
	 * Get the array of arrays
	 * @return mixed
	 */
	public function getArrays()
	{
		$query = $this->find($this);
		$ret_val = $query->asArray()->all();
		$this->success = (sizeof($ret_val) >= 1) ? true : false;
		return $ret_val;
	}
	
	/**
	 * Get a one dimensional associative array
	 * @param mixed $label
	 * @param mixed $separator
	 * @return array
	 */
	public function getList($label='name', $separator=' ')
	{
		$ret_val = [];
		$label = empty($label) ? 'name' : $label;
		$items = $this->getModels();
		switch(empty($items))
		{
			case false:
			foreach($items as $item)
			{
				$ret_val[$item->getId()] = static::getLabel($item, $label, $separator);
			}
			break;
			
			default:
			$ret_val[] = ["No ".static::isWhat()." found"];
			break;
		}
		return $ret_val;
	}
	
	/**
	 * Get a multi dimensional associative array suitable for Json return values
	 * @param mixed $label
	 * @param mixed $separator
	 * @return array
	 */
	public function getJsonList($labelField='name', $separator=' ', $options=[])
	{
		$ret_val = [];
		foreach($this->getModels() as $item)
		{
			$_ = [
				"id" => $item->getId(),
				"value" => $item->getId(), 
				"text" =>  $item->$labelField, 
				"label" => static::getLabel($item, $label, $separator)
			];
			if(isset($options['with']))
			{
				foreach($options['with'] as $attribute)
				{
					switch($attribute)
					{
						case 'htmlView':
						$view = isset($options['view']['file']) ? $options['view']['file'] : "/".$item->isWhat()."/view";
						$viewOptions = isset($options['view']['options']) ? $options['view']['options'] : ["model" => $item];
						$_['html'] = \Yii::$app->getView()->renderAjax($view, $viewOptions);
						break;
						
						case 'icon':
						/*$_['label'] = \lab1\widgets\Thumbnail::widget([
							"model" => $item->getIcon()->one(), 
							"htmlIcon" => $item->html_icon,
							"size" => "tiny",
							"options" => [
								"class" => "thumbnail text-center",
							]
						]).$_['label'];*/
						break;
					}
				}
			}
			$ret_val[] = $_;
		}
		return (sizeof(array_filter($ret_val)) >= 1) ? $ret_val : [['id' => 0, 'text' => "No ".$this->properName($this->isWhat())." Found"]];
	}

	/*
	 * Get array of objects
	 * @return mixed
	 */
	public function getModels()
	{
		$query = $this->find($this);
		$ret_val = $query->all();
		$this->success = (sizeof($ret_val) >= 1) ? true : false;
		return $ret_val;
	}

	/*
	 * Get a single record
	 */
	public function getOne()
	{
		$query = $this->find($this);
		$ret_val = $query->one();
		$this->success = (!is_null($ret_val)) ? true : false;
		return $ret_val;
	}
	
	/**
	 * Get the label for use based on
	 * @param $model The model being resolved
	 * @param mixed $label Either a string or array indicating where the label lies
	 * @param mixed $separator the separator being used to clue everything together
	 * @return string
	 */
	protected static function getLabel($model, $label, $separator)
	{
		switch(is_array($label))
		{
			case true:
			$resolvedLabel = '';
			/**
			 * We're supporting multi propertiy/relation properties for labels
			 */
			foreach($label as $idx=>$l)
			{
				$workingItem = $model;
				$properties = explode('.', $l);
				foreach($properties as $prop)
				{
					if(is_object($workingItem) && ($workingItem->hasAttribute($prop) || $workingItem->hasProperty($prop)))
					{
						$workingItem = $workingItem->$prop;
					}
				}
				/**
				 * Support enacpsulating sub values when $separator is sent as a length==2 array
				 */
				switch(is_array($separator) && ($idx == sizeof($label)-1))
				{
					case true:
					$resolvedLabel .= $separator[0].$workingItem.$separator[1];
					break;
					
					default:
					$resolvedLabel .= $workingItem.(is_array($separator) ? $separator[2] : $separator);
					break;
				}
			}
			$ret_val= $resolvedLabel;
			break;
			
			default:
			$ret_val = $model->hasAttribute($label) || $model->hasProperty($label) ? $model->$label : $label;
			break;
		}
		return $ret_val;
	}
}
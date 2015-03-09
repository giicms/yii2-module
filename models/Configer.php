<?php

namespace nitm\models;

use Yii;
use yii\web\Application;
use yii\base\Event;
use yii\base\Model;
use nitm\helpers\Session;
use nitm\models\configer\Container;
use nitm\models\configer\Section;
use nitm\models\configer\Value;
use nitm\models\configer\File;

/**
 * Class Configer
 * @package nitm\models
 *
 * @property integer $id
 * @property string $name
 * @property string $value
 * @property string $section
 * @property string $container
 * @property string $what
 * @property string $engine
 * @property string $comment
 * @property string $getValues
 */

class Configer extends Model
{
	//public data
	public $backups = true;
	public $backupExtention = '.cfg.bak';
	public $dir = ["default" => 'config/ini/', "config" => null];
	public static $config = [];
	
	public $container = 'globals';
	
	//Form variables
	public $id;				//The id of the value
	public $name;			//The name of a key/value pair
	public $value;			//The value
	public $section;		//Current value section
	public $what;			//What is being done
	public $engine;			//Current engine
	public $comment;		//The comment
	public $convert;		//Convert
	public $convertTo;		//Convert to what engine?
	public $getValues;		//Should we try to get values as well?
	
	//protected data
	protected $containerModel;
	protected $sectionModel;
	protected $classes = [
		"success" => "success",
		"failure" => "warning",
		"info" => "info"
	];
	
	//constant data
	const dm = 'configer';
	const NO_DEC = 'nodec:';
	
	//private data
	private static $_containers;
	private static $_cache = [];
	private $_objects = [];
	private $_types = ['ini' => 'cfg', 'xml' => 'xml', 'file' => 'cfg'];
	private $_location = "file";
	private $_supported = ["file" => "File", "db" => "Database"];
	private $_event;
	private static $hasNew;
	
	public function __construct()
	{
		$this->init();
	}
	
	public function init($enable_backups=true, $backupExtention='.cfg.bak')
	{
		$this->backups = $enable_backups;
		$this->backupExtention = $backupExtention;
		$this->dir['config'] = $this->dir['default'];
		$this->config('supported', $this->_supported);
		$this->initEvents();
	}
	
	public function behaviors()
	{ 
		$behaviors = [
		];
		return array_merge(parent::behaviors(), $behaviors);
	}
	
	public function rules()
	{
		return [
			[['what', 'value', 'section', 'name', 'container'], 'required', 'on' => ['createValue']],
			[['what', 'value', 'container'], 'required', 'on' => ['createSection']],
			[['what', 'value'], 'required', 'on' => ['createContainer']],
			[['what', 'value', 'name', 'container', 'id'], 'required', 'on' => ['updateValue']],
			[['what', 'value', 'section', 'container'], 'required', 'on' => ['updateSection']],
			[['what', 'value', 'container'], 'required', 'on' => ['updateContainer']],
			[['what', 'name', 'container', 'id'], 'required', 'on' => ['deleteValue']],
			[['what', 'value', 'container'], 'required', 'on' => ['deleteSection']],
			[['what', 'value'], 'required', 'on' => ['deleteContainer']],
			[['what', 'container', 'section'], 'required', 'on' => ['getSection']],
			[['convert'], 'required', 'on' => ['convert']],
			[['engine'], 'safe'],
		];
	}
	
	public function scenarios()
	{
		return [
			'default' => ['value', 'container', 'section', 'what', 'engine', 'getValues',],
			'createValue' => ['value', 'name', 'container', 'section', 'what'],
			'createSection' => ['value', 'container', 'what'],
			'createContainer' => ['value', 'what'],
			'updateValue' => ['value', 'name', 'section', 'container', 'what', 'id'],
			'updateSection' => ['value', 'container', 'what', 'id'],
			'deleteValue' => ['section', 'name', 'what', 'id'],
			'deleteSection' => ['name', 'container', 'what', 'id'],
			'deleteContainer' => ['value', 'what', 'id'],
			'getSection' => ['what', 'container', 'section', 'getValues'],
			'convert' => ['convert', 'engine']
		 ];
	}
	
	public function attributeLabels()
	{
		return [
		    'value' => 'Value',
		    'name' => 'Name',
		    'container' => 'Container',
		    'engine' => 'Engine',
		    'section' => 'Section',
		    'what' => 'Action',
		];
	}
	
	/**
	 * Set or get a current setting
	 * @param string|array $name the name of the setting to get
	 * @param mixed $value the value to set
	 * @param boolean $append
	 */
	public static function config($name=null, $value=null, $append=false)
	{
		//echo "Setting $name and appending?: $append to value ".jsON_encode($value)."<br>\n";
		$name = is_array($name) ? implode('.', $name) : $name;
		return \nitm\helpers\ArrayHelper::getOrSetValue(static::$config, $name, $value, $append);
	}
	
	/*
	 * Initiate the event handlers for this class
	 */
	public function initEvents()
	{
		$this->on("afterCreate", function($e) {
			$this->config('current.section', $this->_event['data']['section']);
			if($this->container == \Yii::$app->getModule('nitm')->config->container) {
				$value = (is_null($decoded = json_decode(trim($this->_event['data']['value']), true)) ? $this->_event['data']['value'] : $decoded);
				Session::set($this->_event['data']['key'], $value);
			}
			Session::set($this->uriOf($this->_event['data']['key'], true), $this->_event['data']['value']);
			\Yii::$app->getModule('nitm')->logger->log($this->getEventData());
		});
		
		$this->on("afterUpdate", function($e) {
			if($this->container == \Yii::$app->getModule('nitm')->config->container) {
				$value = (is_null($decoded = json_decode(trim($this->_event['data']['value']), true)) ? $this->_event['data']['value'] : $decoded);
				Session::set($this->_event['data']['key'], $value);
			}
			Session::set($this->uriOf($this->_event['data']['key'], true).'.value', $this->_event['data']['value']);
			\Yii::$app->getModule('nitm')->logger->log($this->getEventData());
		});
		
		$this->on("afterDelete", function($e) {
			switch($this->_event['data']['action'])
			{
				case 'delete':
				$value = $section;
				break;
			}
			$this->config('current.section', @$this->_event['data']['section']);
			Session::del($this->uriOf($this->_event['data']['key'], true), true);
			switch($this->container == \Yii::$app->getModule('nitm')->config->container)
			{
				case true:
				Session::del($this->_event['data']['key']);
				break;
			}
			\Yii::$app->getModule('nitm')->logger->log($this->getEventData());
		});
	}
	
	protected function getEventData()
	{
		return [
			'db_name' => $this->_event['data']['db'],
			'table_name' => $this->_event['data']['table'],
			'action' => $this->_event['data']['action'],
			'message' => $this->_event['data']['message']
		];
	}
	
	/*
     * Prepare the config info for updating
	 * @param string $engine
	 * @param string $container
	 * @param boolean $getValues
     * @return mixed $config
     */
	public function prepareConfig($engine='file', $container='config', $getValues=false)
	{
		$engine = empty($engine) ? (empty($this->engine) ? 'file' : $this->engine) : $engine;
		$container = empty($container) ? 'global' : array_pop(explode('.', $container));
		switch($engine)
		{
			case 'alt':
			switch($container)
			{
				case 'pma':
				$template = Session::getVal("settings.templates.iframe");
				$this->render($template, ['src' => '/phpmyadmin/main.php']);
				return;
				break;
			}
			break;
			
			default:
			$this->setEngine($engine);
			$this->setType($engine, $container);
			//if the selected config is not loaded then load it
			if((Session::getVal(self::dm.'.current.config') != $this->_location.'.'.$container) || (Session::getVal(self::dm.'.current.engine') != $this->_location)) {
				$this->config('current.config', $this->getConfig($engine, $container, $getValues, true));
				Session::set(self::dm.'.'.$this->_location.'.config', $this->config('current.config'));
				$this->config('current.sections', array_merge(["" => "Select section..."], $this->getSections()));
			}
			//otherwise just get the current loaded config
			else {
				$this->config('current.config', Session::getVal(self::dm.'.'.$this->_location.'.config'));
				$this->config('current.sections', array_merge(["" => "Select section..."], $this->getSections()));
			}
			switch($getValues)
			{
				case false:
				$this->config('current.config', null);
				break;
			}
			$this->config('load.current', (bool)count($this->config('current.config'))>=1);
			$this->config('load.sections', (bool)count($this->config('current.sections'))>=1);
			switch($this->container == \Yii::$app->getModule('nitm')->config->container)
			{
				case false:
				Session::set(Session::settings.'.'.$this->_event['data']['key'], $this->_event['data']['value']);
				break;
			}
			Session::set(self::dm.'current.config', $this->_location.'.'.$container);
			break;
		}
	}
	
	/*
     * Set the configuration type
	 * @param string $engine
	 * @param string $container
	 * @param string $from
     * @return mixed $this->config
     */	
	public function setType($engine, $container=null, $from='default')
	{
		$this->config('surround', []);
		$this->config('current.type', $engine);
		$this->config('current.type_text', 'a section');
		$this->config('current.container', $container);
		$this->config('current.sections', null);
		$this->config('current.selected_text', "selected='selected'");
		$this->config('load.types', !is_array($this->_supported) ? false : true);
		$this->getContainers($container);
		switch(isset($this->config('from')[$from]))
		{
			case true:
			switch(1)
			{
				case in_array('xml', $this->config('from')[$from]['types']) !== false:
				//$fb::$compatible = ['text' => '.xml');
				//$freswitch_base = '/usr/local/freswitch/conf/';
				$this->config('current.container', $engine);
				$this->config('current.from.'.$engine.'.selected',  "selected='selected'");
				$this->config('current.path', $this->config('from')[$from]['dir']);
				$this->config('current.type', 'xml');
				$this->config('current.surround', ['open' => "<code>", "close" => "</code>"]);
				$this->config('current.type_text', 'an xml file');
				break;
			
				default:
				switch(in_array($container, $this->config('containers')))
				{
					case true:
					$this->config('current.container', $container);
					$this->config('current.path', "@$container");
					break;
				
					default:
					$this->config('current.container', "globals");
					$this->config('current.path', '@globals');
					break;
				}
				break;
			}
			$this->container = $this->config('current.container');
			break;
		}
	}
	
	/*
		Set the storage engine
		@param string $loc Either file or database
	*/
	public function setEngine($loc)
	{
		switch($this->isSupported($loc))
		{
			case true:
			switch($loc)
			{
				case 'db':
				$this->_location = 'db';
				break;
				
				case 'file':
				$this->_objects['file'] = new File();
				$this->_location = 'file';
				break;
			}
			break;
		}
		switch(!empty($this->_location))
		{
			case true:
			//clear any other unused engine data
			foreach(array_diff_key($this->_supported, [$this->_location => ucfirst($this->_location)]) as $clear=>$key)
			{
				Session::del(self::dm.'.'.$clear);
			}
			Session::set(self::dm.'.current.engine', $this->_location);
			break;
		}
	}
	
	public function initLogging($log_db=null, $log_table=null)
	{
		if(class_exists("Logger") && !($this->l instanceof Logger))
		{
			$this->l = new Logger(null, null, null, Logger::LT_DB, $log_db, $log_table);
		}
	}
	
	public function setBase($container)
	{
		switch(empty($container))
		{
			case false:
			switch($this->_location)
			{
				case 'file':
				$container = explode('.', $container);
				$container = array_shift($container);
				$container = empty($container) ? $this->container : $container;
				$this->container = ($container[0] == '@') ? substr($container, 1, strlen($container)) : $container;
				break;
			}
			break;
		}
	}
	
	public function uriOf($key, $internal=false)
	{
		$key = explode('.', $key);		
		switch($key[0])
		{
			case self::dm:
			array_shift($key);
			switch($key[0] == $this->container)
			{
				case false;
				array_unshift($key, self::dm, $this->_location, 'config');
				break;
			}
			break;
			
			default:
			if($internal === true)
				if($key[0] == $this->container)
					array_unshift($key, self::dm, $this->_location, 'config');
				else
					array_unshift($key, self::dm, $this->_location, 'config', $this->container);
			else
				if($this->container == Yii::$app->getModule('nitm')->config->container)
					array_unshift($key, Session::settings);
			break;
		}
		return implode('.', $key);
	}
	
	/*
	 * Set the directory for the configuration. Backups will also be stroed here
	 * @param string $dir
	 */
	
	public function setDir($dir=null)
	{
		$this->dir['config'] = (is_dir($dir)) ? $dir : $this->dir['default'];
	}
	
	public function getDm()
	{
		return self::dm;
	}
	
	/*
	 * Write/save the configuration
	 * @param string $container
	 * @param mixed $data
	 * @param string $engine
	 * @return boolean success flag
	 */
	public function writeTo($container, $data, $engine='db')
	{
		$sections = '';
		$content = '';
		$ret_val = false;
		$this->setEngine($engine);
		switch($this->_location)
		{
			case 'xml':	
			break;
			
			case 'file':
			$write = $this->_objects['file']->prepare($data);
			break;
				
			case 'db':
			//write info to db
			$write = true;
			break;
		}
		switch($write)
		{
			case true:
			switch($this->_location)
			{
				case 'db':
				$this->container($container);
				switch(!$this->container($container))
				{
					case true:
					$this->createContainer($container, null, $this->_location);
					$container = $this->container()->name;
					break;
				}
				$message = "";
				$result = 'failure';
				$action = ["success" => "Create Config", "failure" => "Create Config Fail"];
				//write the sections
				foreach($data as $name=>$values)
				{
					$model = new Section(['scenario' => 'create']);
					$section->name = $name;
					$model->containerid = $this->container()->id;
					switch($model->validate())
					{
						case true:
						$model->save();
						$this->insert(array_keys($section), array_values($section));
						$sections[$name] = ['model' => $model, 'values' => $values];
						break;
					}
				}
				//write the values
				foreach($sections as $name=>$values)
				{
					foreach($values['values'] as $k=>$v)
					{
						$model = new Value(['scenario' => 'create']);
						$model->load($v);
						$model->containerid = $this->container()->id;
						$model->sectionid = $values['model']->id;
						switch($model->validate())
						{
							case true:
							$model->save();
							break;
						}
					}
				}
				break;
				
				case 'file':
				$container = ($container[0] == '@') ? $this->dir['default'].substr($container, 1, strlen($container)) : $container;
				$this->_objects['file']->write($container, $this->backups);
				/*
					After a change has ben made and commited remove the current values in index $this->container
					to save memory/space and to not allow any new changes to be made
				*/
				$this->setBase($container);
				switch(Session::getVal(self::dm.'.'.$this->_location.'.'.$this->container) != $data)
				{
					case true:
					Session::set(self::dm.'.'.$this->_location.'.'.$this->container);
					break;
				}
				break;
			}
			break;
		}
		return $ret_val;
	}
	
	/*
     * Get the configuration information depending on container and location and store it in $this->config
	 * @param string $engine
	 * @param string $container
	 * @param boolean $getValues
     * @return mixed $config
     */
	public function getConfig($engine=null, $container=null, $getValues=false, $updating=false)
	{
		$this->container = !empty($container) ? $container : $this->container;
		$engine = !empty($engine) ? $engine : $this->_location;
		$ret_val = null;
		switch($engine)
		{
			case 'xml':
			$xml_files = $this->_objects['directory']->getFilesMatching($this->config('current.path').$this->method['in'], false, ['.xml'], true, false, null, $this->config('current.path'));
			foreach($xml_files[$this->method['in']] as $container)
			{
				$ret_val = [$container => '"'.file_get_contents($this->config('path').$this->method['in'].DIRECTORY_SEPARATOR.$container).'"'];
			}
			break;
			
			case 'file':
			$ret_val = $this->readFrom($this->loadFrom($this->config('current.path'), false, true), 
				null, 'json', $updating, $engine);
			break;
			
			
			case 'db':
			$ret_val = $this->readFrom($this->loadFrom($this->config('current.container'), false, true), 
				null, 'json', $updating, $engine);
			break;
		}
		return $ret_val;
	}	
	
	/*
	 * Convert configuration betwen formats
	 * @param string $container
	 * @param string $from
	 * @param string $to
	 */
	public function convert($container, $from, $to)
	{
		$ret_val = [
			"success" => false, 
			"message" => "Unable to convert $container from $from to $to"
		];
		switch($this->isSupported($from) && $this->isSupported($to))
		{
			case true:
			$old_engine = $this->_location;
			$this->setEngine($from);
			$config = $this->getConfig($from, $container, true, true);
			$this->setEngine($to);
			$this->writeTo($container, $config, $to);
			$ret_val['message'] = "Converted $container from $from to $to";
			$ret_val['success'] = true;
			$ret_val['action'] = 'convert';
			$this->config('current.action', $ret_val);
			$this->setEngine($old_engine);
			break;
		}
	} 
	
	/*
	 * Load the configuration
	 * @param string $container
	 * @param boolean $from_sess From the session?
	 * @param boolean $force Force a load?
	 * @return mixed configuration
	 */
	public function loadFrom($container=null, $from_sess=false, $force=false, $engine='db')
	{
		$ret_val = null;
		switch($from_sess === true)
		{
			case true:
			$ret_val = &$_SESSION[$this->sess_name.$_SERVER['SERVER_NAME']][self::dm];
			break;
			
			default:
			switch($this->_location)
			{
				case 'xml':
				break;
				
				case 'file':
				$this->setBase($container);
				$container = $this->resolveDir($this->config('current.path'));
				$container = $container.'.'.$this->_types[$this->_location];
				$ret_val = $this->_objects['file']->load($container, $force);
				break;
				
				case 'db':
				/*
				 * We ned to use activity states here to determine when to load form the database
				 */
				if($force || self::hasNew()) {
					if($this->section)
						$ret_val = \yii\helpers\ArrayHelper::getValue($this->section($this->section), 'values');
					else
						$ret_val = \yii\helpers\ArrayHelper::getValue($this->container($container), 'values');
				}
				break;
			}
			break;
		}
		return $ret_val;
	}
	
	/*
	 * Read the configuration from a database or file
	 * @param mixed $contents
	 * @param string $commentchar
	 * @param string $decode
	 * @param boolean $updating
	 * @param string $engine
	 * @return mixed $ret_val
	 */
	public function readFrom($contents=null, $commentchar=';', $decode='json', $updating=false, $engine='db') 
	{
		$ret_val = [];
		$decode = is_array($decode) ? $decode : [$decode];
		switch($this->_location)
		{
			case 'db':
			//convert the raw config to the proper hierarchy;
			switch(!is_array($contents))
			{
				case true:
				$contents = !$this->container($contents) ? [] : $this->container($contents)->getValues()->all();
				break;
			}
			switch(is_array($contents))
			{
				case true:
				foreach($contents as $idx=>$data) 
				{
					$section = $data->section_name;
					$val_key = $data->name;
					switch(isset($ret_val[$section]))
					{
						case false:
						$ret_val[$section] = [];
						break;
					}
					//set the value
					$ret_val[$section][$val_key] = $data;
				}
				break;
				
				default:
				$ret_val= [];
				break;
			}
			break;
			
			case 'xml':
			break;
			
			case 'file':
			$ret_val = $this->_objects['file']->read($contents);
			break;
		}
		switch($this->_location)
		{
			case 'xml':
			break;
			
			case 'db':
			case 'file':
			switch(is_array($ret_val) && is_array($decode))
			{
				case true:
				foreach($decode as $dec)
				{
					switch($dec)
					{
						case 'json':
						array_walk_recursive($ret_val, function (&$v) use ($updating) {
							switch(1)
							{
								case is_array($v->value):
								continue;
								break;
								
								case substr($v->value, 0, strlen(self::NO_DEC)) == self::NO_DEC:
								$v->value = substr($v->value, strlen(self::NO_DEC), strlen($v->value));
								break;
								
								case((@$v->value[0] == "{") && ($v->value[strlen($v->value)-1] == "}")) && ($updating === false):
								$v->value = ((!is_null($data = json_decode(trim($v->value), true))) ? $data : $v->value);
								break;
							}
							switch($updating)
							{
								case false:
								$v = $v->value;
								break;
								
								default:
								$model = $v;
								$v = array_merge($model->getAttributes(), array_intersect_key(get_object_vars($model), array_flip([
										'section_name',
										'container_name',
										'unique_id',
										'unique_name'
									])
								));
								break;
							}
						});
						break;
						
						case 'csv':
						array_walk_recursive($ret_val, function (&$v) {
							switch((@$v->value[0] == "{") && ($v->value[strlen($v->value)-1] == "}") && ($updating === false))
							{
								case true:
								$v->value = explode(',', $v->value);
								break;
							}
						});
						break;
					}
				}
				break;
			}
			break;
		}
		return $ret_val;
	}
	
	/*
	 * Create a value to the configuration
	 * @param string $key
	 * @param string $container
	 * @param string sess_member
	 * @param string $engine
	 * @return mixed created value and success flag
	 */
	public function create($key, $value, $container, $sess_member=null, $engine='db')
	{
		$ret_val = [
			"success" => false, 
			"message" => "Couldn't perform the create: [$key], [$value], [$container]", 
			"action" => 'update', 
			"class" => $this->classes["failure"]
		];
		$this->setEngine($engine);
		$this->setBase($container);
		$hierarchy = explode('.', $this->uriOf($key));
		switch($this->_location)
		{
			case 'db':
			$ret_val = array_merge($ret_val, $this->_create($container, $key, $value));
			$ret_val['data'] = [$key, $value];
			$ret_val['class'] = !$ret_val['success'] ? $this->classes['failure'] : $this->classes['success'];
			$e = new Event;
			$this->_event['data'] = array_merge($ret_val, [
				'table' => 'config',
				'db' => DB::getDbName(),
				'action' => 'Create Config',
				'message' => "On ".date("F j, Y @ g:i a")." user ".Session::getVal('securer.username') ." created new key->value ($key -> ".var_export($value, true).") to config ".$container
			]);
			$this->trigger('afterCreate');
			break;
			
			case 'xml':
			break;
			
			case 'file':
			$container = $this->resolveDir($this->config('current.path'));
			Session::setCsdm(self::dm.'.'.$this->_location);
			switch(sizeof($hierarchy))
			{
				///we might be creating a container
				case 1:
				switch(empty($contaienr))
				{
					case false:
					$this->createContainer($container);
					break;
				}
				break;
			
				default:
				switch(1)
				{
					case !$container:
					$ret_val['debug'] = "Sorry I cannot create a value to a container that doesn't exist\nPlease try again again by passing the correct parameters to me.\ncreate($key, ".var_export($value).", ".basename($container).", $sess_member) (0);";
					break;
				
					case !$key:
					$ret_val['debug'] = "Sorry I cannot create an empty key\nPlease try again again by passing the correct parameters to me.\ncreate($key, ".var_export($value, true)."), ".basename($container).", $sess_member) (1);";
					break;
					
					default:
					$ret_val = array_merge($ret_val, $this->_create($container, $key, $value));
					$ret_val['class'] = $this->classes['failure'];
					$sess_member = (empty($sess_member)) ? Session::settings : $sess_member.'.'.$this->container;
					switch($ret_val['success'])
					{
						case true:
						$ret_val['class'] = $this->classes['success'];
						$e = new Event;
						$this->_event['data'] = array_merge($ret_val, [
							'table' => 'NULL',
							'db' => 'NULL',
							'action' => 'Create Config',
							'message' => "On ".date("F j, Y @ g:i a")." user ".Session::getVal('securer.username') ." created new key->value ($key -> ".var_export($value, true).") to config file ".basename($container)
						]);
						$this->trigger('afterCreate');
						break;
					}
					break;
				}
				break;
			}
			break;
		}
		$ret_val['action'] = 'create';
		$this->config('current.action', $ret_val);
		Session::set(Configer::dm.'.action', $ret_val);
	}
	
	/*
	 * Update a value in the configuration
	 * @param string $key
	 * @param string $container
	 * @param string sess_member
	 * @param string $engine
	 * @return mixed updated value and success flag
	 */
	public function update($key, $value, $container, $sess_member=null, $engine='db')
	{
		$key = is_array($key) ? implode('.', $key) : $key;
		$value = is_array($value) ? json_encode($value) : $value;
		if(is_array($container))
		{
			debug_print_backtrace();
			exit;
		}
		$container = is_array($container) ? implode('.', $container) : $container;
		$ret_val = [
			"success" => false, 
			"message" => "Couldn't perform the update: $key, $value, $container", 
			"action" => 'update', 
			"class" => $this->classes["failure"]
		];
		$this->setEngine($engine);
		$this->setBase($container);
		$key = stripslashes ($key);
		$value = stripslashes(rawurldecode($value));
		switch($this->_location)
		{
			case 'db':
			$ret_val = array_merge($ret_val, $this->_update($container, $key, $value));
			switch($ret_val['success'])
			{
				case true:
				$ret_val['class'] = $this->classes['success'];
				$this->_event['data'] = [
					'table' => 'config',
					'db' => DB::getDbName(),
					'key' => $key,
					'value' => $value,
					'action' => "Update Config",
					'message' => "On ".date("F j, Y @ g:i a")." user ".Session::getVal('securer.username')." updated value ($key from '".var_export($ret_val['old_value'], true)."' to '".var_export($value, true)."') in container ".basename($container)
				];
				$this->trigger('afterUpdate');
				break;
			}
			break;
			
			case 'file':
			$container = $this->resolveDir($this->config('current.path'));
			switch(1)
			{
				case !$container:
				$ret_val['class'] = $this->classes['failure'];
				$ret_val['debug'] ="Sorry I cannot update a value in a file that doesn't exist\nPlease try again again by passing the correct parameters to me.\nupdate($key, ".var_export($value).", ".basename($container).", $sess_member) (0);";
				break;
				
				case !$key:
				$ret_val['debug'] ="Sorry I cannot update an empty key\nPlease try again again by passing the correct parameters to me.\nupdate($key, ".var_export($value, true).", ".basename($container).", $sess_member) (1);";
				break;
				
				default:
				//use sed for updating
				$ret_val = array_merge($ret_val, $this->_update($container, $key, $value));
				$sess_member = (empty($sess_member)) ? Session::settings : $sess_member.'.'.$this->container;
				switch($ret_val['success'])
				{
					case true:
					$ret_val['class'] = $this->classes['success'];
					$this->_event['data'] = [
						'table' => 'null',
						'db' => DB::getDbName(),
						'key' => $key,
						'value' => $value,
						'action' => "Update Config File",
						'message' => "On ".date("F j, Y @ g:i a")." user ".Session::getVal('securer.username')." updated value ($key from '".var_export($ret_val['old_value'], true)."' to '".var_export($value, true)."') in config file ".basename($container)
					];
					$this->trigger('afterUpdate');
					break;
				}
				break;
			}
		}
		$ret_val['action'] = 'update';
		$ret_val['value'] = rawurlencode($value);
		$this->config('current.action', $ret_val);
		Session::set(Configer::dm.'.action', $ret_val);
	}
	
	/*
	 * Delete a value in the configuration
	 * @param string $key
	 * @param string $container
	 * @param string sess_member
	 * @param string $engine
	 * @return mixed deleted value and success flag
	 */
	public function delete($key, $container, $sess_member=null, $engine='db')
	{
		$ret_val = [
			"success" => false, 
			"message" => "", 
			"action" => 'delete', 
			"class" => $this->classes["failure"]
		];
		$this->setEngine($engine);
		$this->setBase($container);
		$hierarchy = explode('.', $this->uriOf($key));
		$value = Session::getVal($this->uriOf($key));
		switch($this->_location)
		{
			case 'db':
			$engine = $this->_location;
			$ret_val = array_merge($ret_val, $this->_delete($this->container, $key));
			switch($ret_val['success'])
			{
				case true:
				$ret_val['class'] = $this->classes['success'];
				$this->_event['data'] = [
					'table' => 'config',
					'db' => DB::getDbName(),
					'key' => $key,
					'value' => $value,
					'section' => $ret_val['section'],
					'action' => "Delete Config",
					'message' => "On ".date("F j, Y @ g:i a")." user ".Session::getVal('securer.username')." deleted value ($key -> '".var_export($value, true)."') from config file ".basename($container)
				];
				$this->trigger('afterDelete');
				break;
			}
			break;
			
			case 'file':
			$container = $this->resolveDir($this->config('current.path'));
			switch(1)
			{
				case !$container:
				$ret_val['debug'] = "Sorry I cannot delete a value from a file that doesn't exist\nPlease try again again by passing the correct parameters to me.\delete($key, ".var_export($value, true)."), ".basename($container).", $sess_member) (0);";
				break;
				
				case !$key:
				$ret_val['debug'] = "Sorry I cannot delete an empty key\nPlease try again again by passing the correct parameters to me.\ndelete($key, ".var_export($value).", ".basename($container).", $sess_member) (1);";
				break;
				
				default:
				$ret_val = array_merge($ret_val, $this->_delete($container, $key));
				$sess_member = (empty($sess_member)) ? Session::settings : $sess_member.'.'.$this->container;
				switch($ret_val['success'])
				{
					case true:
					$ret_val['class'] = $this->classes['success'];
					$this->_event['data'] = [
						'table' => 'NULL',
						'db' => 'NULL',
						'key' => $key,
						'value' => $value,
						'section' => $ret_val['section'],
						'action' => "Delete Config",
						'message' => "On ".date("F j, Y @ g:i a")." user ".Session::getVal('securer.username')." deleted value ($key -> '".var_export($value, true)."') from config file ".basename($container)
					];
					$this->trigger('afterDelete');
					break;
				}
				break;
			}
			break;
		}
		$ret_val['action'] = 'delete';
		$this->config('current.action', $ret_val);
		Session::set(Configer::dm.'.action', $ret_val);
	}
	
	public function createContainer($name, $in=null, $engine='db')
	{
		$this->setEngine($engine);
		$ret_val = ["success" => false, 'class' => 'error'];
		switch($this->_location)
		{
			case 'db':
			$this->containerModel = new Container([
				'name' => $name,
				'scenario' => 'create'
			]);
			$message = '';
			switch($this->containerModel->save())
			{
				case true:
				$message .= "created container for $in";
				$data["sections"]['containerid'] = $this->containerModel->id; 
				$data["sections"]['name'] = 'global';
				$this->_event['data'] = [
					'table' => 'config',
					'db' => DB::getDbName(),
					'action' => 'create',
					'message' => "On ".date("F j, Y @ g:i a")." user ".Session::getVal('securer.username')." ".$message
				];
				$this->trigger('afterCreate');
				$ret_val['class'] = $this->classes['success'];
				break;
				
				default:
				$message ."Counldn't create container $name";
				break;
			}
			$ret_val['message'] = "The system ".$message;
			break;
			
			case 'file':
			$in = (!is_dir($in)) ? $this->dir['config'] : $in;
			$new_config_file = $in.$name.'.'.$this->_types[$this->_location];
			switch($this->_objects['file']->createFile($new_config_file))
			{
				case true:
				$ret_val['success'] = true;
				$ret_val['message'] = "The system was able to create the config file".basename($new_config_file);
				$e = new Event;
				$this->_event['data'] = [
					'table' => 'NULL',
					'db' => 'NULL',
					'action' => 'Create Config File',
					'message' => "On ".date("F j, Y @ g:i a")." user ".Session::getVal('securer.username')." created a new config file: ".basename($new_config_file)
				];
				$this->trigger('afterCreate');
				$ret_val['class'] = $this->classes['success'];
				break;
					
				default:
				$ret_val['message'] = "The system was unable to create the config file because ".basename($new_config_file)." already exists";
				break;
			}
			break;
		}
		$this->config('current.action', $ret_val);
	}
	
	/*---------------------
		Protected Functions
	---------------------*/
	
	/*
	 * Handle creating to DB or to file to simplify create function
	 * @param string|int $container
	 * @param string|int $key
	 * @return mixed
	 */
	protected function _create($container, $key, $originalValue=null)
	{
		$uriOf = $this->uriOf($key);
		$hierarchy = explode('.', $uriOf);
		$name = isset($hierarchy[4]) ? $hierarchy[4] : (sizeof($hierarchy) == 3 ? $hierarchy[2] : null);
		$sectionName = isset($hierarchy[3]) ? $hierarchy[3] : $hierarchy[1];
		$ret_val = [
			'value' => $originalValue,
			'success' => false,
			'key' => $uriOf,
			'section' =>$sectionName,
			'container' => $container,
			'message' => "Unable to create value ".$originalValue
		];
		
		$container = $this->container($container);
		switch($this->_location)
		{
			case 'db':
			switch(sizeof($hierarchy))
			{
				//We're creating a section
				case 4:
				case 2:
				$value = [
					'containerid' => $container->id,
					'name' => $sectionName,
				];
				$model = new Section($value);
				$message = "Added section ".$sectionName;
				break;
				
				//We're creating a value
				case 5:
				case 3:
				$value = [
					'containerid' => $container->id,
					'sectionid' => $this->section($sectionName)->id,
					'value' => $originalValue,
					'name' => $name
				];
				$model = new Value($value);
				$message = "Added $name to section $sectionName";
				break;
			}
			$model->setScenario('create');
			switch($model->save())
			{
				case true:
				$ret_val['value'] = rawurlencode($originalValue);
				$ret_val['id'] = $model->id;
				$ret_val['container_name'] = $ret_val['container'];
				$ret_val['unique_id'] = $key;
				$ret_val['section_name'] = $sectionName;
				$ret_val = array_merge($ret_val, $value);
				$ret_val['success'] = true;
				$ret_val['message'] = $message;
				break;
				
				default:
				$result['message'] = implode('<br>', array_map(function ($value) {
					return array_shift($value);
				}, $model->getErrors()));
				break;
			}
			break;
			
			case 'file':
			$args = [];
			switch(sizeof($hierarchy))
			{
				//we're creating a section
				case 5:
				case 2:
				$success = $this->_objects['file']->createSection($name);
				$message = "Added new section [".$sectionName."] to ".$container;
				break;
				
	
				//we're creating a value
				case 6:
				case 3:
				$sucess = $this->_objects['file']->createValue($sectionName, $name, $value);
				$message = "Added new config option [".$name."] to ".$sectionName;
				break;
			}
			//sed should return an empty value for success when updating files
			switch($success)
			{
				case 0:
				$ret_val['unique'] = $sectionName.'.'.$name;
				$ret_val['name'] = $name;
				$ret_val['container_name'] = $ret_val['container'];
				$ret_val['section_name'] = $sectionName;
				$ret_val['unique_id'] = $key;
				$ret_val['message'] = $message;
				$ret_val['success'] = true;
				break;
				
			}
			break;
		}
		return $ret_val;
	}
	
	/*
	 * Handled updating in DB or in file to simplify update function
	 * @param string|int $container
	 * @param string|int $key
	 * @return mixed
	 */
	protected function _update($container, $key, $value)
	{
		$uriOf = $this->uriOf($key);
		$hierarchy = explode('.', $uriOf);
		$old_value = Session::getVal($uriOf);
		$name = isset($hierarchy[4]) ? $hierarchy[4] : (sizeof($hierarchy) == 3 ? $hierarchy[2] : null);
		$sectionName = isset($hierarchy[4]) ? $hierarchy[3] : $hierarchy[1];
		$ret_val = [
			'success' => false,
			'old_value' => json_encode($old_value),
			'value' => rawurlencode($value),
			'section' => $sectionName,
			'container' => $key,
			'key' => $uriOf,
			'message' => "Unable to update value ".$value
		];
		switch($this->_location)
		{
			case 'db':
			switch(sizeof($hierarchy))
			{
				//we're updating a section
				case 4:
				case 2:
				$message = "Updated the section name to $value";
				$values = ['value' => $value];
				$model = $this->section(!$this->id ? $key : $this->id);
				break;
			
				//we're updating a value
				case 5:
				case 3:
				$message = "Updated the value [$key] from ".@$old_value['value']." to ".$value;
				$values = ['value' => $value];
				$ret_val['name'] = $name;
				$model = $this->value($sectionName, !$this->id ? $key : $this->id);
				break;
			}
			switch(is_object($model))
			{
				case true:
				$model->setScenario('update');
				$model->load([$model->formName() => $values]);
				switch($model->save())
				{
					case true:
					$ret_val['success'] = true;
					$ret_val['message'] = $message;
					break;
					
					default:
					$result['message'] = implode('<br>', array_map(function ($value) {
						return array_shift($value);
					}, $model->getErrors()));
					break;
				}
				break;
			}
			break;
			
			case 'file':
			$args = [];
			$container = $this->resolveDir($this->config('current.path'));
			switch(sizeof($hierarchy))
			{
				//we're updating a section
				case 4:
				case 2:
				$success = $this->_objects['file']->updateSection($sectionName, $value);
				$message = "Updated the section name from ".$name." to $value";
				break;
			
				//no support for updating section names as of yet
				case 5: 
				case 3:    
				$success = $this->_objects['file']->updateValue($sectionName, $name, $name, $value);
				$message = "Updated the value name from ".$name." to $value";
				break;
			}
			//sed should return an empty value for success when updating files
			switch($success)
			{
				case 0:
				$ret_val['message'] = $message;
				$ret_val['success'] = true;
				break;
				
			}
			break;
		}
		return $ret_val;
	}
	
	/*
	 * Handle deleting in DB or in file to simplify delete function
	 * @param string|int $container
	 * @param string|int $key
	 * @return mixed
	 */
	protected function _delete($container, $key)
	{
		$uriOf = $this->uriOf($key);
		$hierarchy = explode('.', $uriOf);
		$name = isset($hierarchy[4]) ? $hierarchy[4] : (sizeof($hierarchy) == 3 ? $hierarchy[2] : null);
		$sectionName = isset($hierarchy[4]) ? $hierarchy[3] : $hierarchy[1];
		$ret_val = [
			'success' => false,
			'container' => $key,
			'value' => Session::getVal($uriOf),
			'key' => $uriOf,
			'message' => "Unable to delete ".$key,
			'section' => $sectionName
		];
		
		switch($this->_location)
		{
			case 'db':
			switch(sizeof($hierarchy))
			{
				//we're deleting a section
				case 4:
				case 2:
				$model = $this->section(!$this->id ? $key : $this->id);
				$message = "Deleted the section: $key";
				$delete['process'] = true;
				break;
			
				//we're deleting a value
				case 5:
				case 3:
				$ret_val['name'] = $name;
				$message = "Deleted the value: $key";
				$model = $this->value($sectionName, !$this->id ? $key : $this->id);
				break;
			}
			switch(is_object($model) && $model->delete())
			{
				case true:
				$ret_val['success'] = true;
				$ret_val['message'] = $message;
				break;
				
				default:
				$ret_val['success'] = true;
				$ret_val['message'] = "'$key' may have already been deleted";
				break;
			}
			break;
			
			case 'file':
			$args = [];
			$container = $this->resolveDir($this->config('current.path'));
			switch(sizeof($hierarchy))
			{
				//are we deleting a value/line?
				case 6:
				case 3:
				$success = $this->_objects['file']->deleteValue($name, $sectionName);
				$message = "Deleted value ".$hierarchy." in ".$sectionName;
				break;
				
				//we're deleting a section
				case 5:
				case 2:
				$success = $this->_objects['file']->deleteSection($sectionName);
				$args['command'] = "sed -i '/^\[%s\]/,/^$/d' ";
				$args['args'] = [$name];
				$message = "Deleted the section ".$name;
				break;
				
				//we're deleting a container
				case 1:
				$success = $this->_objects['file']->deletefile($container);
				$message = "Deleted the file ".$container;
				break;
			}
			//sed should return an empty value for success when updating files
			switch($success)
			{
				case 0:
				$ret_val['message'] = $message;
				$ret_val['success'] = true;
				break;
				
			}
			break;
		}
		return $ret_val;
	}
	
	/*
	 * Get teh proper path for this container
	 * @param string $container
	 * @return string
	 */
	protected function resolveDir($container)
	{
		return ($container[0] == '@') ? $this->dir['config'].substr($container, 1, strlen($container)) : $container;
	}
	
	/*
	 * Log the data to the DB
	 * @param mixed $data
	 */
	protected function log($data=[])
	{
		$this->initLogging();
		$this->l->addTrans($data['table'], $data['db'], $data['action'], $data['message']);
	}
	
	/*
	 * Get the configuration containers: file or database
	 * @param string $in
	 * @param boolean $multi
	 * @param boolean $objectsOnly
	 * @return mixed
	 */
	protected function getContainers($in=null, $objectsOnly=false)
	{
		$in = ($in == null) ? $this->dir['config'] : $in;
		$ret_val = [];
		switch($this->_location)
		{
			case 'db':
			switch(isset(static::$_containers))
			{
				case false:
				$result = Container::find()->select(['id', 'name'])->indexBy('name')->all();
				static::$_cache = $result;
				array_walk($result, function ($val, $key) use(&$ret_val, $in) {
					if($in == $val->name)
						$this->containerModel = $val;
					$ret_val[$val->name] = $val->name;
				});
				static::$_containers = $ret_val;
				$this->config('containers', $ret_val);
				$this->config('load.containers', true);
				break;
				
				default:
				$this->config('containers', static::$_containers);
				$this->config('load.containers', true);
				break;
			}
			break;
			
			case 'file':
			$this->config('containers', $this->_objects['file']->getFiles($in, $objectsOnly));
			$this->config('load.containers', (bool)count($this->config('containers'))>=1);
			break;
		}
		return $ret_val;
	}
	
	/*
	 * Get the configuration containers: file or database
	 * @param string $in
	 * @param boolean $multi
	 * @param boolean $containers_objectsnly
	 * @return mixed
	 */
	protected function getSections($in=null)
	{
		$ret_val = [];
		$in = is_null($in) ? $this->container : $in;
		switch($this->_location)
		{
			case 'db':
			switch(is_null($in))
			{
				case true:
				if($this->container())
					$result = (array)$this->container()->sections;
				else
					$result = [];
				break;
				
				default:
				if($this->container($in))
					$result = (array)$this->container($in)->getSections()->select(['id', 'name'])->all();
				else
					$result = [];
				break;
			}
			array_walk($result, function ($val, $key) use(&$ret_val) {
				$ret_val[$val->name] = $val->name;
			});
			$this->config('sections', $ret_val);
			$this->config('load.sections', true);
			break;
			
			case 'file':
			$in = ($in == null) ? $this->dir['config'] : $in;
			$this->config('sections', $this->_objects['file']->getNames($in));
			$this->config('load.sections', (bool)count($this->config('sections'))>=1);
			break;
		}
		return $ret_val;
	}
	
	/*---------------------
		Private Functions
	---------------------*/
	
	private  function deleteContainer($in, $name, $ext)
	{
		$ret_val = ["success" => false];
		switch($this->_location)
		{
			case 'db':
			switch(Section::updateAll(['deleted' => 1], ['containerid' => $name]))
			{
				case true:
				$ret_val['success'] = true;
				$message .= "deleted config for $name in $name\n\n";
				$this->trigger('afterDelete', new Event($this, [
						'table' => 'config',
						'db' => DB::getDbName(),
						'action' => $action[$result],
						'message' => "On ".date("F j, Y @ g:i a")." user ".Session::getVal('securer.username')." ".$message
					])
				);
				break;
				
				default;
				$message .= "couldn't delete config for $name\n\n";
				break;
			}
			$ret_val['message'] = "I ".$message;
			break;
			
			case 'file':
			$config_file = $in.$name.'.'.$ext;
			$ret_val['message'] = 'I was unable to delete the config file '.basename($config_file);
			switch(empty($name))
			{
				case false:
				if($this->_objects['file']->deleteFile($config_file))
				{
					$this->trigger('afterDelete', new Event($this, [
							'table' => 'NULL',
							'db' => "NULL",
							'action' => "Delete Config File",
							'message' => $action[$result], "On ".date("F j, Y @ g:i a")." user ".Session::getVal('securer.username')." deleted config file: ".basename($config_file)
						])
					);
					$ret_val['success'] = true;
					$ret_val['message'] = 'I was able to delete the config file '.basename($config_file);
				}
				break;
			}
			break;
		}
		return $ret_val;
	}
	
	/*
	 * Is this engine supported?
	 * @param string $engine
	 * @return boolean is supported?
	 */
	 private function isSupported($engine)
	 {
		return isset($this->_supported[$engine]);
	 }
	 
	 /*
	  * Get the container for a given value
	  * @param string|int $container
	  * @return int containerid
	  */
	private function container($container=null)
	{
		$ret_val = $this->containerModel;
		$container = is_null($container) ? $this->container : $container;
		switch($this->_location)
		{
			case 'file':
			$ret_val = [$this->resolveDir($this->config('current.path'))];
			break;
			
			case 'db':
			switch(isset(static::$_cache[$container]))
			{
				case false:
				switch(1)
				{
					case !$this->containerModel instanceof Container:
					case !is_null($container) && (is_object($this->containerModel) && !($this->containerModel->name == $container || $this->containerModel->id == $container)):
					$where = is_numeric($container) ? ['id' => $container] : ['name' => $container];
					$model = Container::find()
						->where($where)
						->with('sections')
						->one();
					switch($model instanceof Container)
					{
						case true:
						$this->containerModel = $model;
						static::$_cache[$this->containerModel->name] = $ret_val;
						$ret_val = $this->containerModel;
						break;
					}
				}
				break;
				
				default:
				$ret_val = static::$_cache[$container];
				$this->containerModel = $ret_val;
				break;
			}
			break;
		 }
		 return $ret_val;
	 }
	 
	/*
	 * Get the section id for a given value
	 * @param string|int $container
	 * @param string|int $section
	 * @return int containerid
	 */
	private function section($section)
	{
		$ret_val = null;
		switch(isset($this->container()->sections[$section]))
		{
			case false:
			if(!$this->sectionModel instanceof Section)
			{
				$where = is_numeric($section) ? ['id' => $section] : ['name' => $section];
				$found = $this->containerModel->getSections()
					->where($where)
					->one();
				$this->sectionModel = $found instanceof Section ? $found : null;
				$ret_val = $this->sectionModel;
				static::$_cache[$this->containerModel->name]->populateRelation('sections', array_merge(static::$_cache[$this->containerModel->name]->sections, [$section => $ret_val]));
			}
			break;
				
			default:
			$ret_val = static::$_cache[$this->containerModel->name]->sections[$section];
			break;
		}
		return $ret_val;
	}
	 
	/*
	 * Get the section id for a given value
	 * @param string|int $container
	 * @param string|int $section
	 * @return int containerid
	 */
	private function value($section, $id)
	{
		$ret_val = null;
		$sectionModel = $this->section($section);
		if(!$sectionModel instanceof Section)
			return null;
		else
			$where = is_numeric($id) ? ['id' => $id] : ['name' => $id];
		
		$where['sectionid'] = $sectionModel->getId();
		$where['containerid'] = $sectionModel->containerid;
		$ret_val = Value::find()
			->where($where)
			->one();
		
		return $ret_val;
	}
	 
	private static function hasNew()
	{
		switch(isset(self::$hasNew))
		{
			case false:
			self::$hasNew = (static::find()->where(new \yii\db\Expression("SELECT 1 FROM ".$this->configTables['containers']." WHERE NOW()-MAX(updated_at) >= 10000"))->count() >=1) ? true : false;
			break;
		}
		return self::$hasNew;
	}
}
?>
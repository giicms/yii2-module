<?php

namespace nitm\models\configer;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "config_values".
 *
 * @property integer $id
 * @property integer $containerid
 * @property integer $sectionid
 * @property string $name
 * @property string $value
 * @property string $comment
 * @property integer $author_id
 * @property integer $editor_id
 * @property string $created_at
 * @property string $updated_at
 * @property integer $deleted
 *
 * @property Section $section
 * @property Container $container
 */
class BaseConfiger extends ActiveRecord
{

	public $container;
	public $unique_id;
	public $unique_name;
	public $section_name;
	public $container_name;
	protected static $is;

	public function init()
	{
		parent::init();
	}

	public function behaviors()
	{
		$behaviors = [
		];
		//Setup author/editor
		$behaviors["blamable"] = [
		'class' => \yii\behaviors\BlameableBehavior::className(),
			'attributes' => [
				ActiveRecord::EVENT_BEFORE_INSERT => 'author_id',
				ActiveRecord::EVENT_BEFORE_UPDATE => 'editor_id',
			],
		];
		//Setup timestamping
		$behaviors['timestamp'] = [
			'class' => \yii\behaviors\TimestampBehavior::className(),
			'attributes' => [
				ActiveRecord::EVENT_BEFORE_INSERT => 'created_at',
				ActiveRecord::EVENT_BEFORE_UPDATE => 'updated_at',
			],
			'value' => new \yii\db\Expression('NOW()')
		];
		return array_merge(parent::behaviors(), $behaviors);
	}

	public function scenarios() {
		return [
			'default' => []
		];
	}

	/*
	 * What does this claim to be?
	 */
	public static function isWhat()
	{
		switch(empty(static::$is))
		{
			case true:
			static::$is = strtolower(array_pop(explode('\\', static::className())));
			break;
		}
		return static::$is;
	}

	/**
	 * Get the unique ID of this object
	 * @return string|int
	 */
	public function getId()
	{
		$key = $this->primaryKey();
		return (int)$this->$key[0];
	}
}

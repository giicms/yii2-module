<?php

namespace nitm\traits;

use Yii;
use yii\base\Model;
use yii\base\Event;
use nitm\models\Alerts as AlertModel;

/**
 * trait Alerts
 * May merge into nitm Module
 * @package nitm\module
 */

trait Alerts
{
	protected $_alerts;
	
	protected function prepareAlerts($event, $for='any', $priority='any')
	{
		if($event->handled)
			return;
		$this->_alerts = \Yii::$app->getModule('nitm')->alerts;
		$alerts = [];
		$alerts['remote_type'] = $event->sender->isWhat();
		$alerts['remote_for'] = $for;
		$alerts['priority'] = $priority;
		$this->_alerts->prepare($event->sender->getIsNewRecord(), $alerts);
	}
	
	/**
	 * Process the alerts according to $message and $parameters
	 * @param array $message = the subject and mobile/email messages:
	 * [
	 *		'subject' => String
	 *		'message' => [
	 *			'email' => The email message
	 *			'mobile' => The mobile/text message
	 *		]
	 * ]
	 * @param array $options = an array of parameters to be used during alert creation
	 */
	protected function processAlerts($event, $options=[])
	{
		if($event->handled)
			return;
		switch(!$this->_alerts->criteria('action'))
		{
			case false:
			switch($this->_alerts->isPrepared())
			{
				case true:
				//First check to see if this specific alert exits
				$this->_alerts->sendAlerts($options, $options['owner_id']);
				$event->handled = true;
				break;
				
				default:
				throw new \yii\base\Exception("You need to call \$this->prepareAlerts() before calling \self::processAlerts");
				break;
			}
			break;
			
			default:
			throw new \yii\base\Exception("Need an action to process the alert");
			break;
		}
	}
}
?>
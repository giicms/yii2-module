<?php
/**
 * @link http://www.nitm.com/
 * @copyright Copyright (c) 2014 NITM Inc
 */

namespace nitm\assets;

use yii\web\AssetBundle;

/**
 * @author Malcolm Paul admin@nitm.com
 */
class AppAsset extends AssetBundle
{
	public $sourcePath = '@nitm/assets/';
	public $css = [
		'css/base.css'
	];
	public $js = [
		'js/nitm.js',
		'js/entity.js',
		'js/tools.js',
		'js/utils.js',
		'js/animations.js',
	];
	public $jsOptions = ['position' => \yii\web\View::POS_END];
	public $depends = [
		'yii\web\YiiAsset',
	];
}

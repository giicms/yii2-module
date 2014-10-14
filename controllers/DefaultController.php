<?php

namespace nitm\controllers;
use \yii\helpers\Html;
use nitm\models\Category;
use nitm\helpers\Icon;
use nitm\helpers\Response;
use nitm\helpers\Helper;

class DefaultController extends BaseController
{
	use \nitm\traits\Widgets;
	
	public $boolResult;
	/**
	 * Redirect requests to the index page to the search function by default
	 */
	public $indexToSearch = true;
	public static $currentUser;
	
	public function init()
	{
		parent::init();
		static::$currentUser =  \Yii::$app->user->identity;
	}
	
	public function behaviors()
	{
		$behaviors = [
			'access' => [
				'class' => \yii\filters\AccessControl::className(),
				'rules' => [
					[
						'actions' => ['login', 'error'],
						'allow' => true,
						'roles' => ['?']
					],
					[
						'actions' => [
							'index', 'add', 'list', 'view', 'create', 
							'update', 'delete', 'form', 'search', 'disable',
							'close', 'resolve', 'complete', 'error'
						],
						'allow' => true,
						'roles' => ['@'],
					],
				],
			],
			'verbs' => [
				'class' => \yii\filters\VerbFilter::className(),
				'actions' => [
					'index' => ['get', 'post'],
					'list' => ['get', 'post'],
					'add' => ['get'],
					'view' => ['get'],
					'delete' => ['post'],
					'create' => ['post', 'get'],
					'update' => ['post', 'get'],
					'search' => ['get', 'post']
				],
			],
		];
		return array_merge(parent::behaviors(), $behaviors);
	}
	
    /**
	* @inheritdoc
	*/
    public function actions()
    {
        return [
            'error' => [
                'class' => 'yii\web\ErrorAction',
            ],
        ];
    }
	
	public function beforeAction($action)
	{
		switch($action->id)
		{
			case 'delete':
			case 'disable':
			case 'resolve':
			case 'complete':
			case 'close':
			$this->enableCsrfValidation = false;
			break;
		}
		return parent::beforeAction($action);
	}

    /**
     * Lists all Updates models.
     * @return mixed
     */
    public function actionIndex($className, $options=[])
    {
		$options = array_merge([
			'params' => \Yii::$app->request->getQueryParams(),
			'with' => [], 
			'viewOptions' => [], 
			'construct' => [
				'queryOptions' => [
					'orderBy' => ['id' => SORT_DESC]
				]
			],
			'orderBy' => 'id'
		], $options);
        $searchModel = new $className($options['construct']);
		$searchModel->addWith($options['with']);
        $dataProvider = $searchModel->search($options['params']);
		$dataProvider->pagination->route = '/'.$this->id.'/search';
		switch((sizeof($options['params']) == 0) || !isset($options['params']['sort']))
		{	
			case true:
			switch(1)
			{
				case $searchModel instanceof \nitm\search\BaseElasticSearch:
				$dataProvider->query->orderBy([
					$searchModel->primaryModel->primaryKey()[0] => [
						'order' => 'desc',
						'ignore_unmapped' => true
					]
				]);
				break;
				
				default:
				$dataProvider->query->orderBy([
					$searchModel->primaryModel->primaryKey()[0] => SORT_DESC
				]);
				break;
			}
			$dataProvider->pagination->params['sort'] = '-'.$searchModel->primaryModel->primaryKey()[0];
			break;
		}

        return $this->render('index', array_merge([
            'dataProvider' => $dataProvider,
            'searchModel' => $searchModel,
			'model' => $this->model
        ], $options['viewOptions']));
    }
	
	public function actionSearch($options=[], $searchOptions=[])
	{
		$ret_val = [
			"success" => false, 
			'action' => 'filter',
			"format" => $this->getResponseFormat(),
			'message' => "No data found for this filter"
		];
		$searchModelOptions = array_merge([
			'inclusiveSearch' => true,
			'booleanSearch' => true
		], $searchOptions);
		$class = (isset($options['namespace']) ? $options['namespace'] : '\nitm\models\search\\').$this->model->formName();
		switch(class_exists($class))
		{
			case true:
			$className = $class::className();
			$searchModel = new $className($searchModelOptions);
			break;
			
			default:
			$class = (isset($options['namespace']) ? rtrim($options['namespace'], '\\')."\BaseSearch" : '\nitm\models\search\BaseSearch');
			$className = $class::className();
			break;
		}
		
		if(!\Yii::$app->request->isAjax)
			return $this->actionIndex($className, [
				'construct' => $searchModelOptions
			]);

		$searchModel = new $className($searchModelOptions);
		if(!Response::formatSpecified())
		{
			$this->setResponseFormat('html');
		}
		
		/**
		 * Remove the __format paramater as it causes problems with 
		 */
		unset($_REQUEST['__format'], $_GET['__format']);
        $dataProvider = $searchModel->search($_REQUEST);
		
		$view = $this->getViewPath().DIRECTORY_SEPARATOR.ltrim('data', '/').'.php';
		$ret_val['data'] = $this->renderAjax((file_exists($view) ? 'data' : 'index'), [
			"dataProvider" => $dataProvider,
			'searchModel' => $searchModel,
			'primaryModel' => $this->model
		]);
		if(!\Yii::$app->request->isAjax)
		{
			$ret_val['data'] = Html::tag('div',
				\yii\widgets\Breadcrumbs::widget(['links' => [
					[
						'label' => $searchModel->primaryModel->properName($searchModel->primaryModel->isWhat()), 
						'url' => $searchModel->primaryModel->isWhat()
					],
					[
						'label' => 'Search',
					]
				]]).
				$ret_val['data'], ['class' => 'col-md-12 col-lg-12']
			);
			$this->setResponseFormat('html');
		}
		$ret_val['message'] = !$dataProvider->getCount() ? $ret_val['message'] : "Found ".$dataProvider->getTotalCount()." results matching your search";
		Response::$viewOptions['args'] = [
			"content" => $ret_val['data'],
		];
		return $this->renderResponse($ret_val, Response::$viewOptions, \Yii::$app->request->isAjax);
	}
	
	/*
	 * Get the forms associated with this controller
	 * @param string $param What are we getting this form for?
	 * @param int $unique The id to load data for
	 * @param array $options
	 * @return string | json
	 */
	public function actionForm($type=null, $id=null, $options=[])
	{
		$force = false;
		$options['id'] = $id;
		$options['param'] = $type;
		switch($type)
		{	
			//This is for generating the form for updating and creating a form for $this->model->className()
			default:
			$action = !$id ? 'create' : 'update';
			$options['title'] = !isset($options['title']) ? ['title', 'Create '.static::properName($this->model->isWhat())] : $options['title'];
			$options['scenario'] = $action;
			$options['provider'] = null;
			$options['dataProvider'] = null;
			$options['view'] = $type;
			$options['args'] = [false, true, true];
			$options['modelClass'] = $this->model->className();
			$options['force'] = true;
			break;
		}
		$modalOptions = [
			'body' => [
				'class' => 'modal-full'
			],
			'dialog' => [
				'class' => 'modal-full'
			],
			'content' => [
				'class' => 'modal-full'
			],
			'contentOnly' => true
		];
		
		$format = Response::formatSpecified() ? $this->getResponseFormat() : 'html';
		$this->setResponseFormat($format);
		return $this->renderResponse($this->getFormVariables($this->model, $options, $modalOptions), Response::$viewOptions, \Yii::$app->request->isAjax);
	}

    /**
     * Displays a single model.
     * @param integer $id
     * @return mixed
     */
    public function actionView($id, $modelClass=null, $with=[])
    {
		$modelClass = !$modelClass ? $this->model->className() : $modelClass;
        $this->model =  $this->findModel($modelClass, $id, $with);
		Response::$viewOptions = [
			"view" => '@nitm/views/view/index',
			'args' => [
				'content' => $this->renderAjax('/'.$this->model->isWhat().'/view', ["model" => $this->model]),
			],
			'scripts' => new \yii\web\JsExpression("\$nitm.onModuleLoad('nitm', function (){\$nitm.module('nitm').initForms(null, 'nitm:".$this->model->isWhat()."');\$nitm.module('nitm').initMetaActions(null, 'nitm:".$this->model->isWhat()."');})")
		];
		Response::$forceAjax = false;
		return $this->renderResponse(null, Response::$viewOptions, (\Yii::$app->request->get('__contentOnly') ? true : \Yii::$app->request->isAjax));
    }
	
    /**
     * Creates a new Category model.
     * If creation is successful, the browser will be redirected to the 'view' page.
     * @return mixed
     */
    public function actionCreate($modelClass=null, $viewOptions=[])
    {
		$this->action->id = 'create';
		$ret_val = false;
		$result = [];
		$modelClass = !$modelClass ? $this->model->className() : $modelClass;
		$post = \Yii::$app->request->post();
        $this->model =  new $modelClass(['scenario' => 'create']);
		$this->model->load($post);
		switch(\Yii::$app->request->isAjax && (@Helper::boolval($_REQUEST['do']) !== true) && !\Yii::$app->request->get('_pjax'))
		{
			case true:
			$this->setResponseFormat('json');
			return \yii\widgets\ActiveForm::validate($this->model);
			break;
		}
		
		switch(\Yii::$app->request->isAjax)
		{
			case true:
			$this->setResponseFormat(\Yii::$app->request->get('_pjax') ? 'html' : 'json');
			break;
			
			default:
			$this->setResponseFormat('html');
			break;
		}
        if (!empty($post) && $this->model->save()) {
			$metadata = isset($post[$this->model->formName()]['contentMetadata']) ? $post[$this->model->formName()]['contentMetadata'] : null;
			$ret_val = true;
			switch($metadata && $this->model->addMetadata($metadata))
			{
				case true:
				\Yii::$app->getSession()->setFlash(
					'success',
					"Added metadata"
				);
				break;
			}
			Response::$viewOptions["view"] = '/'.$this->model->isWhat().'/view';
        } else {
			if(!empty($post)) {
				$result['message'] = implode('<br>', array_map(function ($value) {
					return array_shift($value);
				}, $this->model->getErrors()));
				\Yii::$app->getSession()->setFlash('error', $result['message']);
			}
			Response::$viewOptions["view"] = '/'.$this->model->isWhat().'/create'; 
        }
		Response::$viewOptions["args"] = array_merge($viewOptions, ["model" => $this->model]);
		return $this->finalAction($ret_val, $result);
    }
	
	/**
     * Updates an existing Category model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param integer $id
     * @return mixed
     */
    public function actionUpdate($id, $modelClass=null, $with=[], $viewOptions=[])
    {
		$this->action->id = 'update';
		$ret_val = false;
		$result = [];
		$modelClass = !$modelClass ? $this->model->className() : $modelClass;
		$post = \Yii::$app->request->post();
        $this->model =  $this->findModel($modelClass, $id, $with);
		$this->model->setScenario('update');
		$this->model->load($post);
		switch(\Yii::$app->request->isAjax && (@Helper::boolval($_REQUEST['do']) !== true) && !\Yii::$app->request->get('_pjax'))
		{
			case true:
			$this->setResponseFormat('json');
			return \yii\widgets\ActiveForm::validate($this->model);
			break;
		}
		switch(\Yii::$app->request->isAjax && !Response::formatSpecified())
		{
			case true:
			$this->setResponseFormat(\Yii::$app->request->get('_pjax') ? 'html' : 'json');
			break;
			
			default:
			$this->setResponseFormat('html');
			break;
		}
        if (!empty($post) && $this->model->save()) {
			$metadata = isset($post[$this->model->formName()]['contentMetadata']) ? $post[$this->model->formName()]['contentMetadata'] : null;
			$ret_val = true;
			switch($metadata && $this->model->addMetadata($metadata))
			{
				case true:
				\Yii::$app->getSession()->setFlash(
					'success',
					"Updated metadata"
				);
				break;
			}
			$result['message'] = "Succesfully updated ".$this->model->isWhat();
			Response::$viewOptions["view"] = '/'.$this->model->isWhat().'/view';
        } else {
			if(!empty($post)) {
				$result['message'] = implode('<br>', array_map(function ($value) {
					return array_shift($value);
				}, $this->model->geterrors()));
				\Yii::$app->getSession()->setFlash('error', $result['message']);
			}
			Response::$viewOptions["view"] = '/'.$this->model->isWhat().'/update'; 
        }
		Response::$viewOptions["args"] = array_merge($viewOptions, ["model" => $this->model]);
		return $this->finalAction($ret_val, $result);
    }

    /**
     * Deletes an existing Category model.
     * If deletion is successful, the browser will be redirected to the 'index' page.
     * @param integer $id
     * @return mixed
     */
    public function actionDelete($id, $modelClass=null)
    {
		$this->action->id = 'delete';
		$deleted = false;
		$modelClass = !$modelClass ? $this->model->className() : $modelClass;
        $this->model =  $this->findModel($modelClass, $id);
		if(is_object($this->model))
		{
			switch(1)
			{
				case \Yii::$app->user->identity->isAdmin():
				case $this->model->hasAttribute('author_id') && ($this->model->author_id == \Yii::$app->user->getId()):
				case $this->model->hasAttribute('user_id') && ($this->model->user_id == \Yii::$app->user->getId()):
				if($this->model->delete())
				{
					$deleted = true;
					$this->model = new $modelClass;
					$this->model->id = $id;
				}
				$deleted = true;
				break;
			}
		}
		switch(\Yii::$app->request->isAjax)
		{
			case true:
			$this->setResponseFormat('json');
			return $this->finalAction($deleted);
			break;
			
			default:
			return $this->redirect(\Yii::$app->request->getReferrer());
			break;
		}
    }
	
	public function actionClose($id)
	{
		return $this->booleanAction($this->action->id, $id);
	}
	
	public function actionComplete($id)
	{
		return $this->booleanAction($this->action->id, $id);
	}
	
	public function actionResolve($id)
	{
		return $this->booleanAction($this->action->id, $id);
	}
	
	public function actionDisable($id)
	{
		return $this->booleanAction($this->action->id, $id);
	}

    public static function booleanActions()
	{
		return [
			'close' => [
				'scenario' => 'close',
				'attributes' => [
					'attribute' => 'closed',
					'blamable' => 'closed_by',
					'date' => 'closed_at'
				],
				'title' => [
					'Close',
					'Re-Open'
				]
			],
			'complete' => [
				'scenario' => 'complete',
				'attributes' => [
					'attribute' => 'completed',
					'blamable' => 'completed_by',
					'date' => 'completed_at'
				],
				'title' => [
					'Complete',
					'In-Complete'
				]
			],
			'resolve' => [
				'scenario' => 'resolve',
				'attributes' => [
					'attribute' => 'resolved',
					'blamable' => 'resolved_by',
					'date' => 'resolved_at'
				],
				'title' => [
					'Resolve',
					'Un-Resolve'
				]
			],
			'disable' => [
				'scenario' => 'disable',
				'attributes' => [
					'attribute' => 'disabled',
					'blamable' => 'disabled_by',
					'date' => 'disabled_at'
				],
				'title' => [
					'Enable',
					'Disable'
				]
			],
			'delete'=> [
				'scenario' => 'delete',
				'attributes' => [
					'attribute' => 'deleted',
					'blamable' => 'deleted_by',
					'date' => 'deleted_at'
				],
				'title' => [
					'Delete',
					'Restore'
				]
			]
		];
	}
	
	protected function booleanAction($action, $id)
	{
		$saved = false;
        $this->model = $this->findModel($this->model->className(), $id);
		if(array_key_exists($action, static::booleanActions()))
		{
			extract(static::booleanActions()[$action]);
			$this->model->setScenario($scenario);
			$this->boolResult = !$this->model->getAttribute($attributes['attribute']) ? 1 : 0;
			foreach($attributes as $key=>$value)
			{
				switch($this->model->hasAttribute($value))
				{
					case true:
					switch($key)
					{
						case 'blamable':
						$this->model->setAttribute($value, (!$this->boolResult ? null : \Yii::$app->user->getId()));
						break;
						
						case 'date':
						$this->model->setAttribute($value, (!$this->boolResult ? null : new \yii\db\Expression('NOW()')));
						break;
					}
					break;
				}
			}
			$this->model->setAttribute($attributes['attribute'], $this->boolResult);
			$this->setResponseFormat('json');
			$saved = $this->model->save();
		}
		return $this->finalAction($saved);
	}
	
	/**
	 * Put here primarily to handle action after create/update
	 */
	protected function finalAction($saved=false, $args=[])
	{
		$ret_val = is_array($args) ? $args : [
			'success' => false,
		];
        if ($saved) {
			switch(\Yii::$app->request->isAjax)
			{
				case true:
				switch(array_key_exists($this->action->id, static::booleanActions()))
				{
					case true:
					extract(static::booleanActions()[$this->action->id]);
					$ret_val['success'] = true;
					$booleanValue = $this->model->getAttribute($attributes['attribute']);
					$ret_val['title'] = $title[$booleanValue];
					$iconName = isset($icon) ? $icon[$booleanValue] : $this->action->id;
					$ret_val['actionHtml'] = Icon::forAction($iconName, $booleanValue);
					$ret_val['data'] = $this->boolResult;
					$ret_val['class'] = 'wrapper';
					switch(\Yii::$app->request->get(static::ELEM_TYPE_PARAM))
					{
						case 'li':
						$ret_val['class'] .= ' '.\nitm\helpers\Statuses::getListIndicator($this->model->getStatus());
						break;
						
						default:
						if(method_exists($this->model, 'getStatus'))
							$ret_val['class'] .= ' '.\nitm\helpers\Statuses::getIndicator($this->model->getStatus());
						break;
					}
					break;
					
					default:
					$format = Response::formatSpecified() ? $this->getResponseFormat() : 'json';
					$this->setResponseFormat($format);
					if($this->model->hasAttribute('created_at')) {
						$this->model->created_at = \nitm\helpers\DateFormatter::formatDate($this->model->created_at);
					}
					switch($this->action->id)
					{
						case 'update':
						if($this->model->hasAttribute('updated_at')) {
							$this->model->updated_at = \nitm\helpers\DateFormatter::formatDate($this->model->updated_at);
						}
						break;
					}
					$viewFile = 'view';
					$ret_val['success'] = true;
					switch($this->getResponseFormat())
					{
						case 'json':
						if(file_exists($this->getViewPath() . DIRECTORY_SEPARATOR . ltrim($viewFile, '/').'.php'))
							$ret_val['data'] = $this->renderAjax($viewFile, ["model" => $this->model]);
						break;
						
						default:
						if(file_exists($this->getViewPath() . DIRECTORY_SEPARATOR . ltrim($viewFile, '/')))
							Response::$viewOptions['content'] = $this->renderAjax($viewFile, ["model" => $this->model]);
						else
							Response::$viewOptions['content'] = true;
						break;
					}
					break;
				}
				break;
					
				default:
				\Yii::$app->getSession()->setFlash(
					@$ret_val['class'],
					@$ret_val['message']
				);
				return $this->redirect(['index']);
				break;
			}
        }
		$ret_val['message'] = ($this->model->validate() && !$saved) ? "No need to update. Everything is the same" : @$ret_val['message'];
		$ret_val['action'] = $this->action->id;
		$ret_val['id'] = $this->model->getId();
		return $this->renderResponse($ret_val, Response::$viewOptions, \Yii::$app->request->isAjax);
	}
}
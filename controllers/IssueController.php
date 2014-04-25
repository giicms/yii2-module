<?php

namespace nitm\controllers;

use Yii;
use nitm\models\Issues;
use nitm\models\search\Issues as IssuesSearch;
use nitm\helpers\Response;
use nitm\helpers\Icon;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;
use yii\data\ArrayDataProvider;

/**
 * IssueController implements the CRUD actions for Issues model.
 */
class IssueController extends WidgetController
{
	use \nitm\traits\Widgets;
	
	public $legend = [
		'success' => 'Closed and Resolved',
		'warning' => 'Closed and Unresolved',
	];
	
	protected $result;
	
	public function init()
	{
		parent::init();
		$this->model = new Issues(['scenario' => 'default']);
	}
	
    public function behaviors()
    {
        return [
			'access' => [
				'class' => \yii\filters\AccessControl::className(),
				//'only' => ['index', 'update', 'create', 'index', 'get', 'delete', 'convert', 'undelete'],
				'rules' => [
					[
						'actions' => ['index',  'create',  'update',  'delete', 'resolve','close', 'duplicate', 'form'],
						'allow' => true,
						'roles' => ['@'],
					],
				],
			],
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'delete' => ['post'],
                    'create' => ['post'],
                    'update' => ['post'],
                    'close' => ['post'],
                    'resolve' => ['post'],
                    'duplicate' => ['post'],
                ],
            ],
        ];
    }
	
	public static function has()
	{
		$has = [
			'\nitm\widgets\issueTracker'
		];
		return array_merge(parent::has(), $has);
	}

    /**
     * Lists all Issues models.
	 * @param string $type The parent type of the issue
	 * @param int $id The id of the parent
     * @return mixed
     */
    public function actionIndex($type, $id)
    {
        $searchModel = new IssuesSearch;
		$get = !\Yii::$app->request->getQueryParams() ? [] : \Yii::$app->request->getQueryParams();
        $dataProviderOpen = $searchModel->search(array_merge($get, ['closed' => 0]));
        $dataProviderClosed = $searchModel->search(array_merge($get, ['closed' => 1]));
		
        Response::$viewOptions['view'] = 'index';
		Response::$viewOptions['args'] = [
            'dataProviderOpen' => $dataProviderOpen,
            'dataProviderClosed' => $dataProviderClosed,
            'searchModel' => $searchModel,
			'useModal' => \Yii::$app->request->isAjax,
			'parentType' => $type,
			'parentId' => $id,
        ];
		switch(\Yii::$app->request->isAjax)
		{
			case false:
			Response::$viewOptions['args']['modal'] = \nitm\widgets\modal\Modal::widget([
				'options' => [
					'id' => 'issue-tracker-modal',
					"style" => "z-index: 10000",
				],
				'dialogOptions' => [
					"class" => "modal-vertical-centered"
				]
			]);
			break;
		}
		return $this->renderResponse(null, Response::$viewOptions, \Yii::$app->request->isAjax);
    }

    /**
     * Displays a single Issues model.
     * @param integer $id
     * @return mixed
     */
    public function actionView($id)
    {
        return $this->render('view', [
            'model' => $this->findModel(Issues::className(), $id),
        ]);
    }
	
	/*
	 * Get the forms associated with this controller
	 * @param string $param What are we getting this form for?
	 * @param int $unique The id to load data for
	 * @return string | json
	 */
	public function actionForm($type=null, $id=null)
	{
		//Response::$viewOptions['args']['content'] = $ret_val['data'];
		$force = false;
		$options = [
			'param' => $type,
			'title' => ['title', 'Create Issue'],
			'scenario' => 'create',
			'provider' => null,
			'dataProvider' => null,
			'view' => 'form/_form',
			'viewArgs' => [
				'parentId' => $id,
				'parentType' => $type
			],
			'args' => [],
			'modelClass' => Issues::className(),
			'force' => true
		];
		
		switch($type)
		{	
			//This is for generating the form for updating and creating a request
			case 'update':
			$options['id'] = $id;
			$options['title'] = ['title', 'Update Issue'];
			break;
		}
		
		$modalOptions = [
			'contentOnly' => true,
			'body' => [
				'class' => ''
			],
			'content' => [
				'class' => 'modal-content'
			],
			'dialog' => [
				'class' => 'modal-dialog'
			],
		];
		$format = Response::formatSpecified() ? $this->getResponseFormat() : 'html';
		$this->setResponseFormat($format);
		return $this->renderResponse($this->getFormVariables($this->model, $options, $modalOptions), Response::$viewOptions, \Yii::$app->request->isAjax);
	}

    /**
     * Creates a new Issues model.
	 * @param string $type The parent type of the issue
	 * @param int $id The id of the parent
     * If creation is successful, the browser will be redirected to the 'view' page.
     * @return mixed
     */
    public function actionCreate()
    {
		$post = \Yii::$app->request->post();
		$this->model->setScenario('create');
		$this->model->load($post);
		switch(\Yii::$app->request->isAjax && (@\nitm\helpers\Helper::boolval($_REQUEST['do']) !== true))
		{
			case true:
			$this->setResponseFormat('json');
            return \yii\widgets\ActiveForm::validate($this->model);
			break;
		}
		return $this->finalAction();
    }

    /**
     * Updates an existing Issues model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param integer $id
	 * @param string $scenario
     * @return mixed
     */
    public function actionUpdate($id, $scenario='update')
    {
		$post = \Yii::$app->request->post();
        $this->model = $this->findModel(Issues::className(), $id);
		$this->model->setScenario($scenario);
		$this->model->load($post);
		switch(\Yii::$app->request->isAjax && (@\nitm\helpers\Helper::boolval($_REQUEST['do']) !== true))
		{
			case true:
			$this->setResponseFormat('json');
            return \yii\widgets\ActiveForm::validate($this->model);
			break;
		}
		return $this->finalAction();
    }
	
	public function actionClose($id)
	{
		\Yii::$app->request->setQueryParams([]);
		return $this->booleanAction($this->action->id, $id);
	}
	
	public function actionDuplicate($id)
	{
		\Yii::$app->request->setQueryParams([]);
		return $this->booleanAction($this->action->id, $id);
	}
	
	public function actionResolve($id)
	{
		\Yii::$app->request->setQueryParams([]);
		return $this->booleanAction($this->action->id, $id);
	}
	
	public function booleanAction($action, $id)
	{
		\Yii::$app->request->setQueryParams([]);
        $this->model = $this->findModel(Issues::className(), $id);
		switch($action)
		{
			case 'close':
			$attribute = 'closed';
			$scenario = 'close';
			break;
			
			case 'resolve':
			$attribute = 'resolved';
			$scenario = 'resolve';
			break;
			
			case 'duplicate':
			$attribute = 'duplicate';
			$scenario = 'duplicate';
			$this->model->load(\Yii::$app->request->post());
			switch(is_array($this->model->duplicate_id))
			{
				case true:
				$this->model->duplicate_id = implode(',', $this->model->duplicate_id);
				break;
			}
			break;
		}
		$this->model->setScenario($scenario);
		$this->result = !$this->model->getAttribute($attribute) ? 1 : 0;
		$this->model->setAttribute($attribute, $this->result);
		$this->setResponseFormat('json');
		return $this->finalAction();
	}

    /**
     * Deletes an existing Issues model.
     * If deletion is successful, the browser will be redirected to the 'index' page.
     * @param integer $id
     * @return mixed
     */
    public function actionDelete($id)
    {
        $this->findModel($id)->delete();

        return $this->redirect(['index']);
    }
	
	/**
	 * Put here primarily to handle action after create/update
	 */
	protected function finalAction($args=[])
	{
		$ret_val = is_array($args) ? $args : [
			'success' => false,
		];
        if ($this->model->save()) {
			switch(\Yii::$app->request->isAjax)
			{
				case true:
				switch($this->action->id)
				{
					case 'close':
					case 'duplicate':
					case 'resolve':
					$ret_val['success'] = true;
					switch($this->action->id)
					{
						case 'resolve':
						$attribute = 'resolved';
						$ret_val['title'] = ($this->model->$attribute == 0) ? 'Resolve' : 'Un-Resolve';
						break;
						
						case 'close':
						$attribute = 'closed';
						$ret_val['title'] = ($this->model->$attribute == 0) ? 'Close' : 'Open';
						break;
						
						case 'duplicate':
						$attribute = 'duplicate';
						$ret_val['title'] = ($this->model->$attribute == 0) ? 'Set to duplicate' : 'Set to not duplicate';
						break;
					}
					$ret_val['actionHtml'] = Icon::forAction($this->action->id, $attribute, $this->model);
					$ret_val['data'] = $this->result;
					$ret_val['class'] = 'wrapper '.\nitm\helpers\Statuses::getIndicator($this->model->getStatus());
					break;
					
					default:
					$format = Response::formatSpecified() ? $this->getResponseFormat() : 'json';
					$this->setResponseFormat($format);
					switch($this->getResponseFormat())
					{
						case 'json':
						$ret_val = [
							'data' => $this->renderAjax('view', ["model" => $this->model]),
							'success' => true
						];
						break;
						
						default:
						Response::$viewOptions['content'] = $this->renderAjax('view', ["model" => $this->model]);
						break;
					}
					break;
				}
				break;
					
				default:
				return $this->redirect(['index']);
				break;
			}
        }
		$ret_val['action'] = $this->action->id;
		$ret_val['id'] = $this->model->id;
		return $this->renderResponse($ret_val, Response::$viewOptions, \Yii::$app->request->isAjax);
	}
}

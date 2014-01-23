<?php

class ExportAction extends CAction {
	/**
	 * @var array CGridView columns definition
	 */
	public $columns;
	public $columns_from_query = true;
	public $criteria;

	/**
	 * @var mixed string with widget class name or array with widget options, defaults to CsvView
	 */
	public $widget;

	public function run() {
		if ($this->controller->checkAccessInActions && !Yii::app()->user->checkAccess('read '.$this->controller->authModelClass)) {
			throw new CHttpException(403,Yii::t('yii','You are not authorized to perform this action.'));
		}

		// set some defaults
		$widgetDefaults = array(
			'class'=>'ext.exporter.CsvView',
			'dataColumnClass' => 'ext.EDataTables.EDataColumn',
			'columns'=>$this->columns,
		);
		if ($this->widget === null)
			$this->widget = array();
		elseif (is_string($this->widget))
			$this->widget = array('class'=>$this->widget);
		$this->widget = array_merge($widgetDefaults, $this->widget);

		// as this could be expensive, create a dataProvider only if one wasn't provided
		if (!isset($this->widget['dataProvider'])) {
			$c = $this->controller;
			$model = NetActiveRecord::model($c->modelClass);
			if ($this->criteria === null)
				$this->criteria = new CDbCriteria;
			if (is_array($this->criteria))
				$this->criteria = new CDbCriteria($this->criteria);

			$filterId = $c->getId().'-filter';
			$filterForm = $c->loadFilterForm($c->getId(), $c->modelClass.'Filter', $filterId, '_filterForm');
			//! @todo how to configure from which action a default query should be used? check out second argument below:
			$columns = $this->widget['columns'];
			list($query, $criteria) = $c->loadQuery($c->getId(), 'index', $c->modelClass, $columns, $filterForm);
			if ($this->columns_from_query) $this->widget['columns'] = $columns;
			$this->criteria->mergeWith($criteria);

			if (isset($_GET['mode']) && isset($_GET['selected'])) {
				$pk = $model->primaryKey()!==null ? $model->primaryKey() : $model->tableSchema->primaryKey;
				$pks = array_map('intval',explode(',',$_GET['selected']));
				if ($_GET['mode']==='' || $_GET['mode']==='deselect') {
					$this->criteria->addInCondition('"t".'.$pk, $pks);
				} else {
					$this->criteria->addNotInCondition('"t".'.$pk, $pks);
				}
			}
            
			$this->widget['dataProvider'] = $model->search($_GET, $this->criteria, $this->widget['columns'], null, false);
            
            $filterModel = $filterForm->getModel();
            $filterModel->format(null, true);
            $this->widget['filter'] = $filterModel;
		}

		// set timer to 5 minutes
		set_time_limit(3600);

		$widget = $this->widget['class'];
		unset($this->widget['class']);
		$this->controller->widget($widget, $this->widget);
	}
}

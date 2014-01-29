<?php
/**
 * ExporterView class file.
 *
 * @author Jan Was <janek.jan@gmail.com>
 * @copyright Copyright &copy; 2013-2013 Jan Was
 */

Yii::import('zii.widgets.grid.CGridView');

/**
 * ExporterView is a base class for grid views rendering data using streaming, row by row by using CDbDataReader on a CDbCommand.
 * This allows to output a large dataset. Because it inherits from the CGridView widget, same column configuration is allowed.
 *
 * Tips:
 * - to include a line number or id column, add it to the column definition
 *
 * @author Jan Was <jwas@nets.com.pl>
 */
abstract class ExporterView extends CGridView
{
	/**
	 * @var string default class for data columns
	 */
	public $dataColumnClass = 'CDataColumn';
	/**
	 * @var boolean should invisible columns be included anyway, useful to export all possible data without creating extra column configuration
	 */
	public $includeInvisible = true;
	/**
	 * @var boolean if true, all buffers are flushed and disabled before any output
	 */
	public $disableBuffering = true;
	/**
	 * @var boolean if true, no http headers will be sent, useful to capture output for further processing
	 */
	public $disableHttpHeaders = false;
	/**
	 * @var string filename sent in http headers. Defaults to null (means it won't set 'Content-Disposition: attachment' HTTP header)
	 */
	public $filename;
    /**
     * @var string fileExt file extension., if value is empty|null then class assumes $filename contains it, otherwise export date will be placed between filename and file extension
     */
    public $fileExt;
    /**
	 * @var string mimetype sent in http headers
	 */
	public $mimetype = 'text/plain';
	/**
	 * @var output encoding, if null defaults to UTF-8
	 */
	public $encoding;
	/**
	 * @var boolean should html tags be stripped from output values, disable for really big exports to improve efficiency
	 */
	public $stripTags = true;
    /**
     * @var boolean should html entities be decoded from output values
     */
    public $decodeHtmlEntities = true;
	/**
	 * @var CActiveRecord model used to fill with current row and pass to row renderer
	 */
	protected $_model;

	/**
	 * Renders the view.
	 * This is the main entry of the whole view rendering.
	 * Child classes should mainly override {@link renderContent} method.
	 */
	public function run()
	{
		$this->renderContent();
	}

	/**
	 * Renders the main content of the view.
	 * The content is divided into sections, such as summary, items, pager.
	 * Each section is rendered by a method named as "renderXyz", where "Xyz" is the section name.
	 * The rendering results will replace the corresponding placeholders in {@link template}.
	 */
	public function renderContent()
	{
		if ($this->disableBuffering)
			while (ob_get_level()) ob_end_clean();
		if (!$this->disableHttpHeaders) {
            if($this->mimetype) {
                header('Content-Type: '.$this->mimetype);
            }

            if($this->filename !== null && strlen($this->filename) > 0) {
                $filename = $this->filename;

                if($this->fileExt !== null && strlen($this->fileExt) > 0) {
                    $filename .= date('_U_Ymd.') . $this->fileExt;
                }

                header('Content-Disposition: attachment; filename="'.$filename.'"');
            }

			header('Pragma: no-cache');
			header('Expires: 0');
		}

		$this->renderItems();
		if (!$this->disableHttpHeaders) {
			Yii::app()->end();
		}
	}

	public function init()
	{
		parent::init();

		$this->initColumns();
	}

	/**
	 * Creates column objects and initializes them. This method is made public to allow calling without full init from CConsoleCommand.
	 */
	public function initColumns()
	{
		if($this->columns===array())
		{
			if($this->dataProvider instanceof CActiveDataProvider)
			{
				$this->columns=$this->dataProvider->model->attributeNames();
			}
			elseif($this->dataProvider instanceof IDataProvider)
			{
				// use the keys of the first row of data as the default columns
				$data=$this->dataProvider->getData();
				if(isset($data[0]) && is_array($data[0]))
					$this->columns=array_keys($data[0]);
			}
		}
		$id=$this->getId();
		foreach($this->columns as $i=>$column)
		{
			if(is_string($column))
				$column=$this->createDataColumn($column);
			elseif(is_array($column))
			{
				if(!isset($column['class'])) {
					// note: EDataColumn instead of CDataColumn
					$column['class']=$this->dataColumnClass;
				}
				$column=Yii::createComponent($column, $this);
			}
            // note: includeInvisible option
			if(!$this->includeInvisible && !$column->visible)
			{
				unset($this->columns[$i]);
				continue;
			} else {
				$column->visible = true;
			}
			if($column->id===null)
				$column->id=$id.'_c'.$i;
			$this->columns[$i]=$column;
		}

		foreach($this->columns as $column)
			$column->init();
	}


	/**
	 * Creates a {@link CDataColumn} based on a shortcut column specification string.
	 * @param string $text the column specification string
	 * @return CDataColumn the column instance
	 */
	protected function createDataColumn($text)
	{
        $matches = array();
		if(!preg_match('/^([\w\.]+)(:(\w*))?(:(.*))?$/',$text,$matches))
			throw new CException(Yii::t('zii','The column must be specified in the format of "Name:Type:Label", where "Type" and "Label" are optional.'));
		$column=array(
			'class'=>$this->dataColumnClass,
			'name'=>$matches[1],
		);
		if(isset($matches[3]) && $matches[3]!=='')
			$column['type']=$matches[3];
		if(isset($matches[5]))
			$column['header']=$matches[5];
		return Yii::createComponent($column, $this);
	}

    /**
     * This is based on CActiveDataProvider::fetchData().
     * @todo afterFind may not be called properly in some cases
     * @todo check effects of enabled offset/limit (baseLimited in CJoinElement::find in CActiveFinder.php)
     * @return array containing CDbDataReader and CActiveFinder objects
     */
	public function getDataReader() {
		$model = $this->dataProvider->model;
		$criteria = $this->dataProvider->getCriteria();

		if(($pagination=$this->dataProvider->getPagination())!==false)
		{
			$pagination->setItemCount($this->dataProvider->getTotalItemCount());
			$pagination->applyLimit($criteria);
		}

		$baseCriteria = $model->getDbCriteria(false);

		if(($sort=$this->dataProvider->getSort())!==false)
		{
			// set model criteria so that CSort can use its table alias setting
			if($baseCriteria!==null)
			{
				$c=clone $baseCriteria;
				$c->mergeWith($criteria);
				$model->setDbCriteria($c);
			}
			else
				$model->setDbCriteria($criteria);
			$sort->applyOrder($criteria);
		}

		$model->setDbCriteria($baseCriteria!==null ? clone $baseCriteria : null);

        list($dataReader, $finder) = $this->createDataReader($model, $criteria);

		$model->setDbCriteria($baseCriteria);

		return array($dataReader, $finder);
	}

    /**
     * This is based on CActiveRecord::query().
     * @param CActiveRecord $model
     * @param CDbCriteria $criteria
     * @return array containing CDbDataReader and CActiveFinder objects
     */
    private function createDataReader($model, $criteria)
    {
		$model->beforeFindInternal();
		$model->applyScopes($criteria);

		if (empty($criteria->with)) {
            $finder = null;
			$command = $model->getCommandBuilder()->createFindCommand($model->tableSchema, $criteria, $model->getTableAlias());
		} else {
			$finder = new EActiveFinder($model, $criteria->with);
			$command = $finder->createCommand($criteria);
		}
		$command->prepare();
		$command->execute($command->params);
        return array(new CDbDataReader($command), $finder);
    }

	/**
	 * @param integer $row the row number (zero-based).
	 * @param array $data result of CDbDataReader.read()
	 * @param CActiveFinder $finder a finder object returned by getDataReader() method
	 * @return array processed values ready for output
	 */
	public function renderRow($row, $data, $finder=null)
	{
		$values = array();

		$this->_model = $finder === null ? $this->dataProvider->model->populateRecord($data) : $finder->populateRecord($data);
        $this->dataProvider->setData(array($row => $this->_model));
        
		foreach($this->columns as $column) {
			$value = $column->getDataCellContent($row);
			if ($this->stripTags)
				$value = strip_tags($value);
            if($this->decodeHtmlEntities)
                $value = html_entity_decode($value);
			if ($this->encoding !== null)
				$value = iconv('UTF-8', $this->encoding, $value);
			$values[] = $value;
		}
		return $values;
	}

	/**
	 * Renders the data items for the grid view.
	 */
	public function renderItems()
	{
		$this->renderHeader();
		$this->renderBody();
		$this->renderFooter();
	}

    public function renderHeaderCellContent($column)
    {
		if((isset($column->name) && $column->name!==null) && (!isset($column->header) || $column->header===null))
		{
			if($this->dataProvider instanceof CActiveDataProvider)
				return $this->dataProvider->model->getAttributeLabel($column->name);
			else
				return $column->name;
		}
		else
			return trim($column->header)!=='' ? $column->header : $this->blankDisplay;
	}

    protected function getHeader()
    {
		$headers = array();
		foreach($this->columns as $column) {
            $header = $this->renderHeaderCellContent($column);
			if ($this->encoding !== null) {
				$header = iconv('UTF-8', $this->encoding, $header);
			}
            $headers[] = $header;
		}
        return $headers;
    }

	public function renderHeader()
	{
	}

	public function renderBody()
	{
	}

	public function renderFooter()
	{
	}
}

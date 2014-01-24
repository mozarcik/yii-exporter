<?php
/**
 * JsonView class file.
 *
 * @author Jan Was <janek.jan@gmail.com>
 * @copyright Copyright &copy; 2013-2013 Jan Was
 */

Yii::import('ext.exporter.ExporterView');

/**
 * JsonView allows output a large dataset in a JSON format by using CDbDataReader on a CDbCommand.
 * Because it inherits from the CGridView widget, same column configuration is allowed.
 *
 * @author Jan Was <jwas@nets.com.pl>
 */
class JsonView extends ExporterView
{
	/**
	 * @var string mimetype sent in http headers
	 */
	public $mimetype = 'application/json';
	/**
	 * @var boolean should html tags be stripped from output values, disable for really big exports to improve efficiency
	 */
	public $stripTags = false;

	public function renderHeader()
	{
		echo "[\n ".json_encode($this->getHeader());
	}

	public function renderBody()
	{
		$dataReader = $this->getDataReader();

		$row = 0;
		while ($data = $dataReader->read()) {
			echo ",\n ".json_encode($this->renderRow($row++, $data));
		}
	}

	public function renderFooter()
	{
		echo "\n]";
	}
}

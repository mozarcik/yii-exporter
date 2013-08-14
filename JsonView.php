<?php
/**
 * JsonView class file.
 *
 * @author Jan Was <janek.jan@gmail.com>
 * @copyright Copyright &copy; 2013-2013 Jan Was
 */

/**
 * JsonView allows output a large dataset in a JSON format by using CDbDataReader on a CDbCommand.
 * Because it inherits from the CGridView widget, same column configuration is allowed.
 *
 * The default classes for columns are taken from EDataTables extension to support fetching formatted output not wrapped in <td> tags.
 *
 * @author Jan Was <jwas@nets.com.pl>
 */
class JsonView extends CsvView
{
	public $mimetype = 'application/json';
	public $stripTags = false;
	public $replaceNewlines = null;

	public function renderHeader()
	{
		$headers = array();
		foreach($this->columns as $column) {
			ob_start();
			$column->renderHeaderCellContent();
			$headers[] = ob_get_clean();
		}
		echo "[\n".json_encode($headers);
	}

	public function renderBody()
	{
		$dataReader = $this->getDataReader();

		$row = 0;
		while ($data = $dataReader->read()) {
			echo ",\n".json_encode($this->renderRow($row++, $data));
		}
	}

	public function renderFooter()
	{
		echo "]\n";
	}
}

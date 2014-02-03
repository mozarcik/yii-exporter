<?php
/**
 * CsvView class file.
 *
 * @author Jan Was <janek.jan@gmail.com>
 * @copyright Copyright &copy; 2013-2013 Jan Was
 */

/**
 * CsvView allows output a large dataset in a CSV format by using CDbDataReader on a CDbCommand.
 * Because it inherits from the CGridView widget, same column configuration is allowed.
 *
 * Tips:
 * - to include a line number or id column, add it to the column definition
 *
 * @author Jan Was <jwas@nets.com.pl>
 */
class CsvView extends ExporterView
{
	/**
	 * @var string mimetype sent in http headers
	 */
	public $mimetype = 'text/csv';
	/**
	 * @var string if not null, newline characters will be replaced with this, useful when output file will be processed by simple CSV parsers; try not to use same characters as in the $delimiter property
	 */
	public $replaceNewlines = ', ';
	/**
	 * @var string field delimiter (one character only)
	 */
	public $delimiter = ';';
	/**
	 * @var string field enclosure (one character only)
	 */
	public $enclosure = '"';

	/**
	 * @var resource stdout or php://output, requried by fputcsv function
	 */
	protected $_fp;

	/**
	 * Renders the view.
	 * This is the main entry of the whole view rendering.
	 * Child classes should mainly override {@link renderContent} method.
	 */
	public function run()
	{
		$this->_fp = fopen('php://output', 'w');
		if (!$this->_fp) {
			return;
		}
		$this->renderContent();
		fclose($this->_fp);
	}

	public function renderHeader()
	{
		fputcsv($this->_fp, $this->getHeader(), $this->delimiter, $this->enclosure);
	}

	public function renderBody()
	{
        $isActiveDataProvider = $this->dataProvider instanceof CActiveDataProvider;
        if (!$isActiveDataProvider || ($this->dataProvider->pagination !== false && $this->dataProvider->pagination->limit < 1000)) {
            $dataReader = null;
            $finder = null;
        } else {
            //! @todo there could be a dataReader for CSqlDataProvider and some sort of iteratable container for CArrayDataProvider to use next()
            list($dataReader, $finder) = $this->getDataReader();
        }

		$row = 0;
        if ($dataReader !== null) {
            while ($data = $dataReader->read()) {
                $data = $this->prepareRow($row, $data, $finder, $isActiveDataProvider);
                fputcsv($this->_fp, $this->renderRow($row++, $data, $isActiveDataProvider), $this->delimiter, $this->enclosure);
            }
        } else {
            foreach ($this->dataProvider->data as $data) {
                fputcsv($this->_fp, $this->renderRow($row++, $data, $isActiveDataProvider), $this->delimiter, $this->enclosure);
            }
        }
        if ($finder!==null)
            $finder->destroyJoinTree();
	}

	/**
	 * @param integer $row the row number (zero-based).
	 * @param array $data result of CDbDataReader.read()
	 * @param CActiveFinder $finder a finder object returned by getDataReader() method
	 * @return array processed values ready for output
	 */
	public function renderRow($row, $data, $finder=null)
	{
        $values = parent::renderRow($row, $data, $finder);
        foreach($values as $key=>$value) {
			if ($this->replaceNewlines!==null)
				$values[$key] = str_replace("\n", $this->replaceNewlines, $value);
        }
		return $values;
	}
}

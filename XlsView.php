<?php

class XlsView extends ExporterView
{
    /**
     * @var string worksheet name, self-explanatory
     */
    public $worksheetName = null;
	/**
	 * @var string mimetype sent in http headers
	 */
	public $mimetype = 'application/excel';

    protected $_typeMap;

	public function init()
	{
		parent::init();
        
        if ($this->worksheetName === null) {
            $this->worksheetName = basename($this->filename, '.xls');
        }
        if (!(($formatter=$this->getFormatter()) instanceof ExcelFormatter)) {
            $formatter = new ExcelFormatter;
            $this->setFormatter($formatter);
        }
        $this->_typeMap = $formatter->typeMap;
	}

	/**
	 * @param integer $row the row number (zero-based).
	 * @param array $data result of CDbDataReader.read()
	 * @return array processed values ready for output
	 */
	public function renderRow($row, $data)
	{
		$values = array();

		$this->_model = $this->dataProvider->model->populateRecord($data);
        $this->dataProvider->setData(array($row => $this->_model));
		foreach($this->columns as $column) {
            
            if (isset($column->type) && !is_array($column->type) && isset($this->_typeMap[$column->type])) {
                $type = $this->_typeMap[$column->type]['type'];
                $style = $column->type.'Format';
                /*if ($column instanceof CDataColumn) {
                    if ($column->value !== null) {
                        $value = $column->evaluateExpression($column->value, array('data'=>$this->_model, 'row'=>$row));
                    } elseif ($column->name !== null) {
                        $value = CHtml::value($this->_model, $column->name);
                    }
                    $value = $this->formatExcel($value, $column->type);
                } else {
                    $value = $column->getDataCellContent($row, $this->_model);
                }*/
            } else {
                $type = 'String';
                $style = null;
            }
            $value = $column->getDataCellContent($row, $this->_model);

			if ($this->stripTags)
				$value = strip_tags($value);
			if ($this->encoding !== null)
				$value = iconv('UTF-8', $this->encoding, $value);
			$values[] = '<Cell'.($style!==null ? ' ss:StyleID="'.$style.'"' : '').'><Data ss:Type="'.$type.'">' . $this->encodeText($value) . '</Data></Cell>';
		}
		return $values;
	}

    public function renderPreambleStyles()
    {
        $styles = <<<XML
        <Style ss:ID="Heading" ss:Name="Heading">
            <Font ss:Size="11" ss:Bold="1" ss:Color="#eeeeee"/>
            <Interior ss:Pattern="Solid" ss:Color="#222222"/>
        </Style>
        <Style ss:ID="Filters">
            <Font ss:Italic="1" ss:Color="#aa0000"/>
            <Interior ss:Pattern="Solid" ss:Color="#eeeeee"/>
        </Style>

XML;
        foreach($this->_typeMap as $type=>$typeData) {
            if ($typeData['format'] === null) continue;
            $styles .= <<<XML
        <Style ss:ID="{$type}Format">
            <NumberFormat ss:Format="{$typeData['format']}"/>
        </Style>

XML;
        }
        return $styles;
    }

    public function renderPreamble()
    {
        $name = $this->encodeText($this->worksheetName);
        $styles = $this->renderPreambleStyles();
        echo <<<XML
<?xml version="1.0"?>
<Workbook
    xmlns="urn:schemas-microsoft-com:office:spreadsheet"
    xmlns:o="urn:schemas-microsoft-com:office:office"
    xmlns:x="urn:schemas-microsoft-com:office:excel"
    xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
    xmlns:c="urn:schemas-microsoft-com:office:component:spreadsheet">
    <Styles>
$styles
    </Styles>
    <Worksheet ss:Name="{$name}">
        <Table>

XML;
    }

    public function renderColumnHeaders()
    {
		echo '<Row ss:StyleID="Heading" ss:Height="1.3">';
		foreach($this->getHeader() as $header) {
            echo '<Cell><Data ss:Type="String">' . $this->encodeText($header) . '</Data></Cell>';
		}
        echo '</Row>';
    }

	public function renderHeader()
	{
        $this->renderPreamble();
        //$this->renderFilter();
        $this->renderColumnHeaders();
	}

	public function renderBody()
	{
		$dataReader = $this->getDataReader();
		$row = 0;

		while ($data = $dataReader->read()) {
            echo '<Row>'.implode('', $this->renderRow($row++, $data)).'</Row>'."\n";
		}
	}

	public function renderFooter()
	{
        echo <<<XML
        </Table>
        <c:WorksheetOptions>
            <c:DisplayCustomHeaders/>
        </c:WorksheetOptions>
    </Worksheet>
</Workbook>
XML;
	}
    
    protected function encodeText($text) {
        // this may not have any sense, encode what we just decoded
        // but input may contain HTML and some of the entities are (not) valid in XML
        // in order to keep formatting as it was, this is the trick to do it
        //FIXME maybe a better way? there is a better way, probably...
        return htmlentities(html_entity_decode($text), ENT_XML1 | ENT_NOQUOTES);
    }
}

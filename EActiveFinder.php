<?php

class EActiveFinder extends CActiveFinder
{
	private $_joinCount = 0;
	private $_joinTree;
	private $_builder;

    // reused in populateRecord()
    private $_query;

    public function __construct($model,$with)
    {
		$this->_builder=$model->getCommandBuilder();
		$this->_joinTree=new EJoinElement($this,$model);
		$this->buildJoinTree($this->_joinTree,$with);
	}

    public function createCommand($criteria)
    {
		$this->joinAll=$criteria->together===true;

		if($criteria->alias!='')
		{
			$this->_joinTree->tableAlias=$criteria->alias;
			$this->_joinTree->rawTableAlias=$this->_builder->getSchema()->quoteTableName($criteria->alias);
		}
		$this->_query = new CJoinQuery($this->_joinTree, $criteria);
		$this->_joinTree->buildQuery($this->_query);
		return $this->_query->createCommand($this->_builder);
	}

    public function populateRecord($data)
    {
        $record = $this->_joinTree->populateRecord($this->_query,$data);
        $this->_joinTree->afterFindIntermediate();
        $this->_joinTree->clear();
        return $record;
    }

	//copied parent class method from Yii source code
    private function buildJoinTree($parent,$with,$options=null)
    {
		if($parent instanceof CStatElement)
			throw new CDbException(Yii::t('yii','The STAT relation "{name}" cannot have child relations.',
				array('{name}'=>$parent->relation->name)));

		if(is_string($with))
		{
			if(($pos=strrpos($with,'.'))!==false)
			{
				$parent=$this->buildJoinTree($parent,substr($with,0,$pos));
				$with=substr($with,$pos+1);
			}

			// named scope
			$scopes=array();
			if(($pos=strpos($with,':'))!==false)
			{
				$scopes=explode(':',substr($with,$pos+1));
				$with=substr($with,0,$pos);
			}

			if(isset($parent->children[$with]) && $parent->children[$with]->master===null)
				return $parent->children[$with];

			if(($relation=$parent->model->getActiveRelation($with))===null)
				throw new CDbException(Yii::t('yii','Relation "{name}" is not defined in active record class "{class}".',
					array('{class}'=>get_class($parent->model), '{name}'=>$with)));

			$relation=clone $relation;
			$model=CActiveRecord::model($relation->className);

			if($relation instanceof CActiveRelation)
			{
				$oldAlias=$model->getTableAlias(false,false);
				if(isset($options['alias']))
					$model->setTableAlias($options['alias']);
				elseif($relation->alias===null)
					$model->setTableAlias($relation->name);
				else
					$model->setTableAlias($relation->alias);
			}

			if(!empty($relation->scopes))
				$scopes=array_merge($scopes,(array)$relation->scopes); // no need for complex merging

			if(!empty($options['scopes']))
				$scopes=array_merge($scopes,(array)$options['scopes']); // no need for complex merging

			$model->resetScope(false);
			$criteria=$model->getDbCriteria();
			$criteria->scopes=$scopes;
			$model->beforeFindInternal();
			$model->applyScopes($criteria);

			// select has a special meaning in stat relation, so we need to ignore select from scope or model criteria
			if($relation instanceof CStatRelation)
				$criteria->select='*';

			$relation->mergeWith($criteria,true);

			// dynamic options
			if($options!==null)
				$relation->mergeWith($options);

			if($relation instanceof CActiveRelation)
				$model->setTableAlias($oldAlias);

			if($relation instanceof CStatRelation)
				return new CStatElement($this,$relation,$parent);
			else
			{
				if(isset($parent->children[$with]))
				{
					$element=$parent->children[$with];
					$element->relation=$relation;
				}
				else
					$element=new EJoinElement($this,$relation,$parent,++$this->_joinCount);
				if(!empty($relation->through))
				{
					$slave=$this->buildJoinTree($parent,$relation->through,array('select'=>false));
					$slave->master=$element;
					$element->slave=$slave;
				}
				$parent->children[$with]=$element;
				if(!empty($relation->with))
					$this->buildJoinTree($element,$relation->with);
				return $element;
			}
		}

		// $with is an array, keys are relation name, values are relation spec
		foreach($with as $key=>$value)
		{
			if(is_string($value))  // the value is a relation name
				$this->buildJoinTree($parent,$value);
			elseif(is_string($key) && is_array($value))
				$this->buildJoinTree($parent,$key,$value);
		}
	}

	public function destroyJoinTree()
	{
		if($this->_joinTree!==null)
			$this->_joinTree->destroy();
		$this->_joinTree=null;
	}
}

/**
 * CJoinElement represents a tree node in the join tree created by {@link CActiveFinder}.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @package system.db.ar
 * @since 1.0
 *
 * Changes:
 * - populateRecord made public
 * - added clear() method to clear the records and _related properties
 * - added afterFindIntermediate() method that doesn't clear children property
 */
class EJoinElement extends CJoinElement
{
	private $_finder;
	private $_builder;
	private $_parent;
	private $_pkAlias;  				// string or name=>alias
	private $_columnAliases=array();	// name=>alias
	private $_joined=false;
	private $_table;
	private $_related=array();			// PK, relation name, related PK => true

	/**
	 * Constructor.
	 * @param CActiveFinder $finder the finder
	 * @param mixed $relation the relation (if the third parameter is not null)
	 * or the model (if the third parameter is null) associated with this tree node.
	 * @param CJoinElement $parent the parent tree node
	 * @param integer $id the ID of this tree node that is unique among all the tree nodes
	 */
	public function __construct($finder,$relation,$parent=null,$id=0)
	{
		$this->_finder=$finder;
		$this->id=$id;
		if($parent!==null)
		{
			$this->relation=$relation;
			$this->_parent=$parent;
			$this->model=$this->_finder->getModel($relation->className);
			$this->_builder=$this->model->getCommandBuilder();
			$this->tableAlias=$relation->alias===null?$relation->name:$relation->alias;
			$this->rawTableAlias=$this->_builder->getSchema()->quoteTableName($this->tableAlias);
			$this->_table=$this->model->getTableSchema();
		}
		else  // root element, the first parameter is the model.
		{
			$this->model=$relation;
			$this->_builder=$relation->getCommandBuilder();
			$this->_table=$relation->getTableSchema();
			$this->tableAlias=$this->model->getTableAlias();
			$this->rawTableAlias=$this->_builder->getSchema()->quoteTableName($this->tableAlias);
		}

		// set up column aliases, such as t1_c2
		$table=$this->_table;
		if($this->model->getDbConnection()->getDriverName()==='oci')  // Issue 482
			$prefix='T'.$id.'_C';
		else
			$prefix='t'.$id.'_c';
		foreach($table->getColumnNames() as $key=>$name)
		{
			$alias=$prefix.$key;
			$this->_columnAliases[$name]=$alias;
			if($table->primaryKey===$name)
				$this->_pkAlias=$alias;
			elseif(is_array($table->primaryKey) && in_array($name,$table->primaryKey))
				$this->_pkAlias[$name]=$alias;
		}
	}

	/**
	 * Removes references to child elements and finder to avoid circular references.
	 * This is internally used.
	 */
	public function destroy()
	{
		if(!empty($this->children))
		{
			foreach($this->children as $child)
				$child->destroy();
		}
		unset($this->_finder, $this->_parent, $this->model, $this->relation, $this->master, $this->slave, $this->records, $this->children, $this->stats);
	}

    /**
     * Custom method to clear properties filled in populateRecord().
     */
    public function clear()
    {
		if(!empty($this->children))
		{
			foreach($this->children as $child)
				$child->clear();
		}
        unset($this->records);
        unset($this->_related);
    }

	/**
	 * Performs the recursive finding with the criteria.
	 * @param CDbCriteria $criteria the query criteria
	 */
	public function find($criteria=null)
	{
		if($this->_parent===null) // root element
		{
			$query=new CJoinQuery($this,$criteria);
			$this->_finder->baseLimited=($criteria->offset>=0 || $criteria->limit>=0);
			$this->buildQuery($query);
			$this->_finder->baseLimited=false;
			$this->runQuery($query);
		}
		elseif(!$this->_joined && !empty($this->_parent->records)) // not joined before
		{
			$query=new CJoinQuery($this->_parent);
			$this->_joined=true;
			$query->join($this);
			$this->buildQuery($query);
			$this->_parent->runQuery($query);
		}

		foreach($this->children as $child) // find recursively
			$child->find();

		foreach($this->stats as $stat)
			$stat->query();
	}

	/**
	 * Performs lazy find with the specified base record.
	 * @param CActiveRecord $baseRecord the active record whose related object is to be fetched.
	 */
	public function lazyFind($baseRecord)
	{
		if(is_string($this->_table->primaryKey))
			$this->records[$baseRecord->{$this->_table->primaryKey}]=$baseRecord;
		else
		{
			$pk=array();
			foreach($this->_table->primaryKey as $name)
				$pk[$name]=$baseRecord->$name;
			$this->records[serialize($pk)]=$baseRecord;
		}

		foreach($this->stats as $stat)
			$stat->query();

		if(!$this->children)
			return;

		$params=array();
		foreach($this->children as $child)
			if(is_array($child->relation->params))
				$params=array_merge($params,$child->relation->params);

		$query=new CJoinQuery($child);
		$query->selects=array($child->getColumnSelect($child->relation->select));
		$query->conditions=array(
			$child->relation->condition,
			$child->relation->on,
		);
		$query->groups[]=$child->relation->group;
		$query->joins[]=$child->relation->join;
		$query->havings[]=$child->relation->having;
		$query->orders[]=$child->relation->order;
		$query->params=$params;
		$query->elements[$child->id]=true;
		if($child->relation instanceof CHasManyRelation)
		{
			$query->limit=$child->relation->limit;
			$query->offset=$child->relation->offset;
		}

		$child->applyLazyCondition($query,$baseRecord);

		$this->_joined=true;
		$child->_joined=true;

		$this->_finder->baseLimited=false;
		$child->buildQuery($query);
		$child->runQuery($query);
		foreach($child->children as $c)
			$c->find();

		if(empty($child->records))
			return;
		if($child->relation instanceof CHasOneRelation || $child->relation instanceof CBelongsToRelation)
			$baseRecord->addRelatedRecord($child->relation->name,reset($child->records),false);
		else // has_many and many_many
		{
			foreach($child->records as $record)
			{
				if($child->relation->index!==null)
					$index=$record->{$child->relation->index};
				else
					$index=true;
				$baseRecord->addRelatedRecord($child->relation->name,$record,$index);
			}
		}
	}

	/**
	 * Apply Lazy Condition
	 * @param CJoinQuery $query represents a JOIN SQL statements
	 * @param CActiveRecord $record the active record whose related object is to be fetched.
	 * @throws CDbException if relation in active record class is not specified correctly
	 */
	private function applyLazyCondition($query,$record)
	{
		$schema=$this->_builder->getSchema();
		$parent=$this->_parent;
		if($this->relation instanceof CManyManyRelation)
		{
			$joinTableName=$this->relation->getJunctionTableName();
			if(($joinTable=$schema->getTable($joinTableName))===null)
				throw new CDbException(Yii::t('yii','The relation "{relation}" in active record class "{class}" is not specified correctly: the join table "{joinTable}" given in the foreign key cannot be found in the database.',
					array('{class}'=>get_class($parent->model), '{relation}'=>$this->relation->name, '{joinTable}'=>$joinTableName)));
			$fks=$this->relation->getJunctionForeignKeys();

			$joinAlias=$schema->quoteTableName($this->relation->name.'_'.$this->tableAlias);
			$parentCondition=array();
			$childCondition=array();
			$count=0;
			$params=array();

			$fkDefined=true;
			foreach($fks as $i=>$fk)
			{
				if(isset($joinTable->foreignKeys[$fk]))  // FK defined
				{
					list($tableName,$pk)=$joinTable->foreignKeys[$fk];
					if(!isset($parentCondition[$pk]) && $schema->compareTableNames($parent->_table->rawName,$tableName))
					{
						$parentCondition[$pk]=$joinAlias.'.'.$schema->quoteColumnName($fk).'=:ypl'.$count;
						$params[':ypl'.$count]=$record->$pk;
						$count++;
					}
					elseif(!isset($childCondition[$pk]) && $schema->compareTableNames($this->_table->rawName,$tableName))
						$childCondition[$pk]=$this->getColumnPrefix().$schema->quoteColumnName($pk).'='.$joinAlias.'.'.$schema->quoteColumnName($fk);
					else
					{
						$fkDefined=false;
						break;
					}
				}
				else
				{
					$fkDefined=false;
					break;
				}
			}

			if(!$fkDefined)
			{
				$parentCondition=array();
				$childCondition=array();
				$count=0;
				$params=array();
				foreach($fks as $i=>$fk)
				{
					if($i<count($parent->_table->primaryKey))
					{
						$pk=is_array($parent->_table->primaryKey) ? $parent->_table->primaryKey[$i] : $parent->_table->primaryKey;
						$parentCondition[$pk]=$joinAlias.'.'.$schema->quoteColumnName($fk).'=:ypl'.$count;
						$params[':ypl'.$count]=$record->$pk;
						$count++;
					}
					else
					{
						$j=$i-count($parent->_table->primaryKey);
						$pk=is_array($this->_table->primaryKey) ? $this->_table->primaryKey[$j] : $this->_table->primaryKey;
						$childCondition[$pk]=$this->getColumnPrefix().$schema->quoteColumnName($pk).'='.$joinAlias.'.'.$schema->quoteColumnName($fk);
					}
				}
			}

			if($parentCondition!==array() && $childCondition!==array())
			{
				$join='INNER JOIN '.$joinTable->rawName.' '.$joinAlias.' ON ';
				$join.='('.implode(') AND (',$parentCondition).') AND ('.implode(') AND (',$childCondition).')';
				if(!empty($this->relation->on))
					$join.=' AND ('.$this->relation->on.')';
				$query->joins[]=$join;
				foreach($params as $name=>$value)
					$query->params[$name]=$value;
			}
			else
				throw new CDbException(Yii::t('yii','The relation "{relation}" in active record class "{class}" is specified with an incomplete foreign key. The foreign key must consist of columns referencing both joining tables.',
					array('{class}'=>get_class($parent->model), '{relation}'=>$this->relation->name)));
		}
		else
		{
			$element=$this;
			while(true)
			{
				$condition=$element->relation->condition;
				if(!empty($condition))
					$query->conditions[]=$condition;
				$query->params=array_merge($query->params,$element->relation->params);
				if($element->slave!==null)
				{
					$query->joins[]=$element->slave->joinOneMany($element->slave,$element->relation->foreignKey,$element,$parent);
					$element=$element->slave;
				}
				else
					break;
			}
			$fks=is_array($element->relation->foreignKey) ? $element->relation->foreignKey : preg_split('/\s*,\s*/',$element->relation->foreignKey,-1,PREG_SPLIT_NO_EMPTY);
			$prefix=$element->getColumnPrefix();
			$params=array();
			foreach($fks as $i=>$fk)
			{
				if(!is_int($i))
				{
					$pk=$fk;
					$fk=$i;
				}

				if($element->relation instanceof CBelongsToRelation)
				{
					if(is_int($i))
					{
						if(isset($parent->_table->foreignKeys[$fk]))  // FK defined
							$pk=$parent->_table->foreignKeys[$fk][1];
						elseif(is_array($element->_table->primaryKey)) // composite PK
							$pk=$element->_table->primaryKey[$i];
						else
							$pk=$element->_table->primaryKey;
					}
					$params[$pk]=$record->$fk;
				}
				else
				{
					if(is_int($i))
					{
						if(isset($element->_table->foreignKeys[$fk]))  // FK defined
							$pk=$element->_table->foreignKeys[$fk][1];
						elseif(is_array($parent->_table->primaryKey)) // composite PK
							$pk=$parent->_table->primaryKey[$i];
						else
							$pk=$parent->_table->primaryKey;
					}
					$params[$fk]=$record->$pk;
				}
			}
			$count=0;
			foreach($params as $name=>$value)
			{
				$query->conditions[]=$prefix.$schema->quoteColumnName($name).'=:ypl'.$count;
				$query->params[':ypl'.$count]=$value;
				$count++;
			}
		}
	}

	/**
	 * Performs the eager loading with the base records ready.
	 * @param mixed $baseRecords the available base record(s).
	 */
	public function findWithBase($baseRecords)
	{
		if(!is_array($baseRecords))
			$baseRecords=array($baseRecords);
		if(is_string($this->_table->primaryKey))
		{
			foreach($baseRecords as $baseRecord)
				$this->records[$baseRecord->{$this->_table->primaryKey}]=$baseRecord;
		}
		else
		{
			foreach($baseRecords as $baseRecord)
			{
				$pk=array();
				foreach($this->_table->primaryKey as $name)
					$pk[$name]=$baseRecord->$name;
				$this->records[serialize($pk)]=$baseRecord;
			}
		}

		$query=new CJoinQuery($this);
		$this->buildQuery($query);
		if(count($query->joins)>1)
			$this->runQuery($query);
		foreach($this->children as $child)
			$child->find();

		foreach($this->stats as $stat)
			$stat->query();
	}

	/**
	 * Count the number of primary records returned by the join statement.
	 * @param CDbCriteria $criteria the query criteria
	 * @return string number of primary records. Note: type is string to keep max. precision.
	 */
	public function count($criteria=null)
	{
		$query=new CJoinQuery($this,$criteria);
		// ensure only one big join statement is used
		$this->_finder->baseLimited=false;
		$this->_finder->joinAll=true;
		$this->buildQuery($query);

		$query->limit=$query->offset=-1;

		if(!empty($criteria->group) || !empty($criteria->having))
		{
			$query->orders = array();
			$command=$query->createCommand($this->_builder);
			$sql=$command->getText();
			$sql="SELECT COUNT(*) FROM ({$sql}) sq";
			$command->setText($sql);
			$command->params=$query->params;
			return $command->queryScalar();
		}
		else
		{
			$select=is_array($criteria->select) ? implode(',',$criteria->select) : $criteria->select;
			if($select!=='*' && !strncasecmp($select,'count',5))
				$query->selects=array($select);
			elseif(is_string($this->_table->primaryKey))
			{
				$prefix=$this->getColumnPrefix();
				$schema=$this->_builder->getSchema();
				$column=$prefix.$schema->quoteColumnName($this->_table->primaryKey);
				$query->selects=array("COUNT(DISTINCT $column)");
			}
			else
				$query->selects=array("COUNT(*)");

			$query->orders=$query->groups=$query->havings=array();
			$command=$query->createCommand($this->_builder);
			return $command->queryScalar();
		}
	}

	/**
     * Custom method that doesn't clear children property.
	 * Calls {@link CActiveRecord::afterFind} of all the records.
	 */
	public function afterFindIntermediate()
	{
		foreach($this->records as $record)
			$record->afterFindInternal();
		foreach($this->children as $child)
			$child->afterFindIntermediate();
	}

	/**
	 * Calls {@link CActiveRecord::afterFind} of all the records.
	 */
	public function afterFind()
	{
		foreach($this->records as $record)
			$record->afterFindInternal();
		foreach($this->children as $child)
			$child->afterFind();

		$this->children = null;
	}

	/**
	 * Builds the join query with all descendant HAS_ONE and BELONGS_TO nodes.
	 * @param CJoinQuery $query the query being built up
	 */
	public function buildQuery($query)
	{
		foreach($this->children as $child)
		{
			if($child->master!==null)
				$child->_joined=true;
			elseif($child->relation instanceof CHasOneRelation || $child->relation instanceof CBelongsToRelation
				|| $this->_finder->joinAll || $child->relation->together || (!$this->_finder->baseLimited && $child->relation->together===null))
			{
				$child->_joined=true;
				$query->join($child);
				$child->buildQuery($query);
			}
		}
	}

	/**
	 * Executes the join query and populates the query results.
	 * @param CJoinQuery $query the query to be executed.
	 */
	public function runQuery($query)
	{
		$command=$query->createCommand($this->_builder);
		foreach($command->queryAll() as $row)
			$this->populateRecord($query,$row);
	}

	/**
	 * Populates the active records with the query data.
	 * @param CJoinQuery $query the query executed
	 * @param array $row a row of data
	 * @return CActiveRecord the populated record
	 */
	public function populateRecord($query,$row)
	{
		// determine the primary key value
		if(is_string($this->_pkAlias))  // single key
		{
			if(isset($row[$this->_pkAlias]))
				$pk=$row[$this->_pkAlias];
			else	// no matching related objects
				return null;
		}
		else // is_array, composite key
		{
			$pk=array();
			foreach($this->_pkAlias as $name=>$alias)
			{
				if(isset($row[$alias]))
					$pk[$name]=$row[$alias];
				else	// no matching related objects
					return null;
			}
			$pk=serialize($pk);
		}

		// retrieve or populate the record according to the primary key value
		if(isset($this->records[$pk]))
			$record=$this->records[$pk];
		else
		{
			$attributes=array();
			$aliases=array_flip($this->_columnAliases);
			foreach($row as $alias=>$value)
			{
				if(isset($aliases[$alias]))
					$attributes[$aliases[$alias]]=$value;
			}
			$record=$this->model->populateRecord($attributes,false);
			foreach($this->children as $child)
			{
				if(!empty($child->relation->select))
					$record->addRelatedRecord($child->relation->name,null,$child->relation instanceof CHasManyRelation);
			}
			$this->records[$pk]=$record;
		}

		// populate child records recursively
		foreach($this->children as $child)
		{
			if(!isset($query->elements[$child->id]) || empty($child->relation->select))
				continue;
			$childRecord=$child->populateRecord($query,$row);
			if($child->relation instanceof CHasOneRelation || $child->relation instanceof CBelongsToRelation)
				$record->addRelatedRecord($child->relation->name,$childRecord,false);
			else // has_many and many_many
			{
				// need to double check to avoid adding duplicated related objects
				if($childRecord instanceof CActiveRecord)
					$fpk=serialize($childRecord->getPrimaryKey());
				else
					$fpk=0;
				if(!isset($this->_related[$pk][$child->relation->name][$fpk]))
				{
					if($childRecord instanceof CActiveRecord && $child->relation->index!==null)
						$index=$childRecord->{$child->relation->index};
					else
						$index=true;
					$record->addRelatedRecord($child->relation->name,$childRecord,$index);
					$this->_related[$pk][$child->relation->name][$fpk]=true;
				}
			}
		}

		return $record;
	}

	/**
	 * @return string the table name and the table alias (if any). This can be used directly in SQL query without escaping.
	 */
	public function getTableNameWithAlias()
	{
		if($this->tableAlias!==null)
			return $this->_table->rawName . ' ' . $this->rawTableAlias;
		else
			return $this->_table->rawName;
	}

	/**
	 * Generates the list of columns to be selected.
	 * Columns will be properly aliased and primary keys will be added to selection if they are not specified.
	 * @param mixed $select columns to be selected. Defaults to '*', indicating all columns.
	 * @throws CDbException if active record class is trying to select an invalid column
	 * @return string the column selection
	 */
	public function getColumnSelect($select='*')
	{
		$schema=$this->_builder->getSchema();
		$prefix=$this->getColumnPrefix();
		$columns=array();
		if($select==='*')
		{
			foreach($this->_table->getColumnNames() as $name)
				$columns[]=$prefix.$schema->quoteColumnName($name).' AS '.$schema->quoteColumnName($this->_columnAliases[$name]);
		}
		else
		{
			if(is_string($select))
				$select=explode(',',$select);
			$selected=array();
			foreach($select as $name)
			{
				$name=trim($name);
				$matches=array();
				if(($pos=strrpos($name,'.'))!==false)
					$key=substr($name,$pos+1);
				else
					$key=$name;
				$key=trim($key,'\'"`');

				if($key==='*')
				{
					foreach($this->_table->columns as $name=>$column)
					{
						$alias=$this->_columnAliases[$name];
						if(!isset($selected[$alias]))
						{
							$columns[]=$prefix.$column->rawName.' AS '.$schema->quoteColumnName($alias);
							$selected[$alias]=1;
						}
					}
					continue;
				}

				if(isset($this->_columnAliases[$key]))  // simple column names
				{
					$columns[]=$prefix.$schema->quoteColumnName($key).' AS '.$schema->quoteColumnName($this->_columnAliases[$key]);
					$selected[$this->_columnAliases[$key]]=1;
				}
				elseif(preg_match('/^(.*?)\s+AS\s+(\w+)$/im',$name,$matches)) // if the column is already aliased
				{
					$alias=$matches[2];
					if(!isset($this->_columnAliases[$alias]) || $this->_columnAliases[$alias]!==$alias)
					{
						$this->_columnAliases[$alias]=$alias;
						$columns[]=$name;
						$selected[$alias]=1;
					}
				}
				else
					throw new CDbException(Yii::t('yii','Active record "{class}" is trying to select an invalid column "{column}". Note, the column must exist in the table or be an expression with alias.',
						array('{class}'=>get_class($this->model), '{column}'=>$name)));
			}
			// add primary key selection if they are not selected
			if(is_string($this->_pkAlias) && !isset($selected[$this->_pkAlias]))
				$columns[]=$prefix.$schema->quoteColumnName($this->_table->primaryKey).' AS '.$schema->quoteColumnName($this->_pkAlias);
			elseif(is_array($this->_pkAlias))
			{
				foreach($this->_table->primaryKey as $name)
					if(!isset($selected[$name]))
						$columns[]=$prefix.$schema->quoteColumnName($name).' AS '.$schema->quoteColumnName($this->_pkAlias[$name]);
			}
		}

		return implode(', ',$columns);
	}

	/**
	 * @return string the primary key selection
	 */
	public function getPrimaryKeySelect()
	{
		$schema=$this->_builder->getSchema();
		$prefix=$this->getColumnPrefix();
		$columns=array();
		if(is_string($this->_pkAlias))
			$columns[]=$prefix.$schema->quoteColumnName($this->_table->primaryKey).' AS '.$schema->quoteColumnName($this->_pkAlias);
		elseif(is_array($this->_pkAlias))
		{
			foreach($this->_pkAlias as $name=>$alias)
				$columns[]=$prefix.$schema->quoteColumnName($name).' AS '.$schema->quoteColumnName($alias);
		}
		return implode(', ',$columns);
	}

	/**
	 * @return string the condition that specifies only the rows with the selected primary key values.
	 */
	public function getPrimaryKeyRange()
	{
		if(empty($this->records))
			return '';
		$values=array_keys($this->records);
		if(is_array($this->_table->primaryKey))
		{
			foreach($values as &$value)
				$value=unserialize($value);
		}
		return $this->_builder->createInCondition($this->_table,$this->_table->primaryKey,$values,$this->getColumnPrefix());
	}

	/**
	 * @return string the column prefix for column reference disambiguation
	 */
	public function getColumnPrefix()
	{
		if($this->tableAlias!==null)
			return $this->rawTableAlias.'.';
		else
			return $this->_table->rawName.'.';
	}

	/**
	 * @throws CDbException if relation in active record class is not specified correctly
	 * @return string the join statement (this node joins with its parent)
	 */
	public function getJoinCondition()
	{
		$parent=$this->_parent;
		if($this->relation instanceof CManyManyRelation)
		{
			$schema=$this->_builder->getSchema();
			$joinTableName=$this->relation->getJunctionTableName();
			if(($joinTable=$schema->getTable($joinTableName))===null)
				throw new CDbException(Yii::t('yii','The relation "{relation}" in active record class "{class}" is not specified correctly: the join table "{joinTable}" given in the foreign key cannot be found in the database.',
					array('{class}'=>get_class($parent->model), '{relation}'=>$this->relation->name, '{joinTable}'=>$joinTableName)));
			$fks=$this->relation->getJunctionForeignKeys();

			return $this->joinManyMany($joinTable,$fks,$parent);
		}
		else
		{
			$fks=is_array($this->relation->foreignKey) ? $this->relation->foreignKey : preg_split('/\s*,\s*/',$this->relation->foreignKey,-1,PREG_SPLIT_NO_EMPTY);
			if($this->slave!==null)
			{
				if($this->relation instanceof CBelongsToRelation)
				{
					$fks=array_flip($fks);
					$pke=$this->slave;
					$fke=$this;
				}
				else
				{
					$pke=$this;
					$fke=$this->slave;
				}
			}
			elseif($this->relation instanceof CBelongsToRelation)
			{
				$pke=$this;
				$fke=$parent;
			}
			else
			{
				$pke=$parent;
				$fke=$this;
			}
			return $this->joinOneMany($fke,$fks,$pke,$parent);
		}
	}

	/**
	 * Generates the join statement for one-many relationship.
	 * This works for HAS_ONE, HAS_MANY and BELONGS_TO.
	 * @param CJoinElement $fke the join element containing foreign keys
	 * @param array $fks the foreign keys
	 * @param CJoinElement $pke the join element contains primary keys
	 * @param CJoinElement $parent the parent join element
	 * @return string the join statement
	 * @throws CDbException if a foreign key is invalid
	 */
	private function joinOneMany($fke,$fks,$pke,$parent)
	{
		$schema=$this->_builder->getSchema();
		$joins=array();
		if(is_string($fks))
			$fks=preg_split('/\s*,\s*/',$fks,-1,PREG_SPLIT_NO_EMPTY);
		foreach($fks as $i=>$fk)
		{
			if(!is_int($i))
			{
				$pk=$fk;
				$fk=$i;
			}

			if(!isset($fke->_table->columns[$fk]))
				throw new CDbException(Yii::t('yii','The relation "{relation}" in active record class "{class}" is specified with an invalid foreign key "{key}". There is no such column in the table "{table}".',
					array('{class}'=>get_class($parent->model), '{relation}'=>$this->relation->name, '{key}'=>$fk, '{table}'=>$fke->_table->name)));

			if(is_int($i))
			{
				if(isset($fke->_table->foreignKeys[$fk]) && $schema->compareTableNames($pke->_table->rawName, $fke->_table->foreignKeys[$fk][0]))
					$pk=$fke->_table->foreignKeys[$fk][1];
				else // FK constraints undefined
				{
					if(is_array($pke->_table->primaryKey)) // composite PK
						$pk=$pke->_table->primaryKey[$i];
					else
						$pk=$pke->_table->primaryKey;
				}
			}

			$joins[]=$fke->getColumnPrefix().$schema->quoteColumnName($fk) . '=' . $pke->getColumnPrefix().$schema->quoteColumnName($pk);
		}
		if(!empty($this->relation->on))
			$joins[]=$this->relation->on;
		return $this->relation->joinType . ' ' . $this->getTableNameWithAlias() . ' ON (' . implode(') AND (',$joins).')';
	}

	/**
	 * Generates the join statement for many-many relationship.
	 * @param CDbTableSchema $joinTable the join table
	 * @param array $fks the foreign keys
	 * @param CJoinElement $parent the parent join element
	 * @return string the join statement
	 * @throws CDbException if a foreign key is invalid
	 */
	private function joinManyMany($joinTable,$fks,$parent)
	{
		$schema=$this->_builder->getSchema();
		$joinAlias=$schema->quoteTableName($this->relation->name.'_'.$this->tableAlias);
		$parentCondition=array();
		$childCondition=array();

		$fkDefined=true;
		foreach($fks as $i=>$fk)
		{
			if(!isset($joinTable->columns[$fk]))
				throw new CDbException(Yii::t('yii','The relation "{relation}" in active record class "{class}" is specified with an invalid foreign key "{key}". There is no such column in the table "{table}".',
					array('{class}'=>get_class($parent->model), '{relation}'=>$this->relation->name, '{key}'=>$fk, '{table}'=>$joinTable->name)));

			if(isset($joinTable->foreignKeys[$fk]))
			{
				list($tableName,$pk)=$joinTable->foreignKeys[$fk];
				if(!isset($parentCondition[$pk]) && $schema->compareTableNames($parent->_table->rawName,$tableName))
					$parentCondition[$pk]=$parent->getColumnPrefix().$schema->quoteColumnName($pk).'='.$joinAlias.'.'.$schema->quoteColumnName($fk);
				elseif(!isset($childCondition[$pk]) && $schema->compareTableNames($this->_table->rawName,$tableName))
					$childCondition[$pk]=$this->getColumnPrefix().$schema->quoteColumnName($pk).'='.$joinAlias.'.'.$schema->quoteColumnName($fk);
				else
				{
					$fkDefined=false;
					break;
				}
			}
			else
			{
				$fkDefined=false;
				break;
			}
		}

		if(!$fkDefined)
		{
			$parentCondition=array();
			$childCondition=array();
			foreach($fks as $i=>$fk)
			{
				if($i<count($parent->_table->primaryKey))
				{
					$pk=is_array($parent->_table->primaryKey) ? $parent->_table->primaryKey[$i] : $parent->_table->primaryKey;
					$parentCondition[$pk]=$parent->getColumnPrefix().$schema->quoteColumnName($pk).'='.$joinAlias.'.'.$schema->quoteColumnName($fk);
				}
				else
				{
					$j=$i-count($parent->_table->primaryKey);
					$pk=is_array($this->_table->primaryKey) ? $this->_table->primaryKey[$j] : $this->_table->primaryKey;
					$childCondition[$pk]=$this->getColumnPrefix().$schema->quoteColumnName($pk).'='.$joinAlias.'.'.$schema->quoteColumnName($fk);
				}
			}
		}

		if($parentCondition!==array() && $childCondition!==array())
		{
			$join=$this->relation->joinType.' '.$joinTable->rawName.' '.$joinAlias;
			$join.=' ON ('.implode(') AND (',$parentCondition).')';
			$join.=' '.$this->relation->joinType.' '.$this->getTableNameWithAlias();
			$join.=' ON ('.implode(') AND (',$childCondition).')';
			if(!empty($this->relation->on))
				$join.=' AND ('.$this->relation->on.')';
			return $join;
		}
		else
			throw new CDbException(Yii::t('yii','The relation "{relation}" in active record class "{class}" is specified with an incomplete foreign key. The foreign key must consist of columns referencing both joining tables.',
				array('{class}'=>get_class($parent->model), '{relation}'=>$this->relation->name)));
	}
}

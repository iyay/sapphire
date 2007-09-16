<?php

/**
 * @package sapphire
 * @subpackage core
 */

/**
 * Abstract database connectivity class.
 * Sub-classes of this implement the actual database connection libraries
 */
abstract class Database extends Object {
	/**
	 * Connection object to the database.
	 * @param resource
	 */
	static $globalConn;
	
	/**
	 * If this is false, then information about database operations
	 * will be displayed, eg creation of tables.
	 * @param boolean
	 */
	public static $supressOutput = false;
	
	/**
	 * Execute the given SQL query.
	 * This abstract function must be defined by subclasses as part of the actual implementation.
	 * It should return a subclass of Query as the result.
	 * @param string $sql The SQL query to execute
	 * @param int $errorLevel The level of error reporting to enable for the query
	 * @return Query
	 */
	abstract function query($sql, $errorLevel = E_USER_ERROR);
	
	/**
	 * Get the autogenerated ID from the previous INSERT query.
	 * @return int
	 */
	abstract function getGeneratedID();
	
	/**
	 * Check if the connection to the database is active.
	 * @return boolean
	 */
	abstract function isActive();
	
	/**
	 * Create the database and connect to it. This can be called if the
	 * initial database connection is not successful because the database
	 * does not exist.
	 * @return boolean Returns true if successful
	 */
	abstract function createDatabase();	
	
	/**
	 * Create a new table.
	 * The table will have a single field - the integer key ID.
	 * @param string $table Name of table to create.
	 */
	abstract function createTable($table, $fields = null, $indexes = null);
	
	/**
	 * Alter a table's schema.
	 */
	abstract function alterTable($table, $newFields, $newIndexes, $alteredFields, $alteredIndexes);
	
	/**
	 * Rename a table.
	 * @param string $oldTableName The old table name.
	 * @param string $newTableName The new table name.
	 */
	abstract function renameTable($oldTableName, $newTableName);
	
	/**
	 * Create a new field on a table.
	 * @param string $table Name of the table.
	 * @param string $field Name of the field to add.
	 * @param string $spec The field specification, eg 'INTEGER NOT NULL'
	 */
	abstract function createField($table, $field, $spec);

	/**
	 * Get a list of all the fields for the given table.
	 * Returns a map of field name => field spec.
	 * @param string $table The table name.
	 * @return array
	 */
	protected abstract function fieldList($table);
	
	/**
	 * Returns a list of all tables in the database.
	 * The table names will be in lower case.
	 * @return array
	 */
	protected abstract function tableList();
	
	/**
	 * The table list, generated by the tableList() function.
	 * Used by the requireTable() function.
	 * @var array
	 */
	protected $tableList;
	
	/**
	 * The field list, generated by the fieldList() function.
	 * An array of maps of field name => field spec, indexed
	 * by table name.
	 * @var array
	 */
	protected $fieldList;
	
	/**
	 * The index list for each table, generated by the indexList() function.
	 * An map from table name to an array of index names.
	 * @var array
	 */
	protected $indexList;
	
	
	/**
	 * Large array structure that represents a schema update transaction
	 */
	protected $schemaUpdateTransaction;
	
	/**
	 * Start a schema-updating transaction.
	 * All calls to requireTable/Field/Index will keep track of the changes requested, but not actually do anything.
	 * Once	
	 */
	function beginSchemaUpdate() {
		$this->tableList = $this->tableList();
		$this->indexList = null;
		$this->fieldList = null;
		$this->schemaUpdateTransaction = array();
	}
	
	function endSchemaUpdate() {
		foreach($this->schemaUpdateTransaction as $tableName => $changes) {
			switch($changes['command']) {
				case 'create':
					$this->createTable($tableName, $changes['newFields'], $changes['newIndexes']);
					break;
				
				case 'alter':
					$this->alterTable($tableName, $changes['newFields'], $changes['newIndexes'],
						$changes['alteredFields'], $changes['alteredIndexes']);
					break;
			}
		}
		$this->schemaUpdateTransaction = null;
	}
	
	// Transactional schema altering functions - they don't do anyhting except for update schemaUpdateTransaction
	
	function transCreateTable($table) {
		$this->schemaUpdateTransaction[$table] = array('command' => 'create', 'newFields' => array(), 'newIndexes' => array());
	}
	function transCreateField($table, $field, $schema) {
		$this->transInitTable($table);
		$this->schemaUpdateTransaction[$table]['newFields'][$field] = $schema;
	}
	function transCreateIndex($table, $index, $schema) {
		$this->transInitTable($table);
		$this->schemaUpdateTransaction[$table]['newIndexes'][$index] = $schema;
	}
	function transAlterField($table, $field, $schema) {
		$this->transInitTable($table);
		$this->schemaUpdateTransaction[$table]['alteredFields'][$field] = $schema;
	}
	function transAlterIndex($table, $index, $schema) {
		$this->transInitTable($table);
		$this->schemaUpdateTransaction[$table]['alteredIndexes'][$index] = $schema;
	}
	
	/**
	 * Handler for the other transXXX methods - mark the given table as being altered
	 * if it doesn't already exist
	 */
	protected function transInitTable($table) {
		if(!isset($this->schemaUpdateTransaction[$table])) {
			$this->schemaUpdateTransaction[$table] = array(
				'command' => 'alter',
				'newFields' => array(),
				'newIndexes' => array(),
				'alteredFields' => array(),
				'alteredIndexes' => array(),
			);
		}		
	}
	
	
	/**
	 * Generate the following table in the database, modifying whatever already exists
	 * as necessary.
	 * @param string $table The name of the table
	 * @param string $fieldSchema A list of the fields to create, in the same form as DataObject::$db
	 * @param string $indexSchema A list of indexes to create.  The keys of the array are the names of the index.
	 * The values of the array can be one of:
	 *   - true: Create a single column index on the field named the same as the index.
	 *   - array('fields' => array('A','B','C'), 'type' => 'index/unique/fulltext'): This gives you full
	 *     control over the index.
	 */
	function requireTable($table, $fieldSchema = null, $indexSchema = null) {
		if(!isset($this->tableList[strtolower($table)])) {
			$this->transCreateTable($table);
			Database::alteration_message("Table $table: created","created");
		} else {
			$this->checkAndRepairTable($table);
		}
			
		// Create custom fields
		if($fieldSchema) {
			foreach($fieldSchema as $fieldName => $fieldSpec) {
				$fieldObj = eval(ViewableData::castingObjectCreator($fieldSpec));
				$fieldObj->setTable($table);
				$fieldObj->requireField();
			}
		}	

		// Create custom indexes
		if($indexSchema) {
			foreach($indexSchema as $indexName => $indexDetails) {
				$this->requireIndex($table, $indexName, $indexDetails);
			}
		}		
	}
	
	/**
	 * If the given table exists, move it out of the way by renaming it to _obsolete_(tablename).
	 * @param string $table The table name.
	 */
	function dontRequireTable($table) {
		if(!isset($this->tableList)) $this->tableList = $this->tableList();
		if(isset($this->tableList[strtolower($table)])) {
			while($this->tableList[strtolower("_obsolete_{$table}$suffix")]) {
				$suffix = $suffix ? ($suffix+1) : 2;
			}			
			$this->renameTable($table, "_obsolete_{$table}$suffix");
			Database::alteration_message("Table $table: renamed to _obsolete_{$table}$suffix","obsolete");
		}
	}
	
	/**
	 * Generate the given index in the database, modifying whatever already exists as necessary.
	 * @param string $table The table name.
	 * @param string $index The index name.
	 * @param string|boolean $spec The specification of the index. See requireTable() for more information.
	 */
	function requireIndex($table, $index, $spec) {
		$newTable = false;
		
		if($spec === true) {
			$spec = "($index)";
		}
		$spec = ereg_replace(" *, *",",",$spec);

		if(!isset($this->tableList[strtolower($table)])) $newTable = true;

		if(!$newTable &&  !isset($this->indexList[$table])) {
			$this->indexList[$table] = $this->indexList($table);
		}
		if($newTable || !isset($this->indexList[$table][$index])) {
			$this->transCreateIndex($table, $index, $spec);
			Database::alteration_message("Index $table.$index: created as $spec","created");
		} else if($this->indexList[$table][$index] != $spec) {
			$this->transAlterIndex($table, $index, $spec);
			Database::alteration_message("Index $table.$index: changed to $spec <i style=\"color: #AAA\">(from {$this->indexList[$table][$index]})</i>","changed");			
		}
	}

	/**
	 * Generate the given field on the table, modifying whatever already exists as necessary.
	 * @param string $table The table name.
	 * @param string $field The field name.
	 * @param string $spec The field specification.
	 */
	function requireField($table, $field, $spec) {
		$newTable = false;
		
		Profiler::mark('requireField');
		// Collations didn't come in until MySQL 4.1.  Anything earlier will throw a syntax error if you try and use
		// collations.
		if(!$this->supportsCollations()) {
			$spec = eregi_replace(' *character set [^ ]+( collate [^ ]+)?( |$)','\\2',$spec);
		}
		if(!isset($this->tableList[strtolower($table)])) $newTable = true;

		if(!$newTable && !isset($this->fieldList[$table])) {
			$this->fieldList[$table] = $this->fieldList($table);
		}
		
		if($newTable || !isset($this->fieldList[$table][$field])) {
			Profiler::mark('createField');
			$this->transCreateField($table, $field, $spec);
			Profiler::unmark('createField');
			Database::alteration_message("Field $table.$field: created as $spec","created");
		} else if($this->fieldList[$table][$field] != $spec) {
			Profiler::mark('alterField');
			$this->transAlterField($table, $field, $spec);
			Profiler::unmark('alterField');
			Database::alteration_message("Field $table.$field: changed to $spec <i style=\"color: #AAA\">(from {$this->fieldList[$table][$field]})</i>","changed");
		}
		Profiler::unmark('requireField');
	}

	/**
	 * Execute a complex manipulation on the database.
	 * A manipulation is an array of insert / or update sequences.  The keys of the array are table names,
	 * and the values are map containing 'command' and 'fields'.  Command should be 'insert' or 'update',
	 * and fields should be a map of field names to field values, including quotes.  The field value can
	 * also be a SQL function or similar.
	 * @param array $manipulation
	 */
	function manipulate($manipulation) {
		foreach($manipulation as $table => $writeInfo) {
			if(isset($writeInfo['fields']) && $writeInfo['fields']) {
				$fieldList = array();
				foreach($writeInfo['fields'] as $fieldName => $fieldVal) {
					$fieldList[] = "`$fieldName` = $fieldVal";
				}
				$fieldList = implode(", ", $fieldList);
				
				if(!isset($writeInfo['where']) && isset($writeInfo['id'])) {
					$writeInfo['where'] = "ID = $writeInfo[id]";
				}
				
				switch($writeInfo['command']) {
					case "update":
						$sql = "update `$table` SET $fieldList where $writeInfo[where]";
						$this->query($sql);
						
						// If numAffectedRecord = 0, then we want to run instert instead
						if(!$this->affectedRows()) {
							if(!isset($writeInfo['fields']['ID']) && isset($writeInfo['id'])) {
								$fieldList .= ", ID = $writeInfo[id]";
							}
							$sql = "insert into `$table` SET $fieldList";
							$this->query($sql, null);			
						}					
						break;
					
					case "insert":
						if(!isset($writeInfo['fields']['ID']) && isset($writeInfo['id'])) {
							$fieldList .= ", ID = $writeInfo[id]";
						}
						$fieldList = Database::replace_with_null($fieldList);
						$sql = "insert into `$table` SET $fieldList";
						$this->query($sql);
						break;
						
					default:
						$sql = null;
						user_error("Database::manipulate() Can't recognise command '$writeInfo[command]'", E_USER_ERROR);
				}
			}
		}
	}
	
	/** Replaces "''" with "null", recursively walks through the given array.
	 * @param string $array Array where the replacement should happen
	 */
	static function replace_with_null(&$array) {
		$array = str_replace('\'\'', "null", $array);
		
		if(is_array($array)) {
			foreach($array as $key => $value) {
				if (is_array($value)) {
					array_walk($array, array(Database, 'replace_with_null'));
				}
			}
		}

		return $array;
	}
	
	/** 
	 * Error handler for database errors.
	 * All database errors will call this function to report the error.  It isn't a static function;
	 * it will be called on the object itself and as such can be overridden in a subclass.
	 * @todo hook this into a more well-structured error handling system.
	 * @param string $msg The error message.
	 * @param int $errorLevel The level of the error to throw.
	 */
	function databaseError($msg, $errorLevel = E_USER_ERROR) {
		user_error("DATABASE ERROR: $msg", $errorLevel);
	}
	
	/**
	 * Enable supression of database messages.
	 */
	function quiet() {
		Database::$supressOutput = true;
	}
	
	static function alteration_message($message,$type=""){
			if(!Database::$supressOutput) {
				$color = "";
				switch ($type){
					case "created":
						$color = "green";
						break;
					case "obsolete":
						$color = "red";
						break;
					case "error":
						$color = "red";
						break;
					case "deleted":
						$color = "red";
						break;						
					case "changed":
						$color = "blue";
						break;
					case "repaired":
						$color = "blue";
						break;
					default:
						$color="";
				}
				echo "<li style=\"color: $color\">$message</li>";
			}
	}
	
}

/**
 * Abstract query-result class.
 * Once again, this should be subclassed by an actual database implementation.  It will only
 * ever be constructed by a subclass of Database.  The result of a database query - an iteratable object that's returned by DB::Query
 *
 * Primarily, the Query class takes care of the iterator plumbing, letting the subclasses focusing
 * on providing the specific data-access methods that are required: {@link nextRecord()}, {@link numRecords()}
 * and {@link seek()}
 */
abstract class Query extends Object implements Iterator {
	/**
	 * The current record in the interator.
	 * @var array
	 */
	private $currentRecord = null;
	
	/**
	 * The number of the current row in the interator.
	 * @var int
	 */
	private $rowNum = -1;
	
	/**
	 * Return an array containing all values in the leftmost column.
	 * @return array
	 */
	public function column() {
		foreach($this as $record) {
			$column[] = reset($record);
		}
		return isset($column) ? $column : null;
	}

	/**
	 * Return an array containing all values in the leftmost column, where the keys are the
	 * same as the values.
	 * @return array
	 */
	public function keyedColumn() {
		foreach($this as $record) {
			$val = reset($record);
			$column[$val] = $val;
		}
		return $column;
	}

	/**
	 * Return a map from the first column to the second column.
	 * @return array
	 */
	public function map() {
		foreach($this as $record) {
			$key = reset($record);
			$val = next($record);
			$column[$key] = $val;
		}
		return $column;
	}
	
	/**
	 * Returns the next record in the iterator.
	 * @return array
	 */
	public function record() {
		return $this->next();
	}
	
	/**
	 * Returns the first column of the first record.
	 * @return string
	 */
	public function value() {
		foreach($this as $record) {
			return reset($record);
		}
	}
	
	/**
	 * Return an HTML table containing the full result-set
	 */
	public function table() {
		$first = true;
		$result = "<table>\n";
		
		foreach($this as $record) {
			if($first) {
				$result .= "<tr>";
				foreach($record as $k => $v) {
					$result .= "<th>" . Convert::raw2xml($k) . "</th> ";
 				}
				$result .= "</tr> \n";
			}

			$result .= "<tr>";
			foreach($record as $k => $v) {
				$result .= "<td>" . Convert::raw2xml($v) . "</td> ";
			}
			$result .= "</tr> \n";
			
			$first = false;
		}
		
		if($first) return "No records found";
		return $result;
	}
	
	/**
	 * Iterator function implementation. Rewind the iterator to the first item and return it.
	 * Makes use of {@link seek()} and {@link numRecords()}, takes care of the plumbing.
	 * @return array
	 */
	public function rewind() {
		if($this->numRecords() > 0) {
			return $this->seek(0);
		}
	}

	/**
	 * Iterator function implementation. Return the current item of the iterator.
	 * @return array
	 */
	public function current() {
		if(!$this->currentRecord) {
			return $this->next();
		} else {
			return $this->currentRecord;
		}
	}
	
	/**
	 * Iterator function implementation. Return the first item of this iterator.
	 * @return array
	 */
	public function first() {
		$this->rewind();
		return $this->current();
	}

	/**
	 * Iterator function implementation. Return the row number of the current item.
	 * @return int
	 */
	public function key() {
		return $this->rowNum;
	}

	/**
	 * Iterator function implementation. Return the next record in the iterator.
	 * Makes use of {@link nextRecord()}, takes care of the plumbing.
	 * @return array
	 */
	public function next() {
		$this->currentRecord = $this->nextRecord(); 
		$this->rowNum++;
		return $this->currentRecord;
	}

	/**
	 * Iterator function implementation. Check if the iterator is pointing to a valid item.
	 * @return boolean
	 */
	public function valid() {
	 	return $this->current() !== false;
	}
	
	/**
	 * Return the next record in the query result.
	 * @return array
	 */
	abstract function nextRecord();

	/**
	 * Return the total number of items in the query result.
	 * @return int
	 */
	abstract function numRecords();

	/**
	 * Go to a specific row number in the query result and return the record.
	 * @param int $rowNum Tow number to go to.
	 * @return array
	 */
	abstract function seek($rowNum);
}

?>

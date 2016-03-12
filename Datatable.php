<?php if (!defined('BASEPATH')) exit('No direct script access allowed');
/** 
 * The MIT License (MIT)

Copyright (c) 2015 Paul Zepernick

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
 * 
 */
 
/**
 * Codeigniter Datatable library
 * 
 *
 * @author Paul Zepernick
 */
class Datatable{
	
	private static $VALID_MATCH_TYPES = array('before', 'after', 'both', 'none');

    //private $model;
    public $model;
	
	private $CI;
	
	private $rowIdCol;
	
	private $preResultFunc = true;
	
	// assoc. array.  key is column name being passed from the DataTables data property and value is before, after, both, none
	private $matchType = array();
	
    
    /**
	 * @params
	 * 		Associative array.  Expecting key "model" and the value name of the model to load
	 */
	public function __construct($params)	{
		$CI =& get_instance();
		
		if(isset($params['model']) === FALSE) {
			 throw new Exception('Expected a parameter named "model".');
		}
		
		if(strpos($params['model'],"/")){
			$mode = explode('/',$params['model']);
			$model = $mode[1];
			$CI -> load -> model($params['model']);
		}else{
			$model = $params['model'];
			$CI -> load -> model($model);
		}
		
		$this -> rowIdCol = isset($params['rowIdCol']) ? $params['rowIdCol'] : NULL;
        
		//$CI -> load -> model($model);
		
        if(($CI -> $model instanceof DatatableModel) === false) {
            throw new Exception('Model must implement the DatatableModel Interface');
        }
        
        //even though $model is a String php looks at the String value and finds the property
        //by that name.  Hence the $ when that would not normally be there for a property
        $this -> model = $CI -> $model;
		$this -> CI = $CI;
		
	}
	
	/**
	 * Register a function that will fire after the JSON object is put together
	 * in the library, but before sending it to the browser.  The function should accept 1 parameter
	 * for the JSON object which is stored as associated array.
	 * 
	 * IMPORTANT: Make sure to add a & in front of the parameter to get a reference of the Array,otherwise
	 * your changes will not be picked up by the library
	 * 
	 * 		function(&$json) {
	 * 			//do some work and add to the json if you wish.
	 * 		}
	 */
	public function setPreResultCallback($func) {
		//echo '34534';exit;
		if(is_object($func) === FALSE || ($func instanceof Closure) === FALSE) {
			throw new Exception('Expected Anonymous Function Parameter Not Received');	
		}
		//var_dump($func);exit;
		$this -> preResultFunc = $func;
		
		return $this;
	}
	
	
	/**
	 * Sets the wildcard matching to be a done on a specific column in the search
	 * 
	 * @param col
	 * 		column sepcified in the DataTables "data" property
	 * @param type
	 * 		Type of wildcard search before, after, both, none.  Default is after if not specified for a column.
	 * @return	Datatable
	 */
	public function setColumnSearchType($col, $type) {
		$type = trim(strtolower($type));
		//make sure we have a valid type
		if(in_array($type, self :: $VALID_MATCH_TYPES) === FALSE) {
			throw new Exception('[' . $type . '] is not a valid type.  Must Use: ' . implode(', ', self :: $VALID_MATCH_TYPES));
		}
		
		$this -> matchType[$col] = $type;
		
	//	log_message('info', 'setColumnSearchType() ' . var_export($this -> matchType, TRUE));
		
		return $this;
	}
	
	/**
	 * Get the current search type for a column
	 * 
	 * @param col
	 * 		column sepcified in the DataTables "data" property
	 * 
	 * @return search type string
	 */
	public function getColumnSearchType($col) {
	//	log_message('info', 'getColumnSearchType() ' . var_export($this -> matchType, TRUE));
		return isset($this -> matchType[$col]) ? $this -> matchType[$col] : 'after';
	}
	
	/**
	 * @param formats
	 * 			Associative array. 
	 * 				Key is column name
	 * 				Value format: percent, currency, date, boolean
	 */
	public function datatableJson($formats = array(), $debug = FALSE) {
		
		$f = $this -> CI -> input;
		$start = (int)$f -> post('start');
		$limit = (int)$f -> post('length');
		
		
		
		$jsonArry = array();
		$jsonArry['start'] = $start;
		$jsonArry['limit'] = $limit;
		$jsonArry['draw'] = (int)$f -> post('draw');
		$jsonArry['recordsTotal'] = 0;
		$jsonArry['recordsFiltered'] = 0;
		$jsonArry['data'] = array();
		
		//query the data for the records being returned
		$selectArray = array();
		$customCols = array();
		$columnIdxArray = array();
		
		foreach($f -> post('columns') as $c) {
			$columnIdxArray[] = $c['data'];
			if(substr($c['data'], 0, 1) === '$') {
				//indicates a column specified in the appendToSelectStr()
				$customCols[] = $c['data'];
				continue;
			}
			$selectArray[] = $c['data'];
		}
		if($this -> rowIdCol !== NULL && in_array($this -> rowIdCol, $selectArray) === FALSE) {
			$selectArray[] = $this -> rowIdCol; 
		}
		
		//put the select string together
		$sqlSelectStr = implode(', ', $selectArray);
		$appendStr = $this -> model -> appendToSelectStr();
		if (is_null($appendStr) === FALSE) {
			foreach($appendStr as $alias => $sqlExp) {
				$sqlSelectStr .= ', ' . $sqlExp . ' ' . $alias;	
			}
			
		}
		
		$this -> CI -> db ->start_cache();
	
		$this -> CI -> db -> select($sqlSelectStr);			
		$whereDebug = $this -> sqlJoinsAndWhere();

		//setup order by
		$customExpArray = is_null($this -> model -> appendToSelectStr()) ? array() : $this -> model -> appendToSelectStr();
		//print_r($customExpArray);
		foreach($f -> post('order') as $o) {
			if($o['column'] !== '') {
				$colName = $columnIdxArray[$o['column']];
				//handle custom sql expressions/subselects
				if(substr($colName, 0, 2) === '$.') {
					$aliasKey = substr($colName, 2);
					if(isset($customExpArray[$aliasKey]) === FALSE) {
						throw new Exception('Alias['. $aliasKey .'] Could Not Be Found In appendToSelectStr() Array');
					}
					
					//$colName = $customExpArray[$aliasKey];
					$colName = $aliasKey;
				}
				$this -> CI -> db -> order_by("$colName", $o['dir']);
			}
		}

		$this -> CI -> db ->stop_cache();
		
		// Calculate total records from the query
		$totalRecords = $this -> CI -> db -> get()->num_rows();
	
		$this -> CI -> db -> limit($limit, $start);
		$query = $this -> CI -> db -> get();	
		
		$jsonArry['debug_sql'] = $this -> CI -> db -> last_query();
		
		$this -> CI ->db->flush_cache();
		
		
		if(!$query) {
			$jsonArry['errorMessage'] = $this -> CI -> db -> _error_message();
			return $jsonArry;
		}
		
		if($debug === TRUE) {
			$jsonArry['debug_sql'] = $this -> CI -> db -> last_query();
		}
		//echo $jsonArry['debug_sql'];exit;
		//process the results and create the JSON objects
		$dataArray = array();
		$allColsArray = array_merge($selectArray, $customCols);
		foreach ($query -> result() as $row) {
			//print_r($allColsArray);
			//print_r($row);exit;
			$colObj = array();
			//loop rows returned by the query
			foreach($allColsArray as $c) {
			    if(trim($c) === '') {
			        continue;
			    }
                
				$propParts = explode('.', $c);
				
				$prop = trim(end($propParts)); 
				//loop columns in each row that the grid has requested
				if(count($propParts) > 1) {
					//nest the objects correctly in the json if the column name includes
					//the table alias
					$nestedObj = array();
					if(isset($colObj[$propParts[0]])) {
						//check if we alraedy have a object for this alias in the array
						$nestedObj = $colObj[$propParts[0]];
					}
					
					
					
					$nestedObj[$propParts[1]] = $this -> formatValue($formats, $prop, $row -> $prop);
					$colObj[$propParts[0]] = $nestedObj;
				} else {
					$colObj[$c] = $this -> formatValue($formats, $prop, $row -> $prop);
				}
			}
			
			if($this -> rowIdCol !== NULL) {
				$tmpRowIdSegments = explode('.', $this -> rowIdCol);
				$idCol = trim(end($tmpRowIdSegments));
				$colObj['DT_RowId'] = 'row_'.$row -> $idCol;
			}
			$dataArray[] = $colObj;
		}
		
		
		//$this -> sqlJoinsAndWhere();
		//$totalRecords = $this -> CI -> db -> count_all_results();
		
		
		
		$jsonArry = array();
		$jsonArry['start'] = $start;
		$jsonArry['limit'] = $limit;
		$jsonArry['draw'] = (int)$f -> post('draw');
		$jsonArry['recordsTotal'] = $totalRecords;
		$jsonArry['recordsFiltered'] = $totalRecords;
		$jsonArry['data'] = $dataArray;
		//$jsonArry['debug'] = $whereDebug;
		
		if($this -> preResultFunc !== FALSE) {
			$func = $this -> preResultFunc;
			$func($jsonArry);
			//$this->setPreResultCallback($jsonArry);
		}
		
		return $jsonArry;
		
	}

	private function formatValue($formats, $column, $value) {
		if (isset($formats[$column]) === FALSE || trim($value) == '') {
			return $value;
		}
		
		switch ($formats[$column]) {
			case 'date' :
				$dtFormats = array('Y-m-d H:i:s', 'Y-m-d');
				$dt = null;
				//try to parse the date as 2 different formats
				foreach($dtFormats as $f) {
					$dt = DateTime::createFromFormat($f, $value);
					if($dt !== FALSE) {
						break;
					}
				}
				if($dt === FALSE) {
					//neither pattern could parse the date
					throw new Exception('Could Not Parse To Date For Formatting [' . $value . ']');
				}
				return $dt -> format('m/d/Y');
			case 'percent' :
				///$formatter = new \NumberFormatter('en_US', \NumberFormatter::PERCENT);
				//return $formatter -> format(floatval($value) * .01);
				return $value . '%';
			case 'currency' :
				return '$' . number_format(floatval($value), 2);
			case 'boolean' :
				$b = filter_var($value, FILTER_VALIDATE_BOOLEAN);
				return $b ? 'Yes' : 'No';
		}
		
		return $value;
	}

	//specify the joins and where clause for the Active Record. This code is common to
	//fetch the data and get a total record count
	private function sqlJoinsAndWhere() {
		$debug = '';
		//$this -> CI -> db-> _protect_identifiers = FALSE;
		$this -> CI -> db -> from($this -> model -> fromTableStr());
		
		$joins = $this -> model -> joinArray() === NULL ? array() : $this -> model -> joinArray();
		foreach ($joins as $table => $on) {
			$joinTypeArray = explode('|', $table);
			$tableName = $joinTypeArray[0];
			$join = 'inner';
			if(count($joinTypeArray) > 1) {
				$join = $joinTypeArray[1];
			}
			$this -> CI -> db -> join($tableName, $on, $join);
		}
		
		$customExpArray = is_null($this -> model -> appendToSelectStr()) ? 
								array() : 
								$this -> model -> appendToSelectStr();
		
		$f = $this -> CI -> input;
		$f -> post('columns');
		//var_dump($f);exit;
		$k1 = 1;
		foreach($f -> post('columns') as $c) {
			//echo $k1++;
			//var_dump($c);exit;
			if($c['search']['value'] !== '') {
				$colName = $c['data'];
				$searchType = $this -> getColumnSearchType($colName);
				//log_message('info', 'colname[' . $colName . '] searchtype[' . $searchType . ']');
				//handle custom sql expressions/subselects
				if(substr($colName, 0, 2) === '$.') {
					$aliasKey = substr($colName, 2);
					if(isset($customExpArray[$aliasKey]) === FALSE) {
						throw new Exception('Alias['. $aliasKey .'] Could Not Be Found In appendToSelectStr() Array');
					}
					
					$colName = $customExpArray[$aliasKey];
				}
				$debug .= 'col[' . $c['data'] .'] value[' . $c['search']['value'] . '] ' . PHP_EOL;
			//	log_message('info', 'colname[' . $colName . '] searchtype[' . $searchType . ']');
				$this -> CI -> db -> like($colName, $c['search']['value'], $searchType);
			}
		}
		
        //append a static where clause to what the user has filtered, if the model tells us to do so
		$wArray = $this -> model -> whereClauseArray();
		if(is_null($wArray) === FALSE && is_array($wArray) === TRUE && count($wArray) > 0) {
			if(isset($wArray['donot_convert_type']) && $wArray['donot_convert_type'] == 1){
				unset($wArray['donot_convert_type']);
				foreach($wArray as $wKey=>$wVal){
					$this -> CI -> db -> where($wKey,$wVal,false);
				}
			}else{
				$this -> CI -> db -> where($wArray);
			}
		}
		
		$gArray = $this -> model -> groupByClauseArray();
		if(is_null($gArray) === FALSE && is_array($gArray) === TRUE && count($gArray) > 0) {
			$this -> CI -> db -> group_by($gArray);
		}
		return $debug;
	}
}
// END Datatable Class
/* End of file Datatable.php */
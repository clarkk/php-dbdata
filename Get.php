<?php

namespace dbdata;

class Get extends Data {
	//	Get child entries
	protected $get_children 	= true;
	
	//	Use integer multiplier
	protected $use_multiplier 	= true;
	
	//	Get only writeable fields
	protected $get_writeable 	= false;
	
	//	Use order clause on get
	protected $default_order 	= false;
	
	//	SQL_CALC_FOUND_ROWS
	protected $found_rows 		= false;
	protected $num_found_rows 	= 0;
	
	//	Use read lock
	protected $get_lock 		= false;
	
	public function no_children(): self{
		$this->get_children = false;
		
		return $this;
	}
	
	public function no_multiplier(): self{
		$this->use_multiplier = false;
		
		return $this;
	}
	
	public function get_writeable(): self{
		$this->get_writeable = true;
		
		return $this;
	}
	
	public function default_order(): self{
		$this->default_order = true;
		
		return $this;
	}
	
	public function found_rows(): self{
		$this->found_rows = true;
		
		return $this;
	}
	
	public function get_found_rows(): int{
		return $this->num_found_rows;
	}
	
	public function no_group(): self{
		$this->default_group = false;
		
		return $this;
	}
	
	public function get_lock(): self{
		$this->get_lock = true;
		
		return $this;
	}
	
	public function exec(string $table, Array $input){
		$this->table 	= $table;
		$this->input 	= $input;
		$this->method 	= self::METHOD_GET;
		
		try{
			$table = $this->prepare_table();
			
			//	Load table class
			$this->load_class($table);
			
			$like_clause = [];
			if($this->external){
				//	Check external rights
				if($this->check_external_rights){
					$this->model_class_name::check_external_rights($this->method);
				}
				
				if(!empty($this->input['order'])){
					//	Exclude fields in order clause which are not allowed
					$order_fields = [];
					foreach($this->input['order'] as $key => $value){
						$pos = strpos($value, ' ');
						if($pos !== false){
							$value = substr($value, 0, $pos);
						}
						if(!isset($this->Table->external_field_order[$value])){
							unset($this->input['order'][$key]);
						}
						else{
							$order_fields[$value] = true;
						}
					}
					
					//	Extend order clause with default fields
					foreach($this->Table->input_default['order'] as $value){
						$field_name = $value;
						$pos = strpos($value, ' ');
						if($pos !== false){
							$field_name = substr($value, 0, $pos);
						}
						if(!isset($order_fields[$field_name])){
							$this->input['order'][] = $value;
						}
					}
				}
				
				if(!empty($this->input[self::CLAUSE_WHERE]['like'])){
					//	Exclude fields in like clause which are not allowed
					foreach($this->input[self::CLAUSE_WHERE]['like'] as $key => $value){
						if($value && in_array($key, $this->Table->external_field_like)){
							$like_clause[$key] = '%'.$value.'%';
						}
					}
					
					unset($this->input[self::CLAUSE_WHERE]['like']);
				}
			}
			
			//	Use default SELECT clause if clause is empty
			if(empty($this->input[self::CLAUSE_SELECT]) && !empty($this->Table->input_default[self::CLAUSE_SELECT])){
				$this->input[self::CLAUSE_SELECT] = $this->Table->input_default[self::CLAUSE_SELECT];
				
				//	Append default SELECT clause
				if(!empty($this->input['select_append'])){
					$this->input[self::CLAUSE_SELECT] = array_merge($this->input[self::CLAUSE_SELECT], $this->input['select_append']);
				}
			}
			
			//	Use default GROUP clause if clause is empty
			if($this->default_group){
				if(empty($this->input[self::CLAUSE_GROUP]) && !empty($this->Table->input_default[self::CLAUSE_GROUP])){
					$this->input[self::CLAUSE_GROUP] = $this->Table->input_default[self::CLAUSE_GROUP];
				}
			}
			
			//	Use default ORDER clause if clause is empty
			if($this->default_order){
				if(empty($this->input['order']) && !empty($this->Table->input_default['order'])){
					$this->input['order'] = $this->Table->input_default['order'];
				}
			}
			
			if($this->external && $this->get_writeable){
				$this->filter_writeable_fields();
			}
			
			$this->output = $this->input;
			
			//	Override WHERE clause with environment determined condition values
			$this->override(self::CLAUSE_WHERE, $this->Table->where_override());
			
			//	Omit fields
			$this->filter_omitted_fields();
			
			//	Prepare clauses for query
			$select 	= $this->prepare_select();
			$where 		= $this->prepare_where();
			$like 		= $this->prepare_like($like_clause);
			$group 		= $this->prepare_group();
			$order 		= $this->prepare_order();
			$limit 		= $this->prepare_limit();
			
			//	Return error if any inputs are invalid
			if($this->external){
				if($invalid_fields = array_diff_key($this->user_inputs, array_flip($this->Table->external_fields[$this->method]))){
					throw new Error_input(null, 'DATA_FIELDS_INVALID', [
						'fields' => implode(', ', array_keys($invalid_fields))
					]);
				}
			}
			
			//	Join clauses and translate table fields
			$str_select 	= $this->translate_clause($select);
			$str_where 		= $this->translate_clause($where['fields'], ' && ');
			$str_like 		= $this->translate_clause($like['fields'], ' || ');
			$str_group 		= $this->translate_clause($group);
			$str_order 		= $this->translate_clause($order);
			
			if($str_like){
				if($str_where){
					$str_where .= ' && ('.$str_like.')';
				}
				else{
					$str_where = $str_like;
				}
			}
			
			//	Create SQL query
			$this->sql = "SELECT".($this->found_rows ? ' SQL_CALC_FOUND_ROWS' : '')." $str_select"
				."\nFROM `$this->table`".($this->is_joined ? ' '.$this->table_short[$this->table] : '')
				.($this->table_inner_joins ? "\n".implode("\n", $this->table_inner_joins) : '')
				.($this->table_outer_joins ? "\n".implode("\n", $this->table_outer_joins) : '')
				.($str_where ? "\nWHERE $str_where" : '')
				.($str_group ? "\nGROUP BY $str_group" : '')
				.($str_order ? "\nORDER BY $str_order" : '')
				.($limit ? "\nLIMIT $limit" : '')
				.($this->get_lock ? "\nFOR UPDATE" : '');
			
			$this->sql_data = $where['names'];
			if($like['names']){
				foreach($like['names'] as $name){
					$this->sql_data[] = $name;
				}
			}
			
			if($this->debug){
				echo $this->get_sql_string();
			}
			
			if(self::$debug_sql_log){
				self::log_debug($this->get_sql_string(true));
			}
			
			if(!$this->test){
				$dbh = self::get_dbh();
				$sth = $dbh->prepare($this->sql);
				if($this->external){
					$sth->setFetchMode(\PDO::FETCH_CLASS, $this->model_class_name, [
						$this->method,
						$this->get_children,
						$this->select_translate,
						$this->get_external_raw,
						$this->use_multiplier
					]);
				}
				$sth->execute($this->sql_data);
				self::$num_get_queries++;
				
				if($this->found_rows){
					$this->num_found_rows = $dbh->query('SELECT FOUND_ROWS()')->fetchColumn();
				}
				
				return $sth;
			}
		}
		catch(\PDOException $e){
			throw new Error_db($e->getMessage().'; SQL: '.$this->get_sql_string(true), isset($dbh) ? $dbh->errorInfo()[1] : 0);
		}
	}
	
	private function parse_select_field(string $value): Array{
		$func 	= '';
		$pos 	= strpos($value, '|');
		if($pos !== false){
			$func 	= substr($value, 0, $pos);
			$field 	= substr($value, $pos + 1);
		}
		else{
			$field = $value;
		}
		
		if($func == 'any_value' && strpos($field, '=') === false){
			$field .= '='.$field;
		}
		
		$trans_field 	= '';
		$pos 			= strpos($field, '=');
		if($pos !== false){
			$trans_field 	= substr($field, $pos + 1);
			$field 			= substr($field, 0, $pos);
			
			if($this->external){
				$this->select_translate[$func ? $func.'('.$field.')' : $field] = $trans_field;
				$trans_field = '';
			}
		}
		
		return [
			'func'			=> $func,
			'field'			=> $field,
			'trans_field'	=> $trans_field
		];
	}
	
	private function prepare_select(): Array{
		if(empty($this->output[self::CLAUSE_SELECT])){
			return [];
		}
		
		$parsed = [];
		
		//	Collect user input fields
		if($this->external && !empty($this->input[self::CLAUSE_SELECT])){
			foreach($this->input[self::CLAUSE_SELECT] as $key => $value){
				$parsed[$value] = $this->parse_select_field($value);
				
				if($parsed[$value]['field'] != '*'){
					$this->user_inputs[$parsed[$value]['field']] = true;
				}
			}
		}
		
		$select = [];
		
		foreach($this->output[self::CLAUSE_SELECT] as $key => $value){
			$parse_select = $parsed[$value] ?? $this->parse_select_field($value);
			
			if($parse_select['field'] == '*'){
				$field = [
					'table_field'	=> '*',
					'field'			=> '*'
				];
			}
			else{
				$field = $this->get_translate_field($parse_select['field'], self::CLAUSE_SELECT, $parse_select['trans_field']);
			}
			
			if($parse_select['func']){
				$select[] = [
					'clause'		=> ($parse_select['func'] == 'count_distinct' ? 'count(distinct $)' : $parse_select['func'].'($)').($parse_select['trans_field'] ? ' '.$parse_select['trans_field'] : ''),
					'table_field'	=> $field['table_field'],
					'field'			=> $field['field']
				];
			}
			else{
				$select[] = [
					'clause'		=> '$'.($parse_select['trans_field'] ? ' '.$parse_select['trans_field'] : ''),
					'table_field'	=> $field['table_field'],
					'field'			=> $field['field']
				];
			}
		}
		
		return $select;
	}
	
	private function prepare_like(Array $input): Array{
		if(!$input){
			return [
				'fields'	=> [],
				'names'		=> []
			];
		}
		
		//	Collect user input fields
		if($this->external){
			foreach($input as $field => $value){
				$this->user_inputs[$field] = true;
			}
		}
		
		$where 			= [];
		$where_names 	= [];
		
		foreach($input as $field => $value){
			$field = $this->get_translate_field($field, self::CLAUSE_WHERE);
			
			$where[] = [
				'clause'		=> "$ LIKE ?",
				'table_field'	=> $field['table_field'],
				'field'			=> $field['field']
			];
			$where_names[] = $value;
		}
		
		return [
			'fields'	=> $where,
			'names'		=> $where_names
		];
	}
	
	private function prepare_group(): Array{
		if(empty($this->output[self::CLAUSE_GROUP])){
			return [];
		}
		
		//	Collect user input fields
		if($this->external && !empty($this->input[self::CLAUSE_GROUP])){
			foreach($this->input[self::CLAUSE_GROUP] as $field){
				$this->user_inputs[$field] = true;
			}
		}
		
		$group = [];
		
		foreach($this->output[self::CLAUSE_GROUP] as $field){
			$field = $this->get_translate_field($field, self::CLAUSE_GROUP);
			
			$group[] = [
				'clause'		=> '$',
				'table_field'	=> $field['table_field'],
				'field'			=> $field['field']
			];
		}
		
		return $group;
	}
	
	private function parse_order_field(string $value){
		$func 	= '';
		$syntax = '';
		$pos 	= strpos($value, '|');
		if($pos !== false){
			$func 	= substr($value, 0, $pos);
			$field 	= substr($value, $pos + 1);
		}
		else{
			$pos 	= strpos($value, ':');
			if($pos !== false){
				$field 		= substr($value, 0, $pos);
				$syntax 	= substr($value, $pos + 1);
			}
			else{
				$field = $value;
			}
		}
		
		$mode 	= '';
		$pos 	= strpos($field, ' ');
		if($pos !== false){
			$mode 	= substr($field, $pos + 1);
			$field 	= substr($field, 0, $pos);
		}
		
		return [
			'func'		=> $func,
			'field'		=> $field,
			'mode'		=> $mode,
			'syntax'	=> $syntax
		];
	}
	
	private function prepare_order(): Array{
		if(empty($this->output['order'])){
			return [];
		}
		
		$parsed = [];
		
		//	Collect user input fields
		if($this->external && !empty($this->input['order'])){
			foreach($this->input['order'] as $key => $value){
				$parsed[$value] = $this->parse_order_field($value);
				$this->user_inputs[$parsed[$value]['field']] = true;
			}
		}
		
		$order = [];
		
		foreach($this->output['order'] as $key => $value){
			$parse_order = $parsed[$value] ?? $this->parse_order_field($value);
			
			$field = $this->get_translate_field($parse_order['field'], 'order');
			
			if($parse_order['func']){
				$order[] = [
					'clause'		=> $parse_order['func'].'($)'.($parse_order['mode'] ? ' '.$parse_order['mode'] : ''),
					'table_field'	=> $field['table_field'],
					'field'			=> $field['field']
				];
			}
			elseif($parse_order['syntax']){
				$order[] = [
					'clause'		=> $parse_order['syntax'],
					'table_field'	=> $field['table_field'],
					'field'			=> $field['field']
				];
			}
			else{
				$order[] = [
					'clause'		=> '$'.($parse_order['mode'] ? ' '.$parse_order['mode'] : ''),
					'table_field'	=> $field['table_field'],
					'field'			=> $field['field']
				];
			}
		}
		
		return $order;
	}
	
	private function prepare_limit(){
		if(!empty($this->output[self::CLAUSE_LIMIT])){
			$pos = strpos($this->output[self::CLAUSE_LIMIT], ',');
			if($pos !== false){
				return (int)substr($this->output[self::CLAUSE_LIMIT], 0, $pos).','.(int)substr($this->output[self::CLAUSE_LIMIT], $pos + 1);
			}
			else{
				$limit = (int)$this->output[self::CLAUSE_LIMIT];
				
				return $limit ? $limit : '';
			}
		}
	}
	
	private function filter_writeable_fields(){
		$this->input[self::CLAUSE_SELECT] = array_keys(array_intersect_key($this->Table->external_fields['put'] ?? $this->Table->external_fields['update'], array_flip($this->input[self::CLAUSE_SELECT])));
		$this->input[self::CLAUSE_SELECT][] = 'id';
	}
}
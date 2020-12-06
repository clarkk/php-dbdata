<?php

/*
	GET:
	$input = [
		'select' => [
			'id',
			'sha1|name=new_name',
			'email=new_email',
			'block_name'
		],
		'where' => [
			'sha1|name !'	=> '12345',
			'pass <'		=> '14',
			'email bt'		=> '34,22',
			'id in'			=> [3,4,1],
			'id !in'		=> [3,4,1]
		],
		'group' => [
			'name'
			
		],
		'order' => [
			'sum|name desc',
			"name:IF(IFNULL($,'')='',1,0) DESC",
		]
	];
	
	PUT:
	$input = [
		'name'		=> 'weeee',
		'amount +'	=> 400,
		'amount -'	=> 500
	];
*/

namespace dbdata;

abstract class Data extends DB {
	
	//	External calls from API or GUI
	protected $external 				= false;
	protected $get_external_raw 		= false;
	protected $check_external_rights 	= true;
	
	//	Define user-level
	protected $access_level 	= '';
	const LEVEL_SYSTEM			= 'system';
	
	//	Omit user auth table relations
	protected $omit_user_auth	= false;
	
	//	System operations
	protected $system 			= false;
	
	//	Show SQL queries
	protected $debug 			= false;
	
	//	Pass parameters to post class
	protected $post_vars 		= [];
	
	//	Use group clause on get
	protected $default_group 	= true;
	
	//	Omit fields
	protected $omit_fields 		= [];
	
	//	Test SQL queries (no requests sent to SQL server)
	protected $test 			= false;
	
	//	Use table constants in delegate classes
	protected $use_table_const 	= true;
	
	protected $table;
	protected $method;
	
	protected $input;
	protected $output;
	protected $user_inputs 		= [];
	
	protected $Map;
	protected $table_short 		= [];
	
	protected $Table;
	protected $Model;
	protected $model_class_name;
	
	protected $is_joined 			= false;
	protected $table_outer_joins 	= [];
	protected $table_inner_joins 	= [];
	protected $select_translate 	= [];
	
	protected $sql 				= '';
	protected $sql_data 		= [];
	
	const METHOD_GET			= 'get';
	const METHOD_PUT 			= 'put';
	const METHOD_UPDATE 		= 'update';
	const METHOD_INSERT 		= 'insert';
	const METHOD_DELETE 		= 'delete';
	
	const CLAUSE_SELECT 		= 'select';
	const CLAUSE_FIELD 			= 'field';
	const CLAUSE_WHERE 			= 'where';
	const CLAUSE_ORDER 			= 'order';
	const CLAUSE_GROUP 			= 'group';
	const CLAUSE_HAVING 		= 'having';
	const CLAUSE_LIMIT 			= 'limit';
	
	const FIELD_NULL_TO_BOOL 	= 'NULL_TO_BOOLEAN';
	
	const JOIN_OPTION_TABLE 	= 'table';
	const JOIN_OPTION_JOIN 		= 'join';
	const JOIN_OPTION_GROUP 	= 'group';
	const JOIN_OPTION_WHERE 	= 'where';
	
	public function external(bool $is_raw=false): self{
		$this->external = true;
		$this->get_external_raw = $is_raw;
		
		return $this;
	}
	
	public function debug(): self{
		$this->debug = true;
		
		return $this;
	}
	
	public function system(int $system=1): self{
		$this->system = $system;
		
		return $this;
	}
	
	public function omit_external_rights(): self{
		$this->check_external_rights = false;
		
		return $this;
	}
	
	public function test(){
		$this->test = true;
	}
	
	public function access_level(string $level): self{
		$this->access_level = $level;
		
		return $this;
	}
	
	public function omit_user_auth(): self{
		$this->omit_user_auth = true;
		
		return $this;
	}
	
	public function send_post_vars(Array $vars): self{
		$this->post_vars = $vars;
		
		return $this;
	}
	
	public function omit_fields(Array $fields): self{
		$this->omit_fields = array_flip($fields);
		
		return $this;
	}
	
	public function omit_table_const(): self{
		$this->use_table_const = false;
		
		return $this;
	}
	
	public function get_sql_string(bool $strip_newlines=false): string{
		$sql = $this->sql;
		foreach($this->sql_data as $data){
			$pos = strpos($sql, '?');
			if($pos !== false){
				$sql = substr_replace($sql, $data, $pos, 1);
			}
		}
		
		if($strip_newlines){
			return str_replace("\n", ' ', $sql);
		}
		else{
			return $sql."\n";
		}
	}
	
	public function get_output(): Array{
		return $this->output;
	}
	
	public function get_var(string $name, string $valid=''){
		if(!property_exists(__CLASS__, $name)){
			throw new Error('Invalid variable');
		}
		
		if($valid){
			return $this->$name == $valid;
		}
		else{
			return $this->$name;
		}
	}
	
	protected function parse_where_field(string $field): Array{
		$func 	= null;
		$pos 	= strpos($field, '|');
		if($pos !== false){
			$func 	= substr($field, 0, $pos);
			$field 	= substr($field, $pos + 1);
		}
		
		$operator 	= null;
		$pos 		= strpos($field, ' ');
		if($pos !== false){
			$operator 	= substr($field, $pos + 1);
			$field 		= substr($field, 0, $pos);
		}
		
		return [
			'func'		=> $func,
			'field'		=> $field,
			'operator'	=> $operator
		];
	}
	
	protected function prepare_where(bool $having=false): Array{
		$clause = $having ? self::CLAUSE_HAVING : self::CLAUSE_WHERE;
		
		if(empty($this->output[$clause])){
			return [
				'fields'	=> [],
				'names'		=> []
			];
		}
		
		$parsed = [];
		
		//	Collect user input fields
		if($this->external && !empty($this->input[$clause])){
			$input = $this->input[$clause];
			foreach($input as $field => $value){
				$parsed[$field] = $this->parse_where_field($field);
				$this->user_inputs[$parsed[$field]['field']] = true;
			}
		}
		
		$output = $this->output[$clause];
		
		$operator_in = [
			'in'	=> 'IN (?)',
			'!in'	=> 'NOT IN (?)'
		];
		
		$operator_between = [
			'bt'	=> 'BETWEEN ? AND ?',
			'!bt'	=> 'NOT BETWEEN ? AND ?'
		];
		
		$where 			= [];
		$where_names 	= [];
		
		foreach($output as $field => $value){
			$parse_where = $parsed[$field] ?? $this->parse_where_field($field);
			
			$field = $this->get_translate_field($parse_where['field'], self::CLAUSE_WHERE);
			
			//	Omit user auth table relation
			if($this->omit_user_auth && $field['table'] == $this->Table->table_user_auth){
				continue;
			}
			
			if(is_null($value)){
				$where[] = [
					'clause'		=> '$ '.($parse_where['operator'] == '!' ? 'IS NOT' : 'IS').' NULL',
					'table_field'	=> $field['table_field'],
					'field'			=> $field['field']
				];
			}
			elseif(isset($operator_in[$parse_where['operator']])){
				$where[] = [
					'clause'		=> '$ '.str_replace('?', substr(str_repeat('?,', count($value)), 0, -1), $operator_in[$parse_where['operator']]),
					'table_field'	=> $field['table_field'],
					'field'			=> $field['field']
				];
				$where_names = array_merge($where_names, $value);
			}
			elseif(isset($operator_between[$parse_where['operator']])){
				$where[] = [
					'clause'		=> '$ '.$operator_between[$parse_where['operator']],
					'table_field'	=> $field['table_field'],
					'field'			=> $field['field']
				];
				$pos = strpos($value, ',');
				$where_names[] = substr($value, 0, $pos);
				$where_names[] = substr($value, $pos + 1);
			}
			elseif($parse_where['operator'] == '!?'){
				$where[] = [
					'clause'		=> 'NOT($ <=> ?)',
					'table_field'	=> $field['table_field'],
					'field'			=> $field['field']
				];
				$where_names[] = $value;
			}
			elseif($parse_where['func']){
				$where[] = [
					'clause'		=> $parse_where['func'].'($)'.$parse_where['operator'].'=?',
					'table_field'	=> $field['table_field'],
					'field'			=> $field['field']
				];
				$where_names[] = $value;
			}
			else{
				$where[] = [
					'clause'		=> '$'.$parse_where['operator'].'=?',
					'table_field'	=> $field['table_field'],
					'field'			=> $field['field']
				];
				$where_names[] = $value;
			}
		}
		
		return [
			'fields'	=> $where,
			'names'		=> $where_names
		];
	}
	
	protected function translate_clause($clause, string $sep=','): string{
		if(!$clause){
			return '';
		}
		
		$return = [];
		$value_key = $this->is_joined ? 'table_field' : 'field';
		foreach($clause as $key => $value){
			$return[] = str_replace('$', $value[$value_key], $value['clause']);
		}
		
		return implode($sep, $return);
	}
	
	protected function override(string $clause, Array $override, bool $is_multiple=false){
		$has_clause = empty($this->output[$clause]) ? false : true;
		
		//	Translate external field constants
		if($this->external && $has_clause && $clause == self::CLAUSE_WHERE){
			$external_field_const = $this->model_class_name::get_external_field_const();
			
			if($external_field_const){
				foreach($this->output[$clause] as $key => $value){
					if(isset($external_field_const[$key])){
						$this->output[$clause][$key] = array_search($value, $external_field_const[$key]);
					}
				}
			}
		}
		
		if($override){
			if($has_clause){
				if($is_multiple){
					foreach($this->output[$clause] as $k => $v){
						$this->output[$clause][$k] = array_merge($this->output[$clause][$k], $override);
					}
				}
				else{
					$this->output[$clause] = array_merge($this->output[$clause], $override);
				}
			}
			else{
				$this->output[$clause] = $override;
			}
		}
	}
	
	protected function get_predata(string $table, array $select, bool $is_get_lock=false): array{
		$Data = new Get;
		if($this->access_level){
			$Data->access_level($this->access_level);
		}
		if(!$this->use_table_const){
			$Data->omit_table_const();
		}
		if($this->omit_user_auth){
			$Data->omit_user_auth();
		}
		if($is_get_lock){
			$Data->get_lock();
		}
		if(!$row = $Data->exec($table, [
			self::CLAUSE_SELECT => $select,
			self::CLAUSE_WHERE => $this->output[self::CLAUSE_WHERE],
			self::CLAUSE_LIMIT => 1
		])->fetch()){
			throw new Error_input(null, 'ENTRY_NOT_FOUND');
		}
		
		return $row;
	}
	
	protected function update_predata(array &$predata, array &$row_predata): array{
		$predata = array_flip($predata);
		foreach($predata as $k => &$value){
			$value = $row_predata[$k];
		}
		
		return $predata;
	}
	
	protected function load_class(string $delegate){
		$table = $delegate ?: $this->table;
		
		$class_name = self::get_class('table', $table);
		$this->Table = new $class_name($this);
		
		if($this->external){
			$this->model_class_name = self::get_class('model', $table);
			
			if($this->method != self::METHOD_GET){
				$this->Model = new $this->model_class_name;
				$this->Model->set_data($this);
			}
		}
	}
	
	protected function load_pre_class(string $table, int $id){
		$class_name = self::get_class('pre', $table);
		new $class_name($id, $table, $this);
	}
	
	protected function load_post_class(string $table, int $id, Array $predata=[]){
		$class_name = self::get_class('post', $table);
		new $class_name($id, $table, $this, $this->Model, $predata);
	}
	
	protected function prepare_table(): string{
		$delegate = '';
		
		$this->Map 			= self::get_map();
		$this->table_short 	= $this->Map->get_table_short();
		
		if(!$table = $this->Map->get($this->table)){
			if($this->external){
				throw new Error_input(null, 'API_REQUEST_TABLE_INVALID', [
					'table' => $this->table
				]);
			}
			else{
				throw new Error('Map table not found: '.$this->table);
			}
		}
		
		//	Get table delegate
		if(!empty($table['delegate'])){
			$delegate 		= $this->table;
			$this->table 	= $table['delegate'];
		}
		//	Return error if table is restricted
		elseif(empty($table['access'])){
			if($this->external){
				throw new Error_input(null, 'API_REQUEST_TABLE_INVALID', [
					'table' => $this->table
				]);
			}
			else{
				throw new Error('Table restricted: '.$this->table);
			}
		}
		
		return $delegate ?: $this->table;
	}
	
	protected function get_translate_field(string $field, string $clause, string &$select_trans_field=''): Array{
		if(!isset($this->Table->field_translate[$field])){
			if($this->external){
				throw new Error_input(null, 'DATA_FIELDS_INVALID', [
					'fields' => $field
				]);
			}
			else{
				throw new Error('Field translation missing in '.$this->table.' for field: '.$field);
			}
		}
		
		$field_translate 	= $this->Table->field_translate[$field];
		
		$trans_field 		= $field_translate[1];
		$table 				= $field_translate[0];
		
		//	Return error if table short is not defined
		if(!isset($this->table_short[$table])){
			throw new Error('Table short missing for table: '.$table);
		}
		
		$table_short = $this->table_short[$table];
		
		if($clause == self::CLAUSE_SELECT){
			if(!$select_trans_field && $field != $trans_field){
				$select_trans_field = $field;
			}
		}
		
		//	Join tables
		if($table != $this->table){
			$this->join_table($table, $table_short);
		}
		
		if(isset($field_translate[2])){
			switch($field_translate[2]){
				case self::FIELD_NULL_TO_BOOL:
					$table_field = 'IF('.$table_short.'.'.$trans_field.' IS NULL,0,1)';
					$trans_field = 'IF('.$trans_field.' IS NULL,0,1)';
					break;
				
				default:
					$table_field = $table_short.'.'.$trans_field;
			}
		}
		else{
			$table_field = $table_short.'.'.$trans_field;
		}
		
		return [
			'table'			=> $table,
			'table_field'	=> $table_field,
			'field'			=> $trans_field
		];
	}
	
	private function join_table(string $table, string $table_short=''){
		if($table_short){
			//	Add table relation dependencies
			if(isset($this->Table->table_relation_dependencies[$table])){
				foreach($this->Table->table_relation_dependencies[$table] as $dependency){
					if(!isset($this->table_outer_joins[$dependency]) && !isset($this->table_inner_joins[$dependency])){
						$this->join_table($dependency);
					}
				}
			}
		}
		else{
			//	Return error if table short is not defined
			if(!isset($this->table_short[$table])){
				throw new Error('Table short missing for table: '.$table);
			}
			
			$table_short = $this->table_short[$table];
		}
		
		$table_relations 	= $this->Table->table_relations[$table];
		$join_type 			= $table_relations[0];
		$join_var 			= $join_type == 'INNER JOIN' ? 'table_inner_joins' : 'table_outer_joins';
		
		if(!isset($this->$join_var[$table])){
			$this->is_joined = true;
			
			//	Omit user auth table relation
			if($this->omit_user_auth && $table == $this->Table->table_user_auth){
				return;
			}
			
			$options = $table_relations[3] ?? [];
			
			$clause = $join_type.' `'.($options[self::JOIN_OPTION_TABLE] ?? $table).'` '.$table_short.' ON ';
			
			//	Check if join where
			if(isset($options[self::JOIN_OPTION_WHERE]) && !empty($this->output['where'][$options[self::JOIN_OPTION_WHERE]['input']])){
				$clause .= $table_short.'.'.$options[self::JOIN_OPTION_WHERE]['join'][1].'='.$this->table_short[$this->table].'.'.$options[self::JOIN_OPTION_WHERE]['join'][0];
			}
			else{
				//	Add first join clause
				$clause .= $table_short.'.'.$table_relations[2].'=';
				$pos = strpos($table_relations[1], '.');
				if($pos === false){
					$clause .= $this->table_short[$this->table].'.'.$table_relations[1];
				}
				else{
					$clause .= $this->table_short[substr($table_relations[1], 0, $pos)].'.'.substr($table_relations[1], $pos + 1);
				}
				
				//	Add second join clause
				if(isset($options[self::JOIN_OPTION_JOIN])){
					$clause .= ' && '.$table_short.'.'.$options[self::JOIN_OPTION_JOIN][0].'=';
					if($options[self::JOIN_OPTION_JOIN][1][0] == '='){
						$clause .= substr($options[self::JOIN_OPTION_JOIN][1], 1);
					}
					else{
						$pos = strpos($options[self::JOIN_OPTION_JOIN][1], '.');
						if($pos === false){
							$clause .= $this->table_short[$this->table].'.'.$options[self::JOIN_OPTION_JOIN][1];
						}
						else{
							$clause .= $this->table_short[substr($options[self::JOIN_OPTION_JOIN][1], 0, $pos)].'.'.substr($options[self::JOIN_OPTION_JOIN][1], $pos + 1);
						}
					}
				}
			}
			
			$this->$join_var[$table] = $clause;
			
			//	Add clause dependencies
			if($this->default_group && isset($options[self::JOIN_OPTION_GROUP])){
				if(isset($this->output['group'])){
					$this->output['group'] = array_merge($this->output['group'], $options[self::JOIN_OPTION_GROUP]);
				}
				else{
					$this->output['group'] = $options[self::JOIN_OPTION_GROUP];
				}
			}
		}
	}
	
	protected function filter_omitted_fields(){
		if(!empty($this->omit_fields)){
			foreach(array_keys($this->omit_fields) as $omit_field){
				$key = array_search($omit_field, $this->output[self::CLAUSE_SELECT]);
				if($key !== false){
					unset($this->output[self::CLAUSE_SELECT][$key]);
				}
			}
		}
	}
}
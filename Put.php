<?php

namespace dbdata;

class Put extends Data {
	private $where 					= [];
	
	private $ignore_duplicate 		= false;
	private $update_duplicate 		= false;
	
	private $external_throw_errors 	= true;
	private $external_errors 		= [];
	
	public function ignore_duplicate(): self{
		$this->ignore_duplicate = true;
		
		return $this;
	}
	
	public function update_duplicate(): self{
		$this->update_duplicate = true;
		
		return $this;
	}
	
	public function where(Array $where): self{
		$this->where = $where;
		
		return $this;
	}
	
	public function return_errors(): self{
		$this->external_throw_errors = false;
		
		return $this;
	}
	
	public function get_errors(): Array{
		return $this->external_errors;
	}
	
	public function exec(string $table, int $id, Array $input, bool $is_multiple=false){
		$this->table 	= $table;
		$this->input 	= $input;
		
		if($this->where && !$id){
			$this->method 	= self::METHOD_UPDATE;
		}
		else{
			$this->method 	= $id || isset($this->input['id']) ? self::METHOD_UPDATE : self::METHOD_INSERT;
		}
		
		try{
			if($this->where){
				if($this->method == self::METHOD_INSERT){
					throw new Error('Where clause on insert not allowed');
				}
				elseif($id){
					throw new Error('Where clause on update not allowed compined with ID');
				}
				elseif($this->external){
					throw new Error('Where clause on update not allowed in external requests');
				}
			}
			elseif($is_multiple){
				if($this->method == self::METHOD_UPDATE){
					throw new Error('Multiple put not allowed on update');
				}
				elseif($this->external){
					throw new Error('Multiple put not allowed in external requests');
				}
				elseif(!$this->is_array_sequential($this->input)){
					throw new Error('Multiple put input not sequential');
				}
				elseif(!$this->validate_multiple_input()){
					throw new Error('Multiple put input not valid');
				}
				elseif($this->update_duplicate){
					throw new Error('Multiple put not allowed with update duplicate');
				}
			}
			elseif($this->update_duplicate){
				if($this->method == self::METHOD_UPDATE){
					throw new Error('Update duplicate not allowed on update');
				}
				elseif($this->external){
					throw new Error('Update duplicate not allowed in external requests');
				}
				elseif($this->ignore_duplicate){
					throw new Error('Update duplicate not allowed with ignore duplicate');
				}
			}
			elseif($this->ignore_duplicate){
				if($this->external){
					throw new Error('Ignore duplicate not allowed in external requests');
				}
			}
			
			$table = $this->prepare_table();
			
			$post_predata = [];
			
			//	Load table class
			$this->load_class($table);
			
			//	Check external rights
			if($this->external && $this->check_external_rights){
				$this->model_class_name::check_external_rights($this->method);
			}
			
			$this->output = [
				self::CLAUSE_FIELD => $this->input,
				self::CLAUSE_WHERE => []
			];
			
			//	If update override WHERE clause
			if($this->method == self::METHOD_UPDATE){
				if($this->where){
					$this->output[self::CLAUSE_WHERE] = $this->where;
				}
				else{
					$this->set_id($id);
				}
				
				//	Override WHERE clause with environment determined values
				$this->override(self::CLAUSE_WHERE, $this->Table->where_override());
				
				//	Check if the entry exists
				if(!$this->test && !$this->where){
					if($this->external){
						$predata_fields = $this->Model->get_update_predata();
						$predata_select = $predata_fields['select'];
					}
					else{
						$predata_select = ['id'];
					}
					
					//	Get predata
					$row_predata = $this->get_predata($table, $predata_select, true);
					
					if($this->external){
						$this->Model->set_predata($this->update_predata($predata_fields['update'], $row_predata));
						
						$post_predata = $predata_fields['post'] ? $this->update_predata($predata_fields['post'], $row_predata) : [];
					}
				}
			}
			
			if($this->external){
				$require_fields = $this->Table->external_fields[self::METHOD_PUT] ?? $this->Table->external_fields[$this->method];
				
				//	Return error if required fields are missing
				if(!$this->input){
					throw new Error_input(null, 'DATA_FIELDS_REQUIRE', [
						'fields' => implode(', ', array_keys($require_fields))
					]);
				}
				elseif($missing_fields = array_diff_key($require_fields, $this->input)){
					throw new Error_input(null, 'DATA_FIELDS_REQUIRE', [
						'fields' => implode(', ', array_keys($missing_fields))
					]);
				}
				
				//	Return error if invalid fields are received
				if($invalid_fields = array_diff_key($this->input, $require_fields)){
					throw new Error_input(null, 'DATA_FIELDS_INVALID', [
						'fields' => implode(', ', array_keys($invalid_fields))
					]);
				}
				
				//	Put required fields into class model and return errors
				$this->Model->set_input($this->method, $table, $this->output[self::CLAUSE_FIELD], $require_fields);
				$this->Model->put($this->Table->table_const);
				
				if($deferred_sub_exec = $this->Model->get_deferred_sub_exec()){
					if($this->method == self::METHOD_UPDATE && $this->Model->get_errors()){
						$this->exec_deferred_sub($deferred_sub_exec);
					}
				}
				
				if($this->external_throw_errors){
					$this->Model->throw_errors();
				}
				else{
					if($this->external_errors = $this->Model->get_errors()){
						return;
					}
				}
				$this->Model->put_apply_multiplier();
				
				$this->output[self::CLAUSE_FIELD] = get_public_object_vars($this->Model);
			}
			
			//	Override FIELD clause with environment determined values
			$this->override(self::CLAUSE_FIELD, $this->Table->field_override(), $is_multiple);
			
			//	Prepare clauses for query
			if($is_multiple){
				$field = $this->prepare_field_multiple();
			}
			else{
				$field = $this->prepare_field();
			}
			$where = $this->prepare_where();
			
			//	Translate table fields
			if($is_multiple){
				$str_field = $this->translate_clause_multiple($field);
			}
			else{
				$str_field = $this->translate_clause($field['fields']);
			}
			$str_where = $this->translate_clause($where['fields'], ' && ');
			
			$this->sql_data = empty($where['names']) ? $field['names'] : array_merge($field['names'], $where['names']);
			
			$on_duplicate = '';
			if($this->ignore_duplicate){
				$on_duplicate .= "\nON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)";
			}
			elseif($this->update_duplicate){
				$on_duplicate .= "\nON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), $str_field";
				$this->sql_data = array_merge($this->sql_data, $field['names']);
			}
			
			//	Create SQL query
			if($is_multiple){
				$this->sql = "INSERT `$this->table`\n$str_field".$on_duplicate;
			}
			elseif($this->method == self::METHOD_INSERT){
				$this->sql = "INSERT `$this->table`\nSET $str_field".$on_duplicate;
			}
			else{
				$this->sql = "UPDATE `$this->table`".($this->is_joined ? ' '.$this->table_short[$this->table] : '')
					.($this->table_inner_joins ? "\n".implode("\n", $this->table_inner_joins) : '')
					.($this->table_outer_joins ? "\n".implode("\n", $this->table_outer_joins) : '')
					."\nSET $str_field"
					."\nWHERE $str_where";
			}
			
			if($this->debug){
				echo $this->get_sql_string();
			}
			
			if(self::$debug_sql_log == 2){
				self::log_debug($this->get_sql_string(true));
			}
			
			if(!$this->test){
				$dbh = self::get_dbh();
				$sth = $dbh->prepare($this->sql);
				$sth->execute($this->sql_data);
				self::$num_put_queries++;
				
				if(!$this->where){
					if(!$return_id = empty($this->output[self::CLAUSE_WHERE]['id']) ? $dbh->lastInsertId() : $this->output[self::CLAUSE_WHERE]['id']){
						throw new Error_input(null, 'ENTRY_NOT_FOUND');
					}
				}
				
				if($this->external){
					//	Load post class
					if($this->Model->has_post_class()){
						$this->load_post_class($table, $return_id, $post_predata);
					}
					
					if($deferred_sub_exec){
						$this->exec_deferred_sub($deferred_sub_exec, true, $return_id);
					}
					
					\Webhook::exec_put($this->method, $table, empty($this->output[self::CLAUSE_WHERE]['id']) ? $return_id : $this->output[self::CLAUSE_WHERE]['id']);
				}
				
				if(!$this->where){
					return (int)$return_id;
				}
			}
		}
		catch(\PDOException $e){
			throw new Error_db($e->getMessage().'; SQL: '.$this->get_sql_string(true), isset($dbh) ? (int)$dbh->errorInfo()[1] : 0);
		}
	}
	
	private function exec_deferred_sub(Array $deferred_sub_exec, bool $throw_errors=false, int $id=0){
		if($this->method == self::METHOD_INSERT && $id){
			if($key = array_search('%id%', $deferred_sub_exec['fields'])){
				$deferred_sub_exec['fields'][$key] = $id;
			}
		}
		
		$deferred_sub_exec['Data']->return_errors();
		if($this->omit_user_auth){
			$deferred_sub_exec['Data']->omit_user_auth();
		}
		$deferred_sub_exec['Data']->exec($deferred_sub_exec['table'], $deferred_sub_exec['id'], $deferred_sub_exec['fields']);
		
		if($errors = $deferred_sub_exec['Data']->get_errors()){
			foreach($errors as $field => $error){
				$this->Model->push_error($deferred_sub_exec['trans'][$field], $error);
			}
		}
		
		if($throw_errors){
			$this->Model->throw_errors();
		}
	}
	
	private function translate_clause_multiple($clause): string{
		$value_key = $this->is_joined ? 'table_field' : 'field';
		
		$values = [];
		$columns = 0;
		foreach($clause['fields'] as $key => $value){
			$values[] = $value[$value_key];
			$columns++;
		}
		
		$return = [];
		for($i=0; $i<$clause['rows']; $i++){
			$rows = [];
			for($e=0; $e<$columns; $e++){
				$rows[] = '?';
			}
			$return[] = '('.implode(',', $rows).')';
		}
		
		return '('.implode(',', $values).")\nVALUES ".implode(',', $return);
	}
	
	private function prepare_field_multiple(){
		$column 		= [];
		$column_names 	= [];
		foreach($this->output[self::CLAUSE_FIELD][0] as $field => $value){
			$field = $this->get_translate_field($field, self::CLAUSE_FIELD);
			
			$column[] = [
				'table_field'	=> $field['table_field'],
				'field'			=> $field['field']
			];
		}
		
		foreach($this->output[self::CLAUSE_FIELD] as $row){
			foreach($row as $value){
				$column_names[] = $value;
			}
		}
		
		return [
			'fields'	=> $column,
			'names'		=> $column_names,
			'rows'		=> count($this->output[self::CLAUSE_FIELD])
		];
	}
	
	private function prepare_field(){
		//	Collect user input fields
		if($this->external && $this->input){
			foreach($this->input as $field => $value){
				$this->user_inputs[$field] = true;
			}
		}
		
		$column 		= [];
		$column_names 	= [];
		if(!empty($this->output[self::CLAUSE_FIELD])){
			foreach($this->output[self::CLAUSE_FIELD] as $field => $value){
				$operator 	= null;
				$prefix 	= '';
				$pos = strpos($field, ' ');
				if($pos !== false){
					$operator 	= substr($field, $pos + 1);
					$field 		= substr($field, 0, $pos);
					
					if($operator == '+' || $operator == '-'){
						$prefix = '$'.$operator;
					}
				}
				
				$field = $this->get_translate_field($field, self::CLAUSE_FIELD);
				
				$column[] = [
					'clause'		=> '$='.$prefix.'?',
					'table_field'	=> $field['table_field'],
					'field'			=> $field['field']
				];
				$column_names[] = $value;
			}
			
			return [
				'fields'	=> $column,
				'names'		=> $column_names
			];
		}
	}
	
	private function set_id(int $id){
		if(!$id && isset($this->input['id'])){
			$id = $this->input['id'];
		}
		
		$this->output[self::CLAUSE_WHERE]['id'] = $id;
		unset($this->input['id']);
		unset($this->output[self::CLAUSE_FIELD]['id']);
		
		if(!$id){
			throw new Error_input(null, 'DATA_FIELD_EMPTY', [
				'field' => 'ID'
			]);
		}
	}
	
	private function validate_multiple_input(): bool{
		$first_input = array_keys($this->input[0]);
		foreach(array_slice($this->input, 1) as $input){
			if($first_input !== array_keys($input)){
				return false;
			}
		}
		
		return true;
	}
	
	private function is_array_sequential(array $arr): bool{
		return array_keys($arr) === range(0, count($arr) - 1) ? true : false;
	}
}
<?php

namespace dbdata;

class Delete extends Data {
	private $where = [];
	
	public function where(Array $where): self{
		$this->where = $where;
		
		return $this;
	}
	
	public function exec(string $table, int $id, Array $post_data=[]){
		$this->table 	= $table;
		$this->method 	= self::METHOD_DELETE;
		
		try{
			if($this->where){
				if($id){
					throw new Error('Where clause on delete not allowed compined with ID');
				}
				elseif($this->external){
					throw new Error('Where clause on delete not allowed in external requests');
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
				self::CLAUSE_WHERE => [
					'id' => $id
				]
			];
			
			if($this->where){
				$this->output[self::CLAUSE_WHERE] = $this->where;
			}
			//	Return error if id is empty
			elseif(!$id){
				throw new Error_input(null, 'DATA_FIELD_EMPTY', [
					'field' => 'ID'
				]);
			}
			
			//	Override WHERE clause with environment determined values
			$this->override(self::CLAUSE_WHERE, $this->Table->where_override());
			
			if($this->external){
				$predata_fields = $this->Model->get_delete_predata();
				
				//	Get predata
				$row_predata = $this->get_predata($table, $predata_fields['select']);
				
				$this->Model->set_predata($this->update_predata($predata_fields['delete'], $row_predata));
				
				$post_predata = $predata_fields['post'] ? $this->update_predata($predata_fields['post'], $row_predata) : [];
			}
			
			$delete_entry = true;
			if(!$this->test && $this->external){
				$this->Model->set_input($this->method, $table);
				
				if($delete_entry = $this->Model->delete($this->output[self::CLAUSE_WHERE], $post_data)){
					//	Load pre class
					if(!$this->test && $this->Model->has_pre_class()){
						$this->load_pre_class($table, $id);
					}
				}
			}
			
			if($delete_entry){
				//	Prepare clauses for query
				$where = $this->prepare_where();
				
				//	Translate table fields
				$str_where = $this->translate_clause($where['fields'], ' && ');
				
				//	Create SQL query
				if($this->is_joined){
					$this->sql = "DELETE ".$this->table_short[$this->table]
						."\nFROM `$this->table` ".$this->table_short[$this->table]
						.($this->table_inner_joins ? "\n".implode("\n", $this->table_inner_joins) : '')
						.($this->table_outer_joins ? "\n".implode("\n", $this->table_outer_joins) : '')
						."\nWHERE $str_where";
				}
				else{
					$this->sql = "DELETE FROM `$this->table` WHERE $str_where";
				}
				
				$this->sql_data = $where['names'];
				
				if($this->debug){
					echo $this->get_sql_string();
				}
				
				if(self::$debug_sql_log == 2){
					self::log_debug($this->get_sql_string(true));
				}
				
				if(!$this->test){
					if($this->external){
						\Webhook::prepare_delete($table, $this->output[self::CLAUSE_WHERE]['id']);
					}
					
					$dbh = self::get_dbh();
					$sth = $dbh->prepare($this->sql);
					$sth->execute($this->sql_data);
					self::$num_delete_queries++;
					
					if(!$this->where && !$sth->rowCount()){
						throw new Error_input(null, 'ENTRY_NOT_FOUND');
					}
					
					if($this->external){
						//	Load post class
						if($this->Model->has_post_class()){
							$this->load_post_class($table, $this->output[self::CLAUSE_WHERE]['id'], $post_predata);
						}
						
						\Webhook::exec_delete($table, $this->output[self::CLAUSE_WHERE]['id']);
					}
				}
			}
			
			return true;
		}
		catch(\PDOException $e){
			throw new Error_db($e->getMessage().'; SQL: '.$this->get_sql_string(true), isset($dbh) ? $dbh->errorInfo()[1] : 0);
		}
	}
}
<?php

namespace dbdata;

abstract class Table {
	//	Table constants
	public $table_const = [];
	
	//	Default get clauses if none is set
	public $input_default = [];
	
	//	Translate fields
	public $field_translate = [];
	
	//	Table relations
	public $table_relations = [];
	
	//	Table relation dependencies
	public $table_relation_dependencies = [];
	
	//	Table user auth
	public $table_user_auth = '';
	
	//	Fields accessible from external requests
	public $external_fields = [];
	
	//	External fields where
	public $external_field_where = [];
	
	//	External fields order
	public $external_field_order = [];
	
	//	External fields like
	public $external_field_like = [];
	
	protected $Data;
	
	public function __construct(Data $Data=null){
		$this->Data = $Data;
	}
	
	abstract public function field_override(): Array;
	
	abstract public function where_override(): Array;
	
	protected function override(Array $override=[], Array $extend=[], bool $where_clause=true, bool $use_table_const=true): Array{
		$return = [];
		
		//	Override fields
		if($where_clause || (!$where_clause && $this->Data->get_var('method', Data::METHOD_INSERT))){
			//	Extend
			if($extend){
				$return = array_merge($return, $extend);
			}
			
			//	Override environment determined variables
			foreach($override as $key => $value){
				$return[$key] = \Env::get($value);
			}
			
			//	Override table constants
			if($use_table_const && $this->table_const && $this->Data->get_var('use_table_const')){
				$return = array_merge($return, $this->table_const);
			}
		}
		
		return $return;
	}
}
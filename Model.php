<?php

namespace dbdata;

abstract class Model extends Input {
	protected $Data;
	protected $_method;
	protected $_table;
	protected $_fields;
	
	protected $_predata 		= [];
	protected $_postdata 		= [];
	
	protected $_deferred_sub_exec 		= [];
	
	protected $_update_predata 			= [];
	protected $_delete_predata 			= [];
	
	//	Pre class
	protected $_pre_class 				= false;
	
	//	Post class
	protected $_post_class 				= false;
	protected $_post_update_predata 	= [];
	protected $_post_delete_predata 	= [];
	
	//	Access methods from external calls
	static protected $_get_external 		= false;
	static protected $_insert_external 		= false;
	static protected $_update_external 		= false;
	static protected $_delete_external 		= false;
	
	static protected $_get_admin_external 		= false;
	static protected $_insert_admin_external 	= false;
	static protected $_update_admin_external 	= false;
	static protected $_delete_admin_external 	= false;
	
	final public function __construct(string $method='', bool $get_children=false, Array $select_translate=[], bool $get_external_raw=false, bool $use_multiplier=true){
		if($method == Data::METHOD_GET){
			//	Translate external fields constant
			if(static::$_external_field_const){
				$this->translate_external_field_const();
			}
			
			if(!$get_external_raw){
				$this->get($get_children);
			}
			
			$this->translate_null_to_string();
			
			//	Apply multiplier
			if($use_multiplier && static::$_external_field_int_multiplier){
				$this->get_unapply_multiplier();
			}
			
			//	Translate select clause fields
			foreach($select_translate as $key => $translate){
				if(isset($this->$key)){
					$this->$translate = $this->$key;
					unset($this->$key);
				}
			}
		}
	}
	
	abstract protected function get(bool $get_children);
	
	abstract public function put(Array $table_const);
	
	abstract public function delete(Array $where, Array $post_data=[]): bool;
	
	static public function get_external_methods(): array{
		return [
			'get'		=> static::$_get_external,
			'insert'	=> static::$_insert_external,
			'update'	=> static::$_update_external,
			'delete'	=> static::$_delete_external
		];
	}
	
	static public function check_external_rights(string $method){
		if(!PHP_CLI && ADMIN){
			$var = '_'.$method.'_admin_external';
		}
		else{
			$var = '_'.$method.'_external';
		}
		
		if(empty(static::$$var)){
			throw new Error_input(null, 'API_REQUEST_METHOD_INVALID', [
				'method' => $method
			]);
		}
	}
	
	static public function get_external_field_const(): Array{
		return static::$_external_field_const;
	}
	
	public function set_input(string $method, string $table, array $input=[], array $fields= []){
		$this->_method 	= $method;
		$this->_table 	= $table;
		$this->_input 	= $input;
		$this->_fields 	= $fields;
	}
	
	public function set_data(Data $Data){
		$this->Data = $Data;
	}
	
	public function has_pre_class(): bool{
		return $this->_pre_class;
	}
	
	public function has_post_class(): bool{
		return $this->_post_class;
	}
	
	public function get_update_predata(): Array{
		$update = $this->_update_predata;
		$update[] = 'id';
		
		return [
			'update'	=> $update,
			'post'		=> $this->_post_update_predata,
			'select'	=> array_unique(array_merge($update, $this->_post_update_predata))
		];
	}
	
	public function get_delete_predata(): Array{
		$delete = $this->_delete_predata;
		$delete[] = 'id';
		
		return [
			'delete'	=> $delete,
			'post'		=> $this->_post_delete_predata,
			'select'	=> array_unique(array_merge($delete, $this->_post_delete_predata))
		];
	}
	
	public function set_predata(array $predata){
		$this->_predata = $predata;
	}
	
	public function get_postdata(): Array{
		return $this->_postdata;
	}
	
	private function translate_external_field_const(){
		foreach(static::$_external_field_const as $field => $v){
			if(isset($this->$field)){
				$this->$field = static::$_external_field_const[$field][$this->$field];
			}
		}
	}
	
	private function translate_null_to_string(){
		foreach((new \ReflectionObject($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop){
			$key = $prop->getName();
			if(is_null($this->$key)){
				$this->$key = '';
			}
		}
	}
	
	private function get_unapply_multiplier(){
		if(static::$_external_field_int_multiplier){
			foreach(static::$_external_field_int_multiplier as $field => $multiply){
				if(isset($this->$field) && $this->$field != ''){
					$this->$field = (string)((float)$this->$field / $multiply);
				}
			}
		}
	}
	
	public function input_unapply_multiplier(array &$input){
		if(static::$_external_field_int_multiplier){
			foreach(static::$_external_field_int_multiplier as $field => $multiply){
				if(isset($input[$field])){
					$input[$field] = (string)((float)$input[$field] / $multiply);
				}
			}
		}
	}
	
	public function field_unapply_multiplier(string $field, float $value): float{
		return $value / static::$_external_field_int_multiplier[$field];
	}
	
	public function field_apply_multiplier(string $field, float $value=null): int{
		return round(($value ?? $this->$field) * static::$_external_field_int_multiplier[$field]);
	}
	
	public function get_deferred_sub_exec(): Array{
		return $this->_deferred_sub_exec;
	}
	
	public function set_deferred_sub_exec(string $table, Array $trans, string $relation_field, string $id_field){
		$input 	= [];
		$set 	= false;
		foreach($trans as $key => $value){
			if($input[$key] = $this->_input[$value]){
				$set = true;
			}
		}
		
		if($this->_method == Data::METHOD_INSERT){
			if($set){
				$input[$relation_field] = '%id%';
				
				$this->_deferred_sub_exec = [
					'Data'		=> (new Put)->external(),
					'table' 	=> $table,
					'id'		=> 0,
					'fields'	=> $input,
					'trans'		=> $trans
				];
			}
		}
		else{
			if($this->_predata[$id_field]){
				if($set){
					$input[$relation_field] = $this->_predata['id'];
					
					$this->_deferred_sub_exec = [
						'Data'		=> (new Put)->external(),
						'table' 	=> $table,
						'id'		=> $this->_predata[$id_field],
						'fields'	=> $input,
						'trans'		=> $trans
					];
				}
				else{
					(new Delete)->external()->exec($table, $this->_predata[$id_field]);
				}
			}
			elseif($set){
				$input[$relation_field] = $this->_predata['id'];
				
				$this->_deferred_sub_exec = [
					'Data'		=> (new Put)->external(),
					'table' 	=> $table,
					'id'		=> 0,
					'fields'	=> $input,
					'trans'		=> $trans
				];
			}
		}
	}
}
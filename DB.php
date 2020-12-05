<?php

namespace dbdata;

require_once 'Map.php';
require_once 'Data.php';
require_once 'Data_pre.php';
require_once 'Data_post.php';
require_once 'Data_action.php';
require_once 'Table.php';
require_once 'Input.php';
require_once 'Model.php';
require_once 'Action.php';

require_once 'Get.php';
require_once 'Put.php';
require_once 'Delete.php';
require_once 'Query.php';

require_once \Ini::get('path/class').'/Error_input.php';
require_once \Ini::get('path/class').'/Data.php';

class DB {
	static private $connection;
	static private $connections 	= [];
	static private $maps 			= [];
	
	static private $errors 			= [];
	
	static private $int_range 		= [];
	
	static public $num_get_queries 		= 0;
	static public $num_put_queries 		= 0;
	static public $num_delete_queries 	= 0;
	
	private const DBH 			= 'dbh';
	private const TRANSACTION 	= 'transaction';
	
	const TYPE_INTEGER 	= 'int';
	const TYPE_DECIMAL 	= 'decimal';
	const TYPE_ENUM 	= 'enum';
	const TYPE_STRING 	= 'string';
	const TYPE_JSON 	= 'json';
	const TYPE_LIST 	= 'list';
	const TYPE_MAP 		= 'map';
	const TYPE_DATE 	= 'date';
	
	static function init(){
		if(!self::$int_range){
			self::$int_range = [
				'tinyint'	=> bcpow(2, 8),
				'smallint'	=> bcpow(2, 16),
				'mediumint'	=> bcpow(2, 24),
				'int'		=> bcpow(2, 32),
				'bigint'	=> bcpow(2, 64)
			];
		}
	}
	
	static public function connect(string $handle, string $db, string $user, string $pass, int $port=3306, string $host='localhost', string $driver='mysql'){
		if(isset(self::$connections[$handle])){
			throw new Error('DB connection handle already used');
		}
		
		try{
			$dbh = new \PDO($driver.':dbname='.$db.';host='.$host.';port='.$port.';charset=utf8', $user, $pass, [
				\PDO::ATTR_EMULATE_PREPARES		=> false,
				\PDO::ATTR_ERRMODE				=> \PDO::ERRMODE_EXCEPTION,
				\PDO::ATTR_DEFAULT_FETCH_MODE	=> \PDO::FETCH_ASSOC,
				\PDO::ATTR_AUTOCOMMIT			=> true
			]);
			
			self::$connections[$handle] = [
				self::DBH			=> $dbh,
				self::TRANSACTION	=> false
			];
			
			$map_class = ucfirst($handle);
			$file = 'map/'.$handle.'/'.$map_class.'.php';
			if(!include_once $file){
				throw new Error('File missing: '.$file);
			}
			
			$map_class = '\\dbdata\\map\\'.$map_class;
			self::$maps[$handle] = new $map_class;
			
			if(!self::$connection){
				self::use_connection($handle);
			}
			
			//	http://www.tocker.ca/2014/01/24/proposal-to-enable-sql-mode-only-full-group-by-by-default.html
			//	https://dev.mysql.com/doc/refman/5.6/en/group-by-handling.html
			//self::get_dbh()->prepare("SET SESSION sql_mode = CONCAT('ONLY_FULL_GROUP_BY,', (SELECT @@sql_mode))")->execute();
		}
		catch(\PDOException $e){
			throw new \DB_error($e->getMessage());
		}
	}
	
	static public function use_connection(string $handle){
		self::$connection = $handle;
	}
	
	static public function get_dbh(): \PDO{
		if(empty(self::$connections[self::$connection])){
			throw new Error('Invalid DB connection handle');
		}
		else{
			return self::$connections[self::$connection][self::DBH];
		}
	}
	
	static public function get_map(): object{
		return self::$maps[self::$connection];
	}
	
	static public function error(string $message, Array $translate=[], string $field=''){
		if(!isset(self::$errors[self::$connection])){
			self::$errors[self::$connection] = [];
		}
		
		self::$errors[self::$connection][$field] = [
			'message'	=> $message,
			'translate'	=> $translate
		];
	}
	
	static public function check_errors(): bool{
		return empty(self::$errors[self::$connection]) ? false : true;
	}
	
	static public function flush_errors(): Array{
		$errors = self::$errors[self::$connection] ?? [];
		self::$errors[self::$connection] = [];
		
		return $errors;
	}
	
	static public function filter_utf8(string $value, bool $allow_newlines=true): string{
		return preg_replace('/[^[:print:]'.($allow_newlines ? '\n' : '').']/u', '', mb_convert_encoding($value, 'UTF-8'));
	}
	
	static public function value(string $value, string $field='', string $table='', string $field_name='', bool $trim_whitespaces=true, bool $allow_newlines=false): string{
		if($table){
			$field_type 		= self::get_column_type($table, $field);
			$is_db_text_field 	= in_array($field_type['type'], ['char','binary']);
		}
		else{
			$field_type 		= [];
			$is_db_text_field 	= false;
		}
		
		$value = self::filter_utf8($value, $allow_newlines);
		
		//	Trim whitespaces in strings
		if($trim_whitespaces){
			if($allow_newlines){
				$has_newline = strpos($value, "\n") !== false;
				if($has_newline){
					$value = implode("\n", array_map('trim', explode("\n", $value)));
				}
			}
			
			$value = trim($value);
			
			if($allow_newlines && $has_newline && strpos($value, "\n\n\n") !== false){
				$value = preg_replace("/\n{3,}/", "\n\n", $value);
			}
			
			if(strpos($value, '  ') !== false){
				$value = preg_replace('/ +/', ' ', $value);
			}
		}
		
		//	Trim strings if field maxlength is exceeded
		if($is_db_text_field && mb_strlen($value) > $field_type['length']){
			if(API){
				$value = mb_substr($value, 0, $field_type['length']);
			}
			else{
				throw new Error_input($field, 'DATA_FIELD_MAXLENGTH', [
					'field'	=> $field_name,
					'num'	=> $field_type['length']
				]);
			}
		}
		
		return $value;
	}
	
	static public function begin(){
		$dbh = &self::get_dbh_all();
		if(!$dbh[self::TRANSACTION]){
			if(DEBUG_SQL_LOG){
				\Log::debug_sql(\Request::get().' BEGIN');
			}
			
			$dbh[self::DBH]->beginTransaction();
			$dbh[self::TRANSACTION] = true;
		}
	}
	
	static public function commit(){
		$dbh = &self::get_dbh_all();
		if($dbh[self::TRANSACTION]){
			if(DEBUG_SQL_LOG){
				\Log::debug_sql(\Request::get().' COMMIT');
			}
			
			$dbh[self::DBH]->commit();
			$dbh[self::TRANSACTION] = false;
		}
	}
	
	static public function rollback(){
		$dbh = &self::get_dbh_all();
		if($dbh[self::TRANSACTION]){
			if(DEBUG_SQL_LOG){
				\Log::debug_sql(\Request::get().' ROLLBACK');
			}
			
			$dbh[self::DBH]->rollBack();
			$dbh[self::TRANSACTION] = false;
		}
	}
	
	static public function get_int_range(): Array{
		return self::$int_range;
	}
	
	static public function get_column_type(string $table, string $field=''): Array{
		$apc_key = 'DB_COLUMN_'.self::$connection.'_'.$table;
		if(!$db_column = apcu_fetch($apc_key)){
			$db_column = [];
			
			$sql_table = self::get_map()->get_delegates()[$table] ?? $table;
			
			$sth = self::get_dbh()->prepare("SHOW COLUMNS FROM `$sql_table`");
			$sth->execute();
			while($row = $sth->fetch()){
				if(preg_match('/^(varchar|char)\((\d+)\)/', $row['Type'], $match)){
					$db_column[$row['Field']] = [
						'type'		=> 'char',
						'subtype'	=> $match[1],
						'length'	=> $match[2]
					];
				}
				elseif(preg_match('/^(varbinary|binary)\((\d+)\)/', $row['Type'], $match)){
					$db_column[$row['Field']] = [
						'type'		=> 'binary',
						'subtype'	=> $match[1],
						'length'	=> $match[2]
					];
				}
				elseif(preg_match('/^('.implode('|', array_keys(self::$int_range)).')\((\d+)\)(?: (.*))?/', $row['Type'], $match)){
					if(!isset($match[3])){
						$match[3] = null;
					}
					$unsigned = $match[3] == 'unsigned' ? 1 : 0;
					$from = $unsigned ? 0 : self::$int_range[$match[1]] * -1 / 2;
					$db_column[$row['Field']] = [
						'type'		=> self::TYPE_INTEGER,
						'subtype'	=> $match[1],
						'length'	=> $match[2],
						'unsigned'	=> $unsigned,
						'range'		=> [
							'from'	=> $from,
							'to'	=> $from + self::$int_range[$match[1]] - 1
						]
					];
				}
				elseif(preg_match('/^(decimal)\((\d+,\d+)\)(?: (.*))?/', $row['Type'], $match)){
					if(!isset($match[3])){
						$match[3] = null;
					}
					$unsigned = $match[3] == 'unsigned' ? 1 : 0;
					list($length, $dec_length) = explode(',', $match[2]);
					$range = str_pad(9, $length, '9') / str_pad(1, $dec_length+1, '0');
					$db_column[$row['Field']] = [
						'type'		=> self::TYPE_DECIMAL,
						'subtype'	=> $match[1],
						'length'	=> $match[2],
						'unsigned'	=> $unsigned,
						'range'		=> [
							'from'	=> $unsigned ? 0 : $range * -1,
							'to'	=> $range
						]
					];
				}
				else{
					$db_column[$row['Field']] = [
						'type'		=> $row['Type'],
						'subtype'	=> $row['Type']
					];
				}
				
				$db_column[$row['Field']]['not_null'] = $row['Null'] == 'NO' ? true : false;
				$db_column[$row['Field']]['default'] = $row['Default'];
				$db_column[$row['Field']]['extra'] = $row['Extra'];
			}
			
			apcu_store($apc_key, $db_column, 60*10);
		}
		
		if($field){
			return empty($db_column[$field]) ? [] : $db_column[$field];
		}
		else{
			return $db_column;
		}
	}
	
	static public function get_class(string $type, string $name): string{
		$name = ucfirst($name);
		$file = 'map/'.self::$connection.'/'.$type.'/'.$name.'.php';
		
		if(!include_once $file){
			throw new Error('File missing: '.$file);
		}
		
		return '\\dbdata\\'.$type.'\\'.$name;
	}
	
	static public function required_fields(Array $require_fields, Array $input){
		$require_fields = array_flip($require_fields);
		
		//	Return error if invalid fields are received
		if($invalid_fields = array_diff_key($input, $require_fields)){
			throw new \User_error('DATA_FIELDS_INVALID', [
				'fields' => implode(', ', array_keys($invalid_fields))
			]);
		}
		
		//	Return error if required fields are omitted
		if($omit_fields = array_diff_key($require_fields, $input)){
			throw new \User_error('DATA_FIELDS_REQUIRE', [
				'fields' => implode(', ', array_keys($omit_fields))
			]);
		}
	}
	
	static private function &get_dbh_all(): array{
		if(empty(self::$connections[self::$connection])){
			throw new Error('Invalid DB connection handle');
		}
		else{
			return self::$connections[self::$connection];
		}
	}
}

class Error extends \Error {}

class Error_input extends \Error {
	private $field;
	private $translate = [];
	
	public function __construct(string $field, string $message, Array $translate=[]){
		parent::__construct($message);
		$this->field 		= $field;
		$this->translate 	= $translate;
	}
	
	public function push(Input $context){
		$context->push_error($this->field, [
			'message'	=> $this->getMessage(),
			'translate'	=> $this->translate
		]);
	}
}
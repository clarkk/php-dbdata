<?php

namespace dbdata;

class DB {
	static private $connection;
	static private $connections 		= [];
	static private $maps 				= [];
	
	static private $errors 				= [];
	
	static private $int_range 			= [];
	
	static public $num_get_queries 		= 0;
	static public $num_put_queries 		= 0;
	static public $num_delete_queries 	= 0;
	
	private const DBH 					= 'dbh';
	private const TRANSACTION 			= 'transaction';
	private const DB_NAME 				= 'db';
	private const DB_USER 				= 'user';
	private const DB_PASS 				= 'pass';
	
	const OPT_ERR_MAXLENGTH 			= 'opt_err_maxlength';
	
	static protected $options = [
		self::OPT_ERR_MAXLENGTH => true
	];
	
	const TYPE_INTEGER 	= 'int';
	const TYPE_DECIMAL 	= 'decimal';
	const TYPE_ENUM 	= 'enum';
	const TYPE_STRING 	= 'string';
	const TYPE_JSON 	= 'json';
	const TYPE_LIST 	= 'list';
	const TYPE_MAP 		= 'map';
	const TYPE_DATE 	= 'date';
	
	static private $env = [];
	
	static private $map_path;
	
	//	Debug SQL
	static protected $debug_sql_log;
	static private $cid;
	
	static function init(string $map_path=__DIR__.'/maps', array $options=[], int $debug_sql_log=0){
		/*	
		*	Set debug SQL log level (debug_sql_log)
		*	0: log no SQL queries
		*	1: log write SQL queries
		*	2: log read/write SQL queries
		*/
		
		self::$map_path = $map_path;
		
		if($options){
			self::$options = $options + self::$options;
		}
		
		if(self::$debug_sql_log = $debug_sql_log){
			self::$cid = substr(md5(microtime(true)), 0, 6);
			self::log_debug('INITIATED (debug level: '.self::$debug_sql_log.')');
		}
		
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
	
	static public function set_env(string $key, string $value){
		self::$env[$key] = $value;
	}
	
	static public function get_env(string $key): string{
		if(!isset(self::$env[$key])){
			throw new Error("Env invalid key: $key");
		}
		
		return self::$env[$key];
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
				self::TRANSACTION	=> false,
				self::DB_NAME 		=> $db,
				self::DB_USER 		=> $user,
				self::DB_PASS 		=> $pass
			];
			
			//	Load map
			$map_class = ucfirst($handle);
			$file = self::$map_path.'/'.$handle.'/'.$map_class.'.php';
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
			throw new Error_db($e->getMessage(), $e->getCode());
		}
	}
	
	static public function reconnect(){
		$connection = self::$connections[self::$connection];
		unset(self::$connections[self::$connection]);
		
		self::connect(self::$connection, $connection[self::DB_NAME], $connection[self::DB_USER], $connection[self::DB_PASS]);
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
	
	static public function error(?string $field, string $message, Array $translate=[]){
		if(!isset(self::$errors[self::$connection])){
			self::$errors[self::$connection] = [];
		}
		
		self::$errors[self::$connection][$field ?: ''] = [
			'message'	=> $message,
			'translate'	=> $translate
		];
	}
	
	static public function check_errors(): bool{
		return empty(self::$errors[self::$connection]) ? false : true;
	}
	
	static public function flush_errors(bool $translate=false): array{
		$errors = self::$errors[self::$connection] ?? [];
		self::$errors[self::$connection] = [];
		
		if($translate){
			foreach($errors as &$error){
				$error = \dbdata\Lang::get_error($error['message'], $error['translate']);
			}
		}
		
		return $errors;
	}
	
	static public function trim_value($value, string $table, string $field){
		$field_type = self::get_column_type($table, $field);
		
		switch($field_type['type']){
			case 'char':
				return $value ? mb_substr($value, 0, $field_type['length']) : '';
			
			case self::TYPE_INTEGER:
			case self::TYPE_DECIMAL:
				return ($value < $field_type['range']['from'] || $value > $field_type['range']['to']) ? 0 : $value;
		}
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
		
		$value = \Str\Str::filter_utf8($value, $allow_newlines ? 'n' : '');
		
		//	Trim whitespaces in strings
		if($trim_whitespaces){
			$value = \Str\Str::trim($value, $allow_newlines);
		}
		
		//	Trim strings if field maxlength is exceeded
		if($is_db_text_field && mb_strlen($value) > $field_type['length']){
			if(self::$options[self::OPT_ERR_MAXLENGTH]){
				throw new Error_input($field, 'DATA_FIELD_MAXLENGTH', [
					'field'	=> $field_name,
					'num'	=> $field_type['length']
				]);
			}
			else{
				$value = mb_substr($value, 0, $field_type['length']);
			}
		}
		
		return $value;
	}
	
	static public function begin(){
		$dbh = &self::get_dbh_all();
		if(!$dbh[self::TRANSACTION]){
			if(self::$debug_sql_log){
				self::log_debug('BEGIN');
			}
			
			$dbh[self::DBH]->beginTransaction();
			$dbh[self::TRANSACTION] = true;
		}
	}
	
	static public function commit(){
		$dbh = &self::get_dbh_all();
		if($dbh[self::TRANSACTION]){
			if(self::$debug_sql_log){
				self::log_debug('COMMIT');
			}
			
			$dbh[self::DBH]->commit();
			$dbh[self::TRANSACTION] = false;
		}
	}
	
	static public function rollback(){
		$dbh = &self::get_dbh_all();
		if($dbh[self::TRANSACTION]){
			if(self::$debug_sql_log){
				self::log_debug('ROLLBACK');
			}
			
			$dbh[self::DBH]->rollBack();
			$dbh[self::TRANSACTION] = false;
		}
	}
	
	static public function get_int_range(): Array{
		return self::$int_range;
	}
	
	static public function get_column_type(string $table, string $field=''): Array{
		$apc_key 		= 'DB_COLUMN_'.self::$connection.'_'.$table;
		$cache_timeout 	= 600;
		
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
				
				$db_column[$row['Field']]['not_null']	= $row['Null'] == 'NO' ? true : false;
				$db_column[$row['Field']]['default']	= $row['Default'];
				$db_column[$row['Field']]['extra']		= $row['Extra'];
			}
			
			apcu_store($apc_key, $db_column, $cache_timeout);
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
		$file = self::$map_path.'/'.self::$connection.'/'.$type.'/'.$name.'.php';
		
		if(!include_once $file){
			throw new Error('File missing: '.$file);
		}
		
		return '\\dbdata\\'.$type.'\\'.$name;
	}
	
	static public function required_fields(Array $require_fields, Array $input){
		$require_fields = array_flip($require_fields);
		
		//	Return error if invalid fields are received
		if($invalid_fields = array_diff_key($input, $require_fields)){
			throw new Error_input(null, 'DATA_FIELDS_INVALID', [
				'fields' => implode(', ', array_keys($invalid_fields))
			]);
		}
		
		//	Return error if required fields are omitted
		if($omit_fields = array_diff_key($require_fields, $input)){
			throw new Error_input(null, 'DATA_FIELDS_REQUIRE', [
				'fields' => implode(', ', array_keys($omit_fields))
			]);
		}
	}
	
	static protected function log_debug(string $message){
		\Log\Log::log('debug_sql', self::$cid.' '.$message);
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

//	Fatal error
class Error extends \Error {}

//	Fatal DB error
class Error_db extends \Error {}

//	Error caused by user input
class Error_input extends \Error {
	private $field;
	private $translate 		= [];
	
	public function __construct(?string $field, string $message, array $translate=[]){
		parent::__construct($message);
		$this->field 		= $field ?: null;
		$this->translate 	= $translate;
	}
	
	public function get_field(): ?string{
		return $this->field;
	}
	
	public function get_raw_error(): array{
		return [
			'field'		=> $this->field,
			'message'	=> $this->message,
			'translate'	=> $this->translate
		];
	}
	
	public function get_error(): ?string{
		//	\dbdata\Error_input(null, '')
		if(!$this->field && !$this->message){
			return null;
		}
		
		return \dbdata\Lang::get_error($this->message, $this->translate);
	}
	
	public function push(Input $context){
		$context->push_error($this->field, [
			'message'	=> $this->getMessage(),
			'translate'	=> $this->translate
		]);
	}
}
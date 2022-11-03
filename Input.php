<?php

namespace dbdata;

abstract class Input {
	private $_errors 	= [];
	
	protected $_input 	= [];
	protected $_data 	= [];
	
	//	Translate external fields constant
	static protected $_external_field_const 			= [];
	
	//	External field conditions
	static protected $_external_field_condition_unset 	= [];
	
	//	External integer multiplier
	static protected $_external_field_int_multiplier 	= [];
	
	protected const CONDITION_NOT_IN 	= 'not_in';
	protected const CONDITION_IN 		= 'in';
	
	const FLAG_ALLOW_EMPTY 				= 1;
	const FLAG_UNSIGNED_FLOAT 			= 2;
	const FLAG_UNSIGNED_INT 			= 3;
	const FLAG_CONDITION_UNSET 			= 4;
	const FLAG_MANDATORY				= 5;
	const FLAG_ALLOW_INT_ZEROFILL 		= 6;
	const FLAG_CALCULATE 				= 7;
	const FLAG_EMPTY_CURRENT_DATE 		= 8;
	const FLAG_MAP_FLOAT 				= 9;
	const FLAG_SKIP_LENGTH_CHECK 		= 10;
	const FLAG_DATE_TIME 				= 11;
	
	static public function get_external_field_condition_unset(){
		return static::$_external_field_condition_unset;
	}
	
	public function put_apply_multiplier(){
		if(static::$_external_field_int_multiplier){
			foreach(static::$_external_field_int_multiplier as $field => $multiply){
				if(!empty($this->$field)){
					$this->$field *= $multiply;
				}
			}
		}
	}
	
	protected function external_field_condition_unset(string $field, string $field_id=''): bool{
		foreach(static::$_external_field_condition_unset as $master_field => $fields){
			if(!empty($fields[$field]) && isset($this->$master_field)){
				if($master_value = static::$_external_field_const[$master_field][$this->$master_field] ?? null){
					$options = $fields[$field];
					
					$unset_field = false;
					if($options[0] == self::CONDITION_IN){
						if(in_array($master_value, $options[1])){
							$unset_field = true;
						}
					}
					elseif(!in_array($master_value, $options[1])){
						$unset_field = true;
					}
					
					//	Unset field
					if($unset_field){
						$field_col = $field_id ?: $field;
						
						if($column_type = DB::get_column_type($this->_table, $field_col)){
							if($column_type['not_null']){
								$this->$field_col = in_array($column_type['type'], ['int','decimal']) ? 0 : '';
							}
							else{
								$this->$field_col = null;
							}
						}
						
						return false;
					}
				}
			}
		}
		
		return true;
	}
	
	public function push_error(string $field, Array $error){
		if(!isset($this->_errors[$field])){
			$this->_errors[$field] = $error;
		}
	}
	
	public function throw_errors(){
		if($this->_errors){
			foreach($this->_errors as $field => $error){
				DB::error($field, $error['message'], $error['translate']);
			}
			
			throw new Error_input(null, '');
		}
	}
	
	public function get_errors(): Array{
		return $this->_errors;
	}
	
	protected function substr(string $value, string $table, string $field): string{
		return mb_substr($value, 0, DB::get_column_type($table, $field)['length']);
	}
	
	//	Field constant
	protected function error_constant(string $field){
		$value = array_search($this->$field, static::$_external_field_const[$field]);
		if($value === false){
			throw new Error_input($field, 'DATA_FIELD_CONSTANT_INVALID', [
				'field'		=> \Lang::get($this->_fields[$field]),
				'values'	=> implode(', ', static::$_external_field_const[$field])
			]);
		}
		
		$this->$field = $value;
	}
	
	//	Field must not be empty
	protected function error_empty(string $field){
		if(!strlen($this->$field)){
			throw new Error_input($field, 'DATA_FIELD_EMPTY', [
				'field' => \Lang::get($this->_fields[$field])
			]);
		}
	}
	
	//	Field must be integer
	protected function error_int(string $field, Array $flags=[]){
		$opt = [
			'options' => []
		];
		
		if(!$this->$field && in_array(self::FLAG_ALLOW_EMPTY, $flags)){
			$this->$field = 0;
			
			return;
		}
		
		if(in_array(self::FLAG_UNSIGNED_INT, $flags)){
			$error = 'DATA_FIELD_ABS_INT';
			
			$opt['options']['min_range'] = 1;
		}
		else{
			$error = 'DATA_FIELD_INT';
		}
		
		//	Allow int zerofill
		if(in_array(self::FLAG_ALLOW_INT_ZEROFILL, $flags)){
			if(ctype_digit($this->$field)){
				$this->$field = (int)$this->$field;
			}
			else{
				throw new Error_input($field, $error, [
					'field' => \Lang::get($this->_fields[$field])
				]);
			}
		}
		else{
			$result = filter_var($this->$field, FILTER_VALIDATE_INT, $opt);
			if($result === false){
				throw new Error_input($field, $error, [
					'field' => \Lang::get($this->_fields[$field])
				]);
			}
			else{
				$this->$field = $result;
			}
		}
	}
	
	//	Field must be float
	protected function error_float(string $field, Array $flags=[]){
		if(in_array(self::FLAG_CALCULATE, $flags)){
			$this->calculate_amount($field);
		}
		
		$this->$field = str_replace(',', '.', $this->$field);
		
		if($unsigned = in_array(self::FLAG_UNSIGNED_FLOAT, $flags)){
			$error = 'DATA_FIELD_ABS_FLOAT';
		}
		else{
			$error = 'DATA_FIELD_FLOAT';
		}
		
		$allow_empty = in_array(self::FLAG_ALLOW_EMPTY, $flags);
		
		//	Allow empty
		if(!strlen($this->$field) && $allow_empty){
			$this->$field = 0;
			
			return;
		}
		
		$value = filter_var($this->$field, FILTER_VALIDATE_FLOAT);
		if($value === false){
			throw new Error_input($field, $error, [
				'field' => \Lang::get($this->_fields[$field])
			]);
		}
		
		//	Allow empty
		if(!$value && $allow_empty){
			$this->$field = 0;
			
			return;
		}
		
		if($unsigned && $value <= 0 || $value == 0){
			throw new Error_input($field, $error, [
				'field' => \Lang::get($this->_fields[$field])
			]);
		}
		
		$this->$field = $value;
	}
	
	//	Field integer range
	protected function error_int_range(string $field, Array $options=[]){
		if(isset($options['max_range']) && isset($options['min_range'])){
			if($this->$field > $options['max_range'] || $this->$field < $options['min_range']){
				throw new Error_input($field, 'DATA_FIELD_RANGE_INT', [
					'field'			=> \Lang::get($this->_fields[$field]),
					'value'			=> $this->$field,
					'range_from'	=> $options['min_range'],
					'range_to'		=> $options['max_range']
				]);
			}
		}
	}
	
	//	Field DB integer range
	protected function error_db_int_range(string $field, Array $options=[]){
		$multiplier = empty($options['multiplier']) ? 1 : (int)$options['multiplier'];
		$column 	= DB::get_column_type($options['table'] ?? $this->_table, $options['field'] ?? $field);
		$from 		= $column['range']['from'] / $multiplier;
		$to 		= $column['range']['to'] / $multiplier;
		
		if($this->$field < $from || $this->$field > $to){
			throw new Error_input($field, 'DATA_FIELD_RANGE_INT', [
				'field'			=> \Lang::get($this->_fields[$field]),
				'value'			=> $this->$field,
				'range_from'	=> $from,
				'range_to'		=> $to
			]);
		}
	}
	
	//	Field must be unique
	protected function error_unique(string $field, Array $options=[]){
		$table = $options['table'] ?? $this->_table;
		
		$access_all = $this->Data->get_var('access_all');
		
		$Data = (new Get);
		if($access_all){
			$Data->access_all();
		}
		$input = [
			'select' => [
				'id'
			],
			'where' => [
				$field => $this->$field
			],
			'limit' => 1
		];
		if(!empty($options['where'])){
			$input['where'] = array_merge($options['where'], $input['where']);
		}
		if(!empty($this->_predata['id'])){
			if($access_all){
				$input['where']['block_id'] = (new Get)
					->access_all()
					->exec($table, [
						'select' => [
							'block_id'
						],
						'where' => [
							'id' => $this->_predata['id']
						]
				])->fetch()['block_id'];
			}
			
			$input['where']['id !'] = $this->_predata['id'];
		}
		if($Data->exec($table, $input)->rowCount()){
			throw new Error_input($field, 'DATA_FIELD_DUPLICATE', [
				'field' => \Lang::get($this->_fields[$options['field'] ?? $field]),
				'value' => $options['value'] ?? $this->$field
			]);
		}
	}
	
	protected function error_allowed_chars(string $field, string $allowed_chars){
		if(!preg_match('/^['.$allowed_chars.']+$/i', $this->$field)){
			throw new Error_input($field, 'DATA_FIELD_ALLOWED_CHARS', [
				'field'		=> \Lang::get($this->_fields[$field]),
				'allowed'	=> $allowed_chars
			]);
		}
	}
	
	//	Validate date
	protected function error_date(string $field, bool $time=false){
		if(is_numeric($this->$field) && $this->$field > 1200000000){
			$this->$field = (int)$this->$field;
			if(!$time){
				$this->$field = mktime(0,0,0, date('m', $this->$field), date('d', $this->$field), date('Y', $this->$field));
			}
		}
		else{
			$this->$field = \Time\Time::date($this->$field);
			if($this->$field <= 0){
				throw new Error_input($field, 'DATA_DATE_INVALID');
			}
		}
		
		if($this->$field > 1999999999){
			throw new Error_input($field, 'DATA_DATE_INVALID');
		}
	}
	
	//	Validate URL
	protected function error_url(string $field){
		if(!preg_match('/^https?:\/\//i', $this->$field)){
			throw new Error_input($field, 'DATA_FIELD_URL', [
				'field' => \Lang::get($this->_fields[$field])
			]);
		}
		
		if(filter_var($this->$field, FILTER_VALIDATE_URL) === false){
			throw new Error_input($field, 'DATA_FIELD_URL', [
				'field' => \Lang::get($this->_fields[$field])
			]);
		}
	}
	
	//	Validate email
	protected function error_email(string $field){
		$this->validate_email($field, $this->$field);
	}
	
	protected function validate_email(string $field, string $value){
		$pos = strpos($value, '@');
		if($pos !== false){
			if($domain = substr($value, $pos + 1)){
				//	Check if domain is IDN (Internation Domain Name) and convert to punycode
				if(!preg_match('/^[a-z\d\-._]+$/i', $domain)){
					$domain = idn_to_ascii($domain) ?: $domain;
				}
				
				//	Check domain pattern
				if(!preg_match('/^(?:(?:[a-z\d]|[a-z\d][a-z\d\-]*[a-z\d])\.)+(?:[a-z]{2,})$/i', $domain)){
					throw new Error_input($field, 'DATA_EMAIL_INVALID_DOMAIN', [
						'email'		=> $value,
						'domain'	=> $domain
					]);
				}
				
				if(!checkdnsrr($domain, 'mx')){
					throw new Error_input($field, 'DATA_EMAIL_INVALID_DOMAIN', [
						'email'		=> $value,
						'domain'	=> $domain
					]);
				}
			}
			else{
				throw new Error_input($field, 'DATA_EMAIL_INVALID', [
					'email' => $value
				]);
			}
			
			//	Return error if alias is empty
			if(!$alias = substr($value, 0, $pos)){
				throw new Error_input($field, 'DATA_EMAIL_INVALID', [
					'email' => $value
				]);
			}
			
			//	Return error if email contains illegal chars
			if(!preg_match('/^[a-z0-9+-_.]*$/i', $alias)){
				throw new Error_input($field, 'DATA_EMAIL_INVALID', [
					'email' => $value
				]);
			}
		}
		else{
			throw new Error_input($field, 'DATA_EMAIL_INVALID', [
				'email' => $value
			]);
		}
	}
	
	//	Field must be alphabetical or numeric
	protected function error_alpha_numeric(string $field, Array $options=[]){
		if(!preg_match('/^[a-zA-Z0-9 ]+$/', $this->$field)){
			throw new Error_input($field, 'DATA_FIELD_ALPHA_NUMERIC', [
				'field' => \Lang::get($this->_fields[$field])
			]);
		}
	}
	
	protected function error_account_entry(Array $account, string $field, string $value, Array $account_types=[]){
		//	Return error if account is not an entry account
		if($account['type'] > 2 || $account['system'] == 1){
			throw new Error_input($field, 'ACCOUNTING_ACCOUNT_NOENTRIES', [
				'account' => $value
			]);
		}
		
		//	Return error if account is a module account
		if($account['module'] || $account['stock']){
			throw new Error_input($field, 'ACCOUNTING_ACCOUNT_MODULE', [
				'account' => $value
			]);
		}
		
		//	Return error if account is not an allowed type
		if($account_types){
			if(!in_array($account['type'], $account_types)){
				$error = in_array(1, $account_types) ? 'ACCOUNT_TYPE_BALANCE' : 'ACCOUNT_TYPE_PROFIT_LOSS';
				throw new Error_input($field, $error, [
					'field' => \Lang::get($this->_fields[$field])
				]);
			}
		}
	}
	
	protected function error_parent_id(string $field, string $parent_table, array $select=['id']): array{
		$this->$field = $this->_input[$field];
		
		//	Return error if id is changed
		if($this->_method == Data::METHOD_UPDATE){
			if(!isset($this->_predata[$field])){
				throw new Error('Parent id undefined on update');
			}
			
			if($this->$field != $this->_predata[$field]){
				throw new Error_input($field, 'ENTRY_CHANGE_PARENT_ID', [
					'field' => \Lang::get($this->_fields[$field])
				]);
			}
		}
		
		$Data = new Get;
		if($this->Data->get_var('omit_user_auth')){
			$Data->omit_user_auth();
		}
		
		if(!$row = $Data->exec($parent_table, [
			'select' => $select,
			'where' => [
				'id' => $this->$field
			]
		])->fetch()){
			//	Return error if not found
			throw new Error_input($field, 'ENTRY_NOT_FOUND');
		}
		
		return $row;
	}
	
	protected function put_constant(string $field, Array $flags=[]){
		try{
			if($flags && in_array(self::FLAG_CONDITION_UNSET, $flags) && !$this->external_field_condition_unset($field)){
				return;
			}
			
			$this->$field = $this->_input[$field];
			$this->error_constant($field);
		}
		catch(Error_input $e){
			$e->push($this);
		}
	}
	
	protected function put_field(string $field, bool $uppercase=false, bool $allow_newlines=false, array $flags=[], array $options=[]){
		try{
			if($flags && in_array(self::FLAG_SKIP_LENGTH_CHECK, $flags)){
				$this->$field = DB::value($this->_input[$field], '', '', '', true, $allow_newlines);
			}
			else{
				$this->$field = DB::value($this->_input[$field], $options['field'] ?? $field, $options['table'] ?? $this->_table, \Lang::get($this->_fields[$field]), true, $allow_newlines);
			}
			
			if(!in_array(self::FLAG_ALLOW_EMPTY, $flags)){
				$this->error_empty($field);
			}
			
			if($uppercase){
				$this->$field = mb_strtoupper($this->$field);
			}
		}
		catch(Error_input $e){
			$e->push($this);
		}
	}
	
	protected function put_html(string $field, bool $uppercase=false){
		try{
			$this->$field = DB::value($this->_input[$field], $field, $this->_table, \Lang::get($this->_fields[$field]), true, true);
			$this->error_empty($field);
			
			$XML = new \Format\XML_decode;
			$XML->string_html($this->$field);
			if($errors = $XML->validation_errors()){
				throw new Error_input($field, 'DATA_FIELD_HTML_INVALID', [
					'field'		=> \Lang::get($this->_fields[$field]),
					'message'	=> $errors[0]->message.' (Line: '.$errors[0]->line.')'
				]);
			}
			libxml_clear_errors();
		}
		catch(Error_input $e){
			$e->push($this);
		}
	}
	
	protected function put_name_unique(string $field, bool $uppercase=false, Array $options=[], string $allowed_chars=''){
		try{
			$this->$field = DB::value($this->_input[$field], $field, $this->_table, \Lang::get($this->_fields[$field]));
			if($uppercase){
				$this->$field = mb_strtoupper($this->$field);
			}
			$this->error_empty($field);
			if($allowed_chars){
				$this->error_allowed_chars($field, $allowed_chars);
			}
			$this->error_unique($field, $options);
		}
		catch(Error_input $e){
			$e->push($this);
		}
	}
	
	//	Verify password
	protected function put_pass(string $field, string $field_verify){
		try{
			$value = $this->_input[$field];
			if($value && $value == $this->_input[$field_verify]){
				require_once \Ini::get('path/class').'/Password_hash.php';
				$this->$field = (new \Password_hash)->create_hash($value);
			}
			else{
				throw new Error_input($field, 'DATA_PASSWORD_VERIFY_INVALID');
			}
		}
		catch(Error_input $e){
			$e->push($this);
		}
	}
	
	protected function put_vatno(string $field, string $country, array $flags=[]){
		try{
			$this->$field = \dbdata\DB::value($this->_input[$field]);
			
			if(!$this->$field && in_array(self::FLAG_MANDATORY, $flags)){
				$this->error_empty($field);
			}
			
			if($this->$field){
				$this->$field = str_replace(' ', '', $this->$field);
				
				if($country){
					$result = (new \Fetch_cache\Cache_vatno)->get(\Fetch_cache\Cache_vatno::MODE_VATNO, $this->$field, $country);
					if($result['status'] == \Fetch_cache\Fetch::STATUS_NOT_FOUND){
						throw new Error_input($field, 'DATA_VATNO_INVALID', [
							'field'		=> \Lang::get($this->_fields[$field]),
							'value'		=> $this->$field,
							'country'	=> strtoupper($country)
						]);
					}
				}
			}
		}
		catch(Error_input $e){
			$e->push($this);
		}
	}
	
	protected function put_email(string $field, Array $flags=[]){
		try{
			if($flags && in_array(self::FLAG_SKIP_LENGTH_CHECK, $flags)){
				$this->$field = DB::value($this->_input[$field]);
			}
			else{
				$this->$field = DB::value($this->_input[$field], $field, $this->_table, \Lang::get($this->_fields[$field]));
			}
			
			if(!$this->$field && in_array(self::FLAG_MANDATORY, $flags)){
				$this->error_empty($field);
			}
			if($this->$field){
				$this->error_email($field);
			}
		}
		catch(Error_input $e){
			$e->push($this);
		}
	}
	
	protected function put_percent(string $field){
		try{
			$this->$field = DB::value($this->_input[$field]);
			$this->error_float($field, [
				self::FLAG_ALLOW_EMPTY,
				self::FLAG_UNSIGNED_FLOAT
			]);
			$this->error_int_range($field, [
				'min_range' => 0,
				'max_range' => 100
			]);
		}
		catch(Error_input $e){
			$e->push($this);
		}
	}
	
	protected function put_entry_account(string $field, string $field_id, Array $flags=[], array $account_types=[]){
		try{
			if($flags && in_array(self::FLAG_CONDITION_UNSET, $flags) && !$this->external_field_condition_unset($field, $field_id)){
				return;
			}
			
			$value = DB::value($this->_input[$field]);
			
			if(!$value && in_array(self::FLAG_ALLOW_EMPTY, $flags)){
				$this->$field_id = null;
			}
			elseif($account = load_resource('account')->get_by_account($value)){
				$this->error_account_entry($account, $field, $value, $account_types);
				
				$this->$field_id = $account['id'];
			}
			else{
				throw new Error_input($field, 'DATA_FIELD_INVALID', [
					'field' => \Lang::get($this->_fields[$field])
				]);
			}
			
			return $value;
		}
		catch(Error_input $e){
			$e->push($this);
		}
	}
	
	protected function put_dimension(string $field, string $field_id, string $account_id, string $account_dimension='', Array $flags=[]){
		try{
			if($flags && in_array(self::FLAG_CONDITION_UNSET, $flags) && !$this->external_field_condition_unset($field, $field_id)){
				return;
			}
			
			$this->$field_id = null;
			
			if(!empty($this->$account_id)){
				$value = DB::value($this->_input[$field]);
				if($value || ($account_dimension && !empty($this->_data[$account_dimension]))){
					if(!$this->$field_id = load_resource('dimension')->get_by_name($value)){
						throw new Error_input($field, 'DATA_FIELD_INVALID', [
							'field' => \Lang::get($this->_fields[$field])
						]);
					}
				}
			}
		}
		catch(Error_input $e){
			$e->push($this);
		}
	}
	
	protected function put_time(string $field, Array $flags=[], bool $unset=false){
		try{
			if($unset){
				$this->$field = 0;
				
				return;
			}
			
			$this->$field = DB::value($this->_input[$field]);
			
			if(!$this->$field){
				if(in_array(self::FLAG_ALLOW_EMPTY, $flags)){
					$this->$field = 0;
					
					return;
				}
				elseif((!isset($this->_method) || $this->_method == \dbdata\Data::METHOD_INSERT) && in_array(self::FLAG_EMPTY_CURRENT_DATE, $flags)){
					$this->$field = \Time\Time::time_today();
					
					return;
				}
			}
			
			$this->error_empty($field);
			$this->error_date($field, $flags && in_array(self::FLAG_DATE_TIME, $flags));
		}
		catch(Error_input $e){
			$e->push($this);
		}
	}
	
	protected function put_vatcode(string $field, Array $flags=[]){
		try{
			if($flags && in_array(self::FLAG_CONDITION_UNSET, $flags) && !$this->external_field_condition_unset($field, 'vatcode_id')){
				return;
			}
			
			if($value = DB::value($this->_input[$field])){
				if($vatcode = load_resource('vatcode')->get_by_name($value)){
					$this->vatcode_id = $vatcode['id'];
				}
				else{
					throw new Error_input($field, 'DATA_FIELD_INVALID', [
						'field' => \Lang::get($this->_fields[$field])
					]);
				}
			}
			else{
				$this->vatcode_id = null;
			}
		}
		catch(Error_input $e){
			$e->push($this);
		}
	}
	
	protected function put_payment(string $field, Array $flags=[]){
		try{
			if($flags && in_array(self::FLAG_CONDITION_UNSET, $flags) && !$this->external_field_condition_unset($field, 'payment_id')){
				return;
			}
			
			$value = DB::value($this->_input[$field]);
			if($payment = load_resource('payment')->get_by_name($value)){
				$this->payment_id = $payment['id'];
			}
			else{
				throw new Error_input($field, 'DATA_FIELD_INVALID', [
					'field' => \Lang::get($this->_fields[$field])
				]);
			}
		}
		catch(Error_input $e){
			$e->push($this);
		}
	}
	
	protected function put_ref_currency(string $field, Array $flags=[]){
		try{
			$this->ref_currency_id = null;
			
			$value = DB::value($this->_input[$field]);
			
			if(!$value && in_array(self::FLAG_ALLOW_EMPTY, $flags)){
				return;
			}
			
			if(!$this->ref_currency_id = load_resource('currency')->get_by_ref_name($value)){
				throw new Error_input($field, 'DATA_FIELD_INVALID', [
					'field' => \Lang::get($this->_fields[$field])
				]);
			}
		}
		catch(Error_input $e){
			$e->push($this);
		}
	}
	
	protected function put_bank(string $field, array $flags=[]){
		try{
			if($flags && in_array(self::FLAG_CONDITION_UNSET, $flags) && !$this->external_field_condition_unset($field, 'bank_id')){
				return;
			}
			
			if($value = DB::value($this->_input[$field])){
				if($bank = load_resource('bank')->get_by_name($value)){
					$this->bank_id = $bank['id'];
				}
				else{
					throw new Error_input($field, 'DATA_FIELD_INVALID', [
						'field' => \Lang::get($this->_fields[$field])
					]);
				}
			}
			else{
				$this->bank_id = null;
			}
		}
		catch(Error_input $e){
			$e->push($this);
		}
	}
	
	protected function put_lang(string $field){
		try{
			$value = $this->_input[$field];
			if(!\Env::check_lang($value)){
				throw new Error_input($field, 'DATA_FIELD_INVALID', [
					'field' => \Lang::get($this->_fields[$field])
				]);
			}
			$this->$field = mb_strtoupper($value);
		}
		catch(Error_input $e){
			$e->push($this);
		}
	}
	
	protected function put_country(string $field, string $field_id){
		try{
			if($value = DB::value($this->_input[$field])){
				if(!$this->$field_id = load_resource('ref_country')->get_by_name($value)){
					throw new Error_input($field, 'DATA_FIELD_INVALID', [
						'field' => \Lang::get($this->_fields[$field])
					]);
				}
				
				$this->_data['country_name'] = $value;
			}
			else{
				$this->$field_id = null;
			}
		}
		catch(Error_input $e){
			$e->push($this);
		}
	}
	
	protected function put_amount(string $field, Array $flags=[], array $options=[]){
		try{
			if($flags && in_array(self::FLAG_CONDITION_UNSET, $flags) && !$this->external_field_condition_unset($field)){
				return;
			}
			
			$flags[] = self::FLAG_CALCULATE;
			
			$options['multiplier'] = static::$_external_field_int_multiplier[$field];
			
			$this->$field = DB::value($this->_input[$field]);
			$this->error_float($field, $flags);
			$this->error_db_int_range($field, $options);
		}
		catch(Error_input $e){
			$e->push($this);
		}
	}
	
	protected function put_product(string $field){
		try{
			$value = DB::value($this->_input[$field]);
			if(!$product = load_resource('product')->get_by_product($value, 0)){
				throw new Error_input($field, 'DATA_FIELD_INVALID', [
					'field' => \Lang::get($this->_fields[$field])
				]);
			}
			
			$this->product_id = $product['id'];
		}
		catch(Error_input $e){
			$e->push($this);
		}
	}
	
	protected function put_module(string $field){
		try{
			$value = DB::value($this->_input[$field]);
			if(!$module = load_resource('module')->get_by_type($this->type, $value)){
				throw new Error_input($field, 'DATA_FIELD_INVALID', [
					'field' => \Lang::get($this->_fields[$field])
				]);
			}
			
			$this->module_id = $module['id'];
		}
		catch(Error_input $e){
			$e->push($this);
		}
	}
	
	//	Calculate amount
	private function calculate_amount(string $field){
		require_once \Ini::get('path/class').'/Field_calculate.php';
		$this->$field = (new \Field_calculate)->calculate($this->$field);
	}
}
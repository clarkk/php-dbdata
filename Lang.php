<?php

namespace dbdata;

class Lang {
	const APC_CACHE = 60*60*24;
	
	static private $locales = [
		self::LANG_DA => [
			'locale'	=> 'da_DK.UTF-8',
			'currency'	=> 'DKK'
		],
		self::LANG_EN => [
			'locale'	=> 'en_US.UTF-8',
			'currency'	=> 'DKK'
		]
	];
	
	const LANG_DA = 'da';
	const LANG_EN = 'en';
	
	static private $lang;
	static private $locale;
	static private $currency;
	
	static public function init(string $lang): array{
		self::$lang = strtolower($lang);
		
		//	Return error if language is invalid
		if(empty(self::$locales[self::$lang])){
			throw new Error('Invalid language');
		}
		
		$locale = self::$locales[self::$lang]['locale'];
		setLocale(LC_COLLATE, $locale);
		setLocale(LC_CTYPE, $locale);
		setLocale(LC_MONETARY, $locale);
		$localeconv = localeconv();
		
		self::$locale = [
			'decimal_point'	=> $localeconv['mon_decimal_point'],
			'thousands_sep'	=> $localeconv['mon_thousands_sep']
		];
		
		self::$currency = self::$locales[self::$lang]['currency'];
		
		return self::get_locale();
	}
	
	static public function get_locale(): array{
		return [
			'lang'		=> self::$lang,
			'currency'	=> self::$currency
		] + self::$locale;
	}
	
	static public function num(float $num, int $dec=0, string $thousand_sep='', string $decimal_sep=''): string{
		return number_format($num, $dec, $decimal_sep ?: self::$locale['decimal_point'], $thousand_sep ?: self::$locale['thousands_sep']);
	}
	
	static public function num_datasize(int $int): string{
		if(!$int){
			return '0';
		}
		
		$arr = [
			'Bytes'	=> 1,
			'Kb'	=> 1024,
			'Mb'	=> 1024 * 1024
		];
		
		$arr = array_reverse($arr);
		foreach($arr as $key => $value){
			$scale = $int / $value;
			if($scale >= 1){
				return self::num($scale, is_int($scale) ? 0 : 2).' '.$key;
			}
		}
		
		return '0';
	}
	
	static public function get(string $string, array $trans=[], string $lang=''): string{
		return self::fetch($string, $trans, $lang, false);
	}
	
	static public function get_error(string $string, array $trans=[], string $lang=''): string{
		return self::fetch($string, $trans, $lang, true);
	}
	
	static private function fetch(string $string, array $trans, string $lang, bool $is_error): string{
		$lang = $lang ? strtolower($lang) : self::$lang;
		
		$replace = [];
		if($trans){
			foreach($trans as $k => $v){
				$replace['%'.$k.'%'] = $v;
			}
		}
		
		$apc_key = 'LANG_'.($is_error ? 'ERROR_' : '').$lang.'_'.$string;
		
		if(!$lang_string = apcu_fetch($apc_key)){
			$result = (new Get)->exec($is_error ? 'lang_error' : 'lang', [
				'select' => [
					$lang
				],
				'where' => [
					'string' => $string
				]
			]);
			
			//	Return string if not found in database
			if(!$row = $result->fetch()){
				return $string;
			}
			
			$lang_string = $row[$lang];
			
			apcu_store($apc_key, $lang_string, self::APC_CACHE);
		}
		
		if($replace){
			return strtr($lang_string, $replace);
		}
		
		return $lang_string;
	}
}
<?php

namespace dbdata;

class Lang {
	const APC_CACHE = 60*60*24;
	
	static private $locales = [
		self::LANG_DA => [
			'locale'	=> 'da_DK.'.self::ENC_UTF8,
			'currency'	=> 'DKK'
		],
		self::LANG_EN => [
			'locale'	=> 'en_US.'.self::ENC_UTF8,
			'currency'	=> 'DKK'
		]
	];
	
	const LANG_DA 	= 'da';
	const LANG_EN 	= 'en';
	
	const ENC_UTF8 	= 'UTF-8';
	
	static private $lang;
	static private $locale;
	static private $currency;
	
	static public function init(string $lang): array{
		self::$lang = strtolower($lang);
		
		//	Return error if language is invalid
		if(empty(self::$locales[self::$lang])){
			throw new Error('Invalid language');
		}
		
		self::$locale 	= self::$locales[self::$lang]['locale'];
		self::$currency = self::$locales[self::$lang]['currency'];
		
		return self::get_locale();
	}
	
	static public function get_locale(): array{
		return [
			'lang'		=> self::$lang,
			'locale'	=> self::$locale,
			'currency'	=> self::$currency
		];
	}
	
	static public function get_locales(): array{
		return array_keys(self::$locales);
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
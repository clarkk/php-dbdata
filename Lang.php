<?php

namespace dbdata;

class Lang {
	const APC_CACHE = 60*60*24;
	
	static private $locales = [
		self::LANG_DA => [
			'locale'	=> 'da_DK.UTF-8'
		],
		self::LANG_EN => [
			'locale'	=> 'en_US.UTF-8'
		]
	];
	
	const LANG_DA = 'da';
	const LANG_EN = 'en';
	
	static private $lang;
	
	static public function init(string $lang){
		self::$lang = strtolower($lang);
		
		$locale = self::$locales[$lang]['locale'];
		setLocale(LC_COLLATE, $locale);
		setLocale(LC_CTYPE, $locale);
		setLocale(LC_MONETARY, $locale);
		$localeconv = localeconv();
		//self::set('locale/decimal_point', $localeconv['mon_decimal_point']);
		//self::set('locale/thousands_sep', $localeconv['mon_thousands_sep']);
	}
	
	static public function get(string $string, array $trans=[], string $lang=''){
		return self::fetch($string, $trans, $lang, false);
	}
	
	static public function get_error(string $string, array $trans=[], string $lang=''){
		return self::fetch($string, $trans, $lang, true);
	}
	
	static private function fetch(string $string, array $trans, string $lang, bool $is_error){
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
			if($row = $result->fetch()){
				$lang_string = $row[$lang];
			}
			
			apcu_store($apc_key, $lang_string, self::APC_CACHE);
		}
		
		if($replace){
			return strtr($lang_string, $replace);
		}
		
		return $lang_string;
	}
}
<?php

namespace dbdata;

class Map {
	private $table_short = [];
	
	public function __construct(){
		foreach($this->table as $table => $value){
			if(!empty($value['name'])){
				$this->table_short[$table] = $value['name'];
			}
		}
	}
	
	public function get(string $table): array{
		return $this->table[$table] ?? [];
	}
	
	public function get_table_short(): array{
		return $this->table_short;
	}
	
	public function get_delegates(): array{
		$list = [];
		foreach($this->table as $table => $value){
			if(!empty($value['delegate'])){
				$list[$table] = $value['delegate'];
			}
		}
		
		return $list;
	}
}
<?php
class BankTransaction extends Eloquent{

	public $timestamps = FALSE;
	
	public function user(){
		return $this->belongsTo('User', 'user_id');
	}
	
	public function purchase(){
		return $this->hasMany('Purchase');
	}

}

/* End of File: app/models/Transaction.php */
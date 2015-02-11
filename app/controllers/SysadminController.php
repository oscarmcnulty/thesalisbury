<?php

use Facebook\FacebookSession;
use Facebook\FacebookRequest;
use Facebook\GraphUser;

class SysadminController extends BaseController {

	protected $layout = 'account.master';

	
	//============================= Begin SysAdmin User Pages =====================================
	//==========================================================================================
	
	public function getSysadminDashboard() {
		
		$data = app('request_data');
		$data['user'] = User::where('id', $data['id']) -> first();
		$data['user'] -> load('transaction', 'transaction.sku', 'bankTransaction');

		$data['sku_count'] = $this -> getSkuCount(NULL);
		$data['bank_timeline'] = $this -> getBankBalanceTimeline();
		$data['profit_timeline'] = $this -> getProfitTimeline();
		
		$data['assets'] = $this->getAssets();
		$data['liabilities'] = $this->getLiabilities();
		$data['stock_value'] = $this->getStockValue();
		$data['cash_on_hand'] = $this->getCashOnHand();
		$data['net_tabs'] = DB::select('SELECT SUM(balance) `total` FROM users')[0]->total;
		$data['equity'] = $data['assets'] - $data['liabilities'];
		$data['payout'] = $this->getPayouts();
		$data['takings_to_date'] = $data['equity'] + $data['payout'];
		
		

		$data['location'] = 'SysAdmin Dashboard';
		$data['description'] = 'An overview of your empire';
		$this -> layout -> content = View::make('sysadmin.dashboard', $data);
	}

	public function getSysadminOperations() {
		$data = app('request_data');
		$data['user'] = User::where('id', $data['id']) -> first();
		$data['user'] -> load('transaction', 'transaction.sku', 'bankTransaction');
		
		$data['users'] = User::all();
		$data['ingredients'] = Ingredient::all();

		$data['location'] = 'Operations';
		$data['description'] = 'Admin Operations';
		$this -> layout -> content = View::make('sysadmin.operations', $data);
	}
	
	public function getSysadminTransactions() {
		$data = app('request_data');
		$data['user'] = User::where('id', $data['id']) -> first();
		$data['location'] = 'Bank Transactions';
		$data['description'] = 'A list of all bank transactions';
		$data['transactions'] = BankTransaction::all();
		$data['transactions'] -> load('user','purchase');
		$data['users'] = User::all()->sortBy('first_name');
		
		$this -> layout -> content = View::make('sysadmin.transactions', $data);
	}
	
	public function getSysadminPurchases() {
		$data = app('request_data');
		$data['user'] = User::where('id', $data['id']) -> first();
		$data['location'] = 'Purchases';
		$data['description'] = 'A list of all stock purchases';
		$data['bank_transactions'] = BankTransaction::all();
		$data['purchases'] = Purchase::all();
		$data['purchases']->load('ingredient');
		
		$this -> layout -> content = View::make('sysadmin.purchases', $data);
	}

	public function getSysadminCashTransactions() {
		$data = app('request_data');
		$data['user'] = User::where('id', $data['id']) -> first();
		$data['location'] = 'Cash Transactions';
		$data['description'] = 'A list of all cash transactions';
		$data['transactions'] = CashTransaction::all();
		$data['transactions'] -> load('user','purchase');
		$data['users'] = User::all()->sortBy('first_name');
		
		$deposits = BankTransaction::where('app_type', 'CASHDEPOSIT')->get();
		foreach($deposits as $deposit){
			$transaction = new CashTransaction();
			$transaction-> amount = -$deposit->amount;
			$transaction-> type = 'CASHDEPOSIT';
			$transaction-> timestamp = date('c', strtotime($deposit->date));
			$data['transactions']->add($transaction);
		}
		
		$this -> layout -> content = View::make('sysadmin.cashtransactions', $data);
	}
	
	
	//============================ Super User AJAX Functions ===================================
	//==========================================================================================

	public function updateCash($amount){
		setlocale(LC_MONETARY, "en_US.UTF-8");
		$current = DB::select('SELECT SUM(amount) `total` FROM cash_transactions')[0]->total - DB::select("SELECT SUM(amount) `total` FROM bank_transactions WHERE app_type = 'CASHDEPOSIT'")[0]->total;
		$difference = $amount-$current;
		$transaction = new CashTransaction();
		$transaction -> amount = $difference;
		$transaction -> type = "CASHRECONCILIATION";
		$transaction -> save();
		return money_format('%n',$amount/100);
	}

	public function updateCredit($id, $amount){
		setlocale(LC_MONETARY, "en_US.UTF-8");
		$t = new CashTransaction();
		$t->user_id = $id;
		$t->amount = $amount;
		$t->type = 'TABCREDIT';
		$t->save();
		$user = User::where('id',$id)->first();
		$user['balance'] += $amount;
		$user->save();
		return $user['first_name'].' '.$user['last_name'].' was credited with '.money_format('%n',$amount/100);
	}
	
	public function updatePurchase($id, $volume, $cost){
		setlocale(LC_MONETARY, "en_US.UTF-8");
		$p = new Purchase();
		$p->volume = $volume;
		$p->ingredient_id = $id;
		$p->cost = $cost;
		$p->save();
		return $volume.'ml of '.Ingredient::where('id', $id)->first()['name'].' was bought for '.money_format('%n',$cost/100);
	}
	
	public function setBankTransactionType($transactionid, $type){
		$t = BankTransaction::where('id', $transactionid)->first();
		
		if( ($old_user = User::where('id', $t['user_id'])->first()) != NULL){
			$old_user['balance'] += - $t['amount'];
			$old_user -> save();
			$t['user_id'] = NULL;
		}
		if(strcmp($type, 'NONE') == 0){
			$t['app_type'] = NULL;
			$t['app_description'] = NULL;
			$t->save();
			return '';
		}else{	
			$t['app_type'] = $type;
			$t['app_description'] = NULL;
			$t->save();
			return $type;
		}
	}
	
	public function setBankTransactionPayoutType($transactionid, $type, $user){
		$t = BankTransaction::where('id', $transactionid)->first();
		
		if( ($old_user = User::where('id', $t['user_id'])->first()) != NULL){
			$old_user['balance'] += - $t['amount'];
			$old_user -> save();
			$t['user_id'] = NULL;
		}	
		$t['app_type'] = $type;
		$t['app_description'] = $user;
		$t->save();
		
		return $type.' '.$user;
	}
	
	public function setCashTransactionType($transactionid, $type){
		$t = CashTransaction::where('id', $transactionid)->first();
		
		if( ($old_user = User::where('id', $t['user_id'])->first()) != NULL){
			$old_user['balance'] += - $t['amount'];
			$old_user -> save();
			$t['user_id'] = NULL;
		}	
		$t['type'] = $type;
		
		$t->save();
		
		return $type;
	}
	
	public function linkPurchaseBank($id, $transactionid){
		$purchase = Purchase::where('id', $id)->first();
		if($purchase->cash_transaction_id!= NULL) CashTransaction::where('id', $purchase->cash_transaction_id)->delete();
		$purchase->cash_transaction_id = NULL;
		$purchase->bank_transaction_id = $transactionid;
		$purchase->save();
		$transaction = BankTransaction::where('id', $transactionid)->first();
		return 'Bank '.$transaction->date.' '.money_format('%n', $transaction->amount / 100 ).' '.$transaction->description;
	}
	
	public function linkPurchaseCash($id){
		$purchase = Purchase::where('id', $id)->first();
		if($purchase->cash_transaction_id!= NULL) CashTransaction::where('id', $purchase->cash_transaction_id)->delete();
		$transaction = new CashTransaction();
		$transaction->amount = -$purchase->cost;
		$transaction->type = 'STOCKPURCHASE';
		$transaction->description = $purchase->ingredient->name;
		$transaction->save();
		$purchase->cash_transaction_id = $transaction->id;
		$purchase->bank_transaction_id = NULL;
		$purchase->save();
		return 'Cash '.$transaction->date.' '.money_format('%n', $transaction->amount / 100 ).' '.$transaction->description;
	}
	
	public function uploadBankTransactions() {
		if (Input::hasFile('csv')) {

			$file = Input::file('csv');
			$handle = fopen($file, 'r');
			while (($drs = fgetcsv($handle, 1000, ",")) !== false) {
				$t = new BankTransaction();
				$t -> date = date('c', strtotime($drs[0]));
				$t -> amount = round($drs[1]*100);
				$t -> type = $drs[4];
				$t -> description = $drs[5];
				$t -> balance = round($drs[6]*100); 
				if( DB::select('SELECT COUNT(*) `count` FROM bank_transactions WHERE date = ? AND amount = ? AND type = ? AND description = ? AND balance = ?',
					array($t -> date, $t -> amount, $t -> type, $t -> description, $t -> balance ))[0]->count == 0){
						$t -> save();
					}
			}
		}
		return Redirect::to('/account/sysadmin/transactions');
	}
	
	public function linkBankTransaction($transactionid, $userid) {
		$t = BankTransaction::where('id', $transactionid)->first();
		
		if( ($user = User::where('id', $t['user_id'])->first()) != NULL){
			$old_user = $t['user'];
			$old_user['balance'] += - $t['amount'];
			$old_user -> save();
		}
		
		if( ($user = User::where('id', $userid)->first()) != NULL){
			$user['balance'] += $t['amount'];
			$user->save();
			$t['user_id'] = $userid;
		}else{
			$t['user_id'] = NULL;
		}
		$t->save();
		
		if(isset($user)){
			return $user['first_name'] . ' ' . ($user['middle_name'] ? $user['middle_name'].' ' : '') . $user['last_name'] ;
		}else{
			return '';
		}	
	}

	public function setPayout($user, $amount) {
		setlocale(LC_MONETARY, "en_US.UTF-8");
		if(strcmp($user,'all') == 0){
			$this->setPayout('beniac',$amount);
			$this->setPayout('bliss',$amount);
			$this->setPayout('brand',$amount);
			$this->setPayout('mcnulty',$amount);
			$this->setPayout('morris',$amount);
			$this->setPayout('pullar',$amount);
			$this->setPayout('straton',$amount);
			return money_format('%n',$amount/100).' was paid out to all 7 syndicate members. (Total of '.money_format('%n',7*$amount/100).' paid out)';
		}else{
			$t = new CashTransaction();
			$t->amount = -$amount;
			$t->type = 'PAYOUT';
			$t->description = $user;
			$t->save();
			return money_format('%n',$amount/100).' was paid out to '.$user;
		}
	}
	
	
	
	//============================= Helper Functions ===========================================
	//==========================================================================================
	
	private function getAssets(){
		$tab_assets = - DB::select('SELECT SUM(balance) `total` FROM users WHERE balance < 0')[0]->total;
		$bank_balance = ($t=BankTransaction::latest('date')->first())?$t->balance:0; 
		//DB::select('SELECT balance FROM bank_transactions ORDER BY date DESC LIMIT 0, 1')[0]->balance;
		$bank_balance = ($bank_balance > 0 ? $bank_balance : 0);
		$cash = DB::select('SELECT SUM(amount) `total` FROM cash_transactions')[0]->total - DB::select("SELECT SUM(amount) `total` FROM bank_transactions WHERE app_type = 'CASHDEPOSIT'")[0]->total;
		$stock_value = $this->getStockValue();
		return $tab_assets + $bank_balance + $cash + $stock_value;
	}
	
	private function getLiabilities(){
		$tab_liabilities = DB::select('SELECT SUM(balance) `total` FROM users WHERE balance > 0')[0]->total;
		$bank_balance = ($t=BankTransaction::latest('date')->first())?$t->balance:0;
		$bank_balance = ($bank_balance < 0 ? -$bank_balance : 0);
		return $tab_liabilities + $bank_balance;
	}

	private function getBankBalance(){
		$bank_balance = ($t=BankTransaction::latest('date')->first())?$t->balance:0;
		return $bank_balance;
	}
	
	private function getCashOnHand(){
		$bank_balance = ($t=BankTransaction::latest('date')->first())?$t->balance:0;
		$bank_balance = $bank_balance > 0 ? $bank_balance : 0;
		$cash = DB::select('SELECT SUM(amount) `total` FROM cash_transactions')[0]->total - DB::select("SELECT SUM(amount) `total` FROM bank_transactions WHERE app_type = 'CASHDEPOSIT'")[0]->total;
		return $bank_balance + $cash;
	}
	
	private function getStockValue(){
		return $this->getStockValueByDate(time());
	}

	private function getStockValueByDate($time){
		$stock_volumes = DB::select(
			'SELECT t1.ingredient_id, t1.volume, t1.timestamp FROM stocktakes t1 WHERE t1.timestamp = (SELECT MAX(t2.timestamp) FROM stocktakes t2 WHERE t2.timestamp <= FROM_UNIXTIME(?) AND t2.ingredient_id = t1.ingredient_id)', array($time));
		$stock_value = 0;
		foreach($stock_volumes as $stock_volume){
			$purchases = DB::select('SELECT volume, cost, timestamp FROM purchases WHERE ingredient_id = ? AND timestamp < FROM_UNIXTIME(?) ORDER BY timestamp DESC', array($stock_volume->ingredient_id, $time));
			
			if(isset($purchases[0])){
				$j=0;
				while(isset($purchases[$j]) && $purchases[$j]->timestamp > $stock_volume->timestamp){
					//$stock_volume->volume += $purchases[$j]->volume;
					$stock_value += $purchases[$j]->cost;
					$j++;
				}
				
				$volume_iterated = 0;
				$i = 0;
				$value = 0;
				while(isset($purchases[$i]) && $volume_iterated <= $stock_volume->volume){
					$volume_iterated += $purchases[$i]->volume;
					$value += $purchases[$i]->cost;
					$i++;
				}
				$value += - ($volume_iterated - $stock_volume->volume) * ($purchases[$i-1]->cost / $purchases[$i-1]->volume);
				$stock_value += $value;
			}
		}
		
		return $stock_value;
	}

	private function getPayouts(){
		return -DB::select("SELECT SUM(amount) `total` FROM bank_transactions WHERE app_type = 'PAYOUT'")[0]->total
					-DB::select("SELECT SUM(amount) `total` FROM cash_transactions WHERE type = 'PAYOUT'")[0]->total;
	}
	
	private function getProfitTimeline(){
		//types :
		//cash - 1
		//bank - 2
		//purchase - 3
		//stocktake - 4
		//transaction - 5
		$a = array();
		$ct = CashTransaction::all();
		foreach ($ct as $c) {
			$data['type'] = 1;
			$data['value'] = $c;
			$time = strtotime($c['timestamp']);
			while(isset($a[$time])){
				$time++;
			}
			$a[$time] = $data;
		}
		$bt = BankTransaction::all();
		foreach ($bt as $c) {
			$data['type'] = 2;
			$data['value'] = $c;
			$time = strtotime($c['date']);
			while(isset($a[$time])){
				$time++;
			}
			$a[$time] = $data;
		}
		$purchases = Purchase::all();
		foreach ($purchases as $c) {
			$data['type'] = 3;
			$data['value'] = $c;
			$time = strtotime($c['timestamp']);
			while(isset($a[$time])){
				$time++;
			}
			$a[$time] = $data;
		}
		$stocktakes = Stocktake::all();
		foreach ($stocktakes as $c) {
			$data['type'] = 4;
			$data['value'] = $c;
			$time = strtotime($c['timestamp']);
			while(isset($a[$time])){
				$time++;
			}
			$a[$time] = $data;
		}
		$transactions = Transaction::all();
		foreach ($transactions as $c) {
			$data['type'] = 5;
			$data['value'] = $c;
			$time = strtotime($c['timestamp']);
			while(isset($a[$time])){
				$time++;
			}
			$a[$time] = $data;
		}
		ksort($a);
		$payouts = 0;
		$stock_value = 0;
		$cash_balance = 0;
		$bank_balance = 0;
		$positive_tab_balance = 0;
		$negative_tab_balance = 0;
		$output = array();
		foreach ($a as $time => $c) {
			switch ($c['type']) {
			    case 1: //cash-transaction
			    	if(strcmp($c['value']['type'], 'PAYOUT')==0){
			    		$payouts += -$c['value']['amount'];
			    	}elseif(strcmp($c['value']['type'], 'TABCREDIT')==0){
			    		$balance = -DB::select("SELECT SUM(quantity*price) balance FROM transactions WHERE user_id=? AND timestamp <= ?",
			    								 array($c['value']['user_id'], $c['value']['timestamp']))[0]->balance
												 +DB::select("SELECT SUM(amount) balance FROM bank_transactions WHERE app_type='TABCREDIT' AND user_id=? AND date <= ?",
			    								 array($c['value']['user_id'], $c['value']['timestamp']))[0]->balance
												 +DB::select("SELECT SUM(amount) balance FROM cash_transactions WHERE type='TABCREDIT' AND user_id=? AND timestamp <= ?",
			    								 array($c['value']['user_id'], $c['value']['timestamp']))[0]->balance;
						if($c['value']['amount']>0){
			    			$positive_tab_balance += ($balance+$c['value']['amount'])<0 ? 0 : ( ($balance<0) ? $balance+$c['value']['amount'] : $c['value']['amount'] );
			    			$negative_tab_balance += ($balance+$c['value']['amount'])<0 ? -$c['value']['amount'] : ( ($balance<0) ? -($balance) : 0 );
						}else{
							$positive_tab_balance += ($balance+$c['value']['amount'])>0 ? $c['value']['amount'] : ( ($balance>0) ? -($balance) : 0 );
			    			$negative_tab_balance += ($balance+$c['value']['amount'])>0 ? 0 : ( ($balance>0) ? -($balance+$c['value']['amount']) : -$c['value']['amount'] );
						}
					}elseif(strcmp($c['value']['type'], 'PURCHASE')==0){
			    		$stock_value += -$c['value']['amount'];
			    	}
			        $cash_balance += $c['value']['amount'];
			        break;
			    case 2: //bank-transaction
			    	if(strcmp($c['value']['app_type'], 'CASHDEPOSIT') == 0) $cash_balance += -$c['value']['amount'];
					if(strcmp($c['value']['app_type'], 'PURCHASE') == 0) $stock_value += -$c['value']['amount'];
					if(strcmp($c['value']['app_type'], 'PAYOUT') == 0) $payouts += -$c['value']['amount'];
					if(strcmp($c['value']['app_type'], 'TABCREDIT') == 0) {
						$balance = -DB::select("SELECT SUM(quantity*price) balance FROM transactions WHERE user_id=? AND timestamp <= ?",
			    								 array($c['value']['user_id'], $c['value']['timestamp']))[0]->balance
												 +DB::select("SELECT SUM(amount) balance FROM bank_transactions WHERE app_type='TABCREDIT' AND user_id=? AND date <= ?",
			    								 array($c['value']['user_id'], $c['value']['timestamp']))[0]->balance
												 +DB::select("SELECT SUM(amount) balance FROM cash_transactions WHERE type='TABCREDIT' AND user_id=? AND timestamp <= ?",
			    								 array($c['value']['user_id'], $c['value']['timestamp']))[0]->balance;
						if($c['value']['amount']>0){
			    			$positive_tab_balance += ($balance+$c['value']['amount'])<0 ? 0 : ( ($balance<0) ? $balance+$c['value']['amount'] : $c['value']['amount'] );
			    			$negative_tab_balance += ($balance+$c['value']['amount'])<0 ? -$c['value']['amount'] : ( ($balance<0) ? -($balance) : 0 );
						}else{
							$positive_tab_balance += ($balance+$c['value']['amount'])>0 ? $c['value']['amount'] : ( ($balance>0) ? -($balance) : 0 );
			    			$negative_tab_balance += ($balance+$c['value']['amount'])>0 ? 0 : ( ($balance>0) ? -($balance+$c['value']['amount']) : -$c['value']['amount'] );
						}
					}
			        $bank_balance = $c['value']['balance'];
			        break;
				case 3: //purchase
			      	$stock_value = $this->getStockValueByDate(strtotime($c['value']['timestamp']));
			        break;
				case 4: //stocktake
			      	$stock_value = $this->getStockValueByDate(strtotime($c['value']['timestamp']));
			        break;
				case 5: //transaction
			        $balance = -DB::select("SELECT SUM(quantity*price) balance FROM transactions WHERE user_id=? AND timestamp <= ?",
		    								 array($c['value']['user_id'], $c['value']['timestamp']))[0]->balance
											 +DB::select("SELECT SUM(amount) balance FROM bank_transactions WHERE app_type='TABCREDIT' AND user_id=? AND date <= ?",
		    								 array($c['value']['user_id'], $c['value']['timestamp']))[0]->balance
											 +DB::select("SELECT SUM(amount) balance FROM cash_transactions WHERE type='TABCREDIT' AND user_id=? AND timestamp <= ?",
		    								 array($c['value']['user_id'], $c['value']['timestamp']))[0]->balance;
					if(-$c['value']['quantity']*$c['value']['price']>0){
		    			$positive_tab_balance += ($balance-$c['value']['quantity']*$c['value']['price'])<0 ? 0 : ( ($balance<0) ? $balance-$c['value']['quantity']*$c['value']['price'] : -$c['value']['quantity']*$c['value']['price'] );
		    			$negative_tab_balance += ($balance-$c['value']['quantity']*$c['value']['price'])<0 ? $c['value']['quantity']*$c['value']['price'] : ( ($balance<0) ? -($balance) : 0 );
					}else{
						$positive_tab_balance += ($balance-$c['value']['quantity']*$c['value']['price'])>0 ? -$c['value']['quantity']*$c['value']['price'] : ( ($balance>0) ? -($balance) : 0 );
		    			$negative_tab_balance += ($balance-$c['value']['quantity']*$c['value']['price'])>0 ? 0 : ( ($balance>0) ? -($balance-$c['value']['quantity']*$c['value']['price']) : +$c['value']['quantity']*$c['value']['price'] );
					}
			        break;
			}
			$output[] = ['time' => date('c',$time), 
						'assets' => ($stock_value + $cash_balance + ($bank_balance>0 ? $bank_balance : 0) + $negative_tab_balance) / 100, 
						'liabilities' => (($bank_balance<0 ? -$bank_balance : 0) + $positive_tab_balance) / 100,
						'payouts' => $payouts/100,
						'profit' => ($payouts + $stock_value + $cash_balance + $bank_balance + $negative_tab_balance - $positive_tab_balance)/100];
		}
		if(isset($output))
			return json_encode($output);
		
	}
	
	private function getBankBalanceTimeline() {
		$bank_transactions = BankTransaction::all();
		if(isset($bank_transactions))
		foreach ($bank_transactions as $transaction) {
			$account_balance[] = ['time' => $transaction->date, 'balance' => $transaction->balance / 100];
		};
		if(isset($account_balance))
			return json_encode($account_balance);
	}

	private function getAccountBalanceTimeline($user) {
		$account_balance[] = ['time' => $user -> date_created, 'balance' => 0];
		
		$transactions = DB::select("SELECT timestamp, (-quantity*price) AS amount FROM transactions WHERE user_id = ? UNION ALL
			SELECT date, amount FROM bank_transactions WHERE user_id = ? UNION ALL
			SELECT timestamp, amount FROM cash_transactions WHERE user_id = ?
			ORDER BY timestamp", array($user->id, $user->id, $user->id));
		$balance = 0;
		foreach($transactions as $t){
			$account_balance[] = ['time' => $t->timestamp, 'balance' => ($balance += $t->amount)/ 100];
		}
		$account_balance[] = ['time' => date('Y-m-d G:i:s'), 'balance' => $user -> balance / 100];
		if(isset($account_balance))
			return json_encode($account_balance);
	}

	private function getSkuCount($user) {
		if(isset($user)){
			$transactions = $user->transaction;
		} else {
			$transactions = Transaction::all();
		}
		foreach ($transactions as $transaction) {
			if ($transaction['sku'] != NULL) {
				if (!isset($skus[$transaction['sku']['id']])) {
					$skus[$transaction['sku']['id']]['label'] = $transaction['sku']['description'];
					$skus[$transaction['sku']['id']]['value'] = 0;
				}
				$skus[$transaction['sku']['id']]['value'] = $skus[$transaction['sku']['id']]['value'] + $transaction['quantity'];
			}
		}

		if (isset($skus))
			return json_encode(array_values($skus));
	}

}
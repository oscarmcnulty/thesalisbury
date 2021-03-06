<?php
use Facebook\FacebookSession;
use Facebook\FacebookRequest;
use Facebook\GraphUser;
class SysadminController extends BaseController {
	protected $layout = 'account.master';
	
	// ============================= Begin SysAdmin User Pages =====================================
	// ==========================================================================================
	public function getSysadminDashboard() {
		$data = app ( 'request_data' );
		$data ['user'] = User::where ( 'id', $data ['id'] )->first ();
		$data ['user']->load ( 'transaction', 'transaction.sku', 'bankTransaction' );
		
		$data ['sku_count'] = $this->getSkuCount ( NULL );
		$data ['bank_timeline'] = $this->getBankBalanceTimeline ();
		$data ['profit_timeline'] = $this->getProfitTimeline ();
		
		$data ['assets'] = $this->getAssets ();
		$data ['liabilities'] = $this->getLiabilities ();
		$data ['cash_on_hand'] = $this->getCashOnHand ();
		$data ['revenue_24h'] = DB::select( 'SELECT SUM(price*quantity)+0 `total` FROM transactions WHERE timestamp > (NOW()-INTERVAL 1 DAY)')[0]->total;
		$data ['revenue_7d'] = DB::select( 'SELECT SUM(price*quantity)+0 `total` FROM transactions WHERE timestamp > (NOW()-INTERVAL 7 DAY)')[0]->total;
		$data ['revenue_todate'] = DB::select( 'SELECT SUM(price*quantity)+0 `total` FROM transactions')[0]->total;
		$data ['standard_drinks'] = DB::select('SELECT sum(t.quantity*s.standard_drinks)/100.0 `total` FROM transactions AS t LEFT JOIN skus AS s ON t.sku_id = s.id')[0]->total;
		$data ['net_tabs'] = DB::select ( 'SELECT SUM(balance) `total` FROM users' )[0]->total;
		$data ['equity'] = $data ['assets'] - $data ['liabilities'];
		$data ['payout'] = $this->getPayouts ();
		$data ['takings_to_date'] = $data ['equity'] + $data ['payout'];
		
		$data ['location'] = 'SysAdmin Dashboard';
		$data ['description'] = 'An overview of your empire';
		$this->layout->content = View::make ( 'sysadmin.dashboard', $data );
	}
	public function getSysadminOperations() {
		$data = app ( 'request_data' );
		$data ['user'] = User::where ( 'id', $data ['id'] )->first ();
		$data ['user']->load ( 'transaction', 'transaction.sku', 'bankTransaction' );
		
		$data ['users'] = User::all ()->sortBy ( 'first_name' );
		
		$boys = array (
				'twr',
				'clarkson',
				'chernov',
				'biscoe',
				'morgan',
				'mcclelland' 
		);
		
		foreach ( $boys as $lad ) {
			$data ['payouts'] [$lad] = - DB::select ( 'SELECT SUM(amount) `total` FROM cash_transactions WHERE app_type = \'PAYOUT\' AND app_description = ?', array (
					$lad 
			) )[0]->total / 100 - DB::select ( "SELECT SUM(amount) `total` FROM bank_transactions WHERE app_type = 'PAYOUT' AND app_description = ?", array (
					$lad 
			) )[0]->total / 100;
		}
		
		$data ['location'] = 'Operations';
		$data ['description'] = 'Admin Operations';
		$this->layout->content = View::make ( 'sysadmin.operations', $data );
	}
	public function getSysadminTransactions() {
		$data = app ( 'request_data' );
		$data ['user'] = User::where ( 'id', $data ['id'] )->first ();
		$data ['location'] = 'Bank Transactions';
		$data ['description'] = 'A list of all bank transactions';
		$data ['transactions'] = BankTransaction::all ();
		$data ['transactions']->load ( 'user' );
		$data ['users'] = User::all ()->sortBy ( 'first_name' );
		
		$this->layout->content = View::make ( 'sysadmin.transactions', $data );
	}
	public function getSysadminCashTransactions() {
		$data = app ( 'request_data' );
		$data ['user'] = User::where ( 'id', $data ['id'] )->first ();
		$data ['location'] = 'Cash Transactions';
		$data ['description'] = 'A list of all cash transactions';
		$data ['transactions'] = CashTransaction::all ();
		$data ['transactions']->load ( 'user' );
		$data ['users'] = User::all ()->sortBy ( 'first_name' );
		
		$deposits = BankTransaction::where ( 'app_type', 'CASHDEPOSIT' )->get ();
		foreach ( $deposits as $deposit ) {
			$transaction = new CashTransaction ();
			$transaction->amount = - $deposit->amount;
			$transaction->type = 'CASHDEPOSIT';
			$transaction->timestamp = date ( 'c', strtotime ( $deposit->date ) );
			$data ['transactions']->add ( $transaction );
		}
		
		$this->layout->content = View::make ( 'sysadmin.cashtransactions', $data );
	}
	// Endpoint called from operations view to download transactions of all types
	public function getCSV() {
		
		// types :
		// cash - 1
		// bank - 2
		// transaction - 5
		$a = DB::select ( "SELECT 1 AS type, timestamp, amount, app_type AS transaction_type, NULL AS balance FROM cash_transactions UNION ALL
			SELECT 2, date, amount, app_type, balance FROM bank_transactions UNION ALL
			SELECT 5, timestamp, (quantity*price), NULL, NULL AS amount FROM transactions
			ORDER BY timestamp" );
		
		$payouts = 0;
		$stock_value = 0;
		$cash_balance = 0;
		$bank_balance = 0;
		$tab_assets = 0; // positive is asset, negative liability.
		$output = array ();
		foreach ( $a as $c ) {
			switch ($c->type) {
				case 1 : // cash-transaction
					if ($c->transaction_type === 'PAYOUT') {
						$payouts += - $c->amount;
					} elseif (strcmp ( $c->transaction_type, 'TABCREDIT' ) == 0) {
						$tab_assets -= $c->amount;
					} elseif ($c->transaction_type === 'PURCHASE') {
						$stock_value -= $c->amount;
					}
					$cash_balance += $c->amount;
					break;
				case 2 : // bank-transaction
					if ($c->transaction_type === 'CASHDEPOSIT')
						$cash_balance -= $c->amount;
					if ($c->transaction_type === 'PURCHASE')
						$stock_value -= $c->amount;
					if ($c->transaction_type === 'PAYOUT')
						$payouts -= $c->amount;
					if ($c->transaction_type === 'TABCREDIT')
						$tab_assets -= $c->amount;
					$bank_balance = $c->balance;
					break;
				case 3 : // purchase
					$stock_value = $this->getStockValueByDate ( $c->timestamp );
					break;
				case 4 : // stocktake
					$stock_value = $this->getStockValueByDate ( $c->timestamp );
					break;
				case 5 : // transaction
					$tab_assets += $c->amount;
					break;
			}
		}
		
		// the csv file with the first row
		$output = implode ( ",", array (
				'type',
				'timestamp',
				'amount',
				'app_type' 
		) );
		
		foreach ( $a as $row ) {
			switch ($row->type) {
				case 1 : // cash-transaction
					$output .= implode ( ",", array (
							'cash transaction',
							$row -> timestamp,
							$row -> amount,
							$row -> transaction_type
					) ); // append each row
					$output .= "\r\n";
					break;
				case 2 : // bank-transaction
					$output .= implode ( ",", array (
							'bank transaction',
							$row -> timestamp,
							$row -> amount,
							$row -> transaction_type
					) ); // append each row
					$output .= "\r\n";
					break;
				case 5 : // tab-transaction
					$output .= implode ( ",", array (
							'tab transaction',
							$row -> timestamp,
							$row -> amount,
							$row -> transaction_type
					) ); // append each row
					$output .= "\r\n";
					break;
			}
			
		}
		
		// headers used to make the file "downloadable", we set them manually
		// since we can't use Laravel's Response::download() function
		$headers = array (
				'Content-Type' => 'text/csv',
				'Content-Disposition' => 'attachment; filename="transactions.csv"' 
		);
		
		// our response, this will be equivalent to your download() but
		// without using a local file
		return Response::make ( rtrim ( $output, "\n" ), 200, $headers );
	}
	
	// ============================ Super User AJAX Functions ===================================
	// ==========================================================================================
	public function updateCash($amount) {
		$current = DB::select ( 'SELECT SUM(amount) `total` FROM cash_transactions' )[0]->total - DB::select ( "SELECT SUM(amount) `total` FROM bank_transactions WHERE app_type = 'CASHDEPOSIT'" )[0]->total;
		$difference = $amount - $current;
		$transaction = new CashTransaction ();
		$transaction->amount = $difference;
		$transaction->app_type = "CASHRECONCILIATION";
		$transaction->save ();
		return money_format ( '%n', $amount / 100 );
	}
	// Endpoint called from opertions view to add cash purchase
	public function updatePurchase($cost, $desc) {
		$transaction = new CashTransaction ();
		$transaction->amount = -$cost;
		$transaction->app_type = "CASHPURCHASE";
		$transaction->app_description = urldecode($desc);
		$transaction->save ();
		return money_format ( '%n', $cost / 100 );
	}
	// Endpoint called from operations view to add credit to a tab account
	public function updateCredit($id, $amount) {
		$t = new CashTransaction ();
		$t->user_id = $id;
		$t->amount = $amount;
		$t->app_type = 'TABCREDIT';
		$t->save ();
		$user = User::where ( 'id', $id )->first ();
		$user ['balance'] += $amount;
		$user->save ();
		return $user ['first_name'] . ' ' . $user ['last_name'] . ' was credited with ' . money_format ( '%n', $amount / 100 );
	}
	// Called from ank transactio view to update individual bank transaction records
	public function setBankTransactionType($transactionid, $type) {
		$t = BankTransaction::where ( 'id', $transactionid )->first ();
		
		if (($old_user = User::where ( 'id', $t ['user_id'] )->first ()) != NULL) {
			$old_user ['balance'] += - $t ['amount'];
			$old_user->save ();
			$t ['user_id'] = NULL;
		}
		if (strcmp ( $type, 'NONE' ) == 0) {
			$t ['app_type'] = NULL;
			$t ['app_description'] = NULL;
			$t->save ();
			return '';
		} else {
			$t ['app_type'] = $type;
			$t ['app_description'] = NULL;
			$t->save ();
			return $type;
		}
	}
	public function setBankTransactionPayoutType($transactionid, $type, $user) {
		$t = BankTransaction::where ( 'id', $transactionid )->first ();
		
		if (($old_user = User::where ( 'id', $t ['user_id'] )->first ()) != NULL) {
			$old_user ['balance'] += - $t ['amount'];
			$old_user->save ();
			$t ['user_id'] = NULL;
		}
		$t ['app_type'] = $type;
		$t ['app_description'] = $user;
		$t->save ();
		
		return $type . ' ' . $user;
	}
	public function setCashTransactionType($transactionid, $type) {
		$t = CashTransaction::where ( 'id', $transactionid )->first ();
		
		if (($old_user = User::where ( 'id', $t ['user_id'] )->first ()) != NULL) {
			$old_user ['balance'] += - $t ['amount'];
			$old_user->save ();
			$t ['user_id'] = NULL;
		}
		$t ['app_type'] = $type;
		
		$t->save ();
		
		return $type;
	}
	public function uploadBankTransactions() {
		if (Input::hasFile ( 'csv' )) {
			$file = Input::file ( 'csv' );
			$handle = fopen ( $file, 'r' );
			while ( ($drs = fgetcsv ( $handle, 1000, "," )) !== false ) {
				if ($drs [0] !== null) {
					$t = new BankTransaction ();
					$t->date = date ( 'c', strtotime ( $drs [0] ) );
					$t->amount = round ( $drs [1] * 100 );
					$t->type = $drs [4];
					$t->description = $drs [5];
					$t->balance = round ( $drs [6] * 100 );
					if (DB::select ( 'SELECT COUNT(*) `count` FROM bank_transactions WHERE amount = ? AND type = ? AND balance = ?', array (
							$t->amount,
							$t->type,
							$t->balance 
					) )[0]->count == 0) {
						$t->save ();
					}
				}
			}
		}
		return Redirect::to ( '/account/sysadmin/transactions' );
	}
	public function linkBankTransaction($transactionid, $userid) {
		$t = BankTransaction::where ( 'id', $transactionid )->first ();
		
		if (($user = User::where ( 'id', $t ['user_id'] )->first ()) != NULL) {
			$old_user = $t ['user'];
			$old_user ['balance'] += - $t ['amount'];
			$old_user->save ();
		}
		
		if (($user = User::where ( 'id', $userid )->first ()) != NULL) {
			$user ['balance'] += $t ['amount'];
			$user->save ();
			$t ['user_id'] = $userid;
			$t ['app_type'] = 'TABCREDIT';
		} else {
			$t ['user_id'] = NULL;
		}
		$t->save ();
		
		if (isset ( $user )) {
			return $user ['first_name'] . ' ' . ($user ['middle_name'] ? $user ['middle_name'] . ' ' : '') . $user ['last_name'];
		} else {
			return '';
		}
	}
	
	public function setNFCTag($user, $tag){
		if (($user_object = User::where ( 'id', $user )->first ()) != NULL){
			$newtag = new Tag();
			$newtag->user_id = $user;
			$newtag->id = $tag;
			$newtag->description = 'Salisbury Card';
			$newtag->save();
			return 'Salisbury Card added to '. $user_object->first_name . '\'s account';
		}
		return 'Failed';
	}
	
	// ============================= Helper Functions ===========================================
	// ==========================================================================================
	private function getAssets() {
		$tab_assets = - DB::select ( 'SELECT SUM(balance) `total` FROM users WHERE balance <= 0' )[0]->total;
		$bank_balance = ($t = BankTransaction::latest ( 'date' )->first ()) ? $t->balance : 0;
		// DB::select('SELECT balance FROM bank_transactions ORDER BY date DESC LIMIT 0, 1')[0]->balance;
		$bank_balance = ($bank_balance > 0 ? $bank_balance : 0);
		$cash = DB::select ( 'SELECT SUM(amount) `total` FROM cash_transactions' )[0]->total - DB::select ( "SELECT SUM(amount) `total` FROM bank_transactions WHERE app_type = 'CASHDEPOSIT'" )[0]->total;
		return $tab_assets + $bank_balance + $cash;
	}
	private function getLiabilities() {
		$tab_liabilities = DB::select ( 'SELECT SUM(balance) `total` FROM users WHERE balance > 0' )[0]->total;
		$bank_balance = ($t = BankTransaction::latest ( 'date' )->first ()) ? $t->balance : 0;
		$bank_balance = ($bank_balance < 0 ? - $bank_balance : 0);
		return $tab_liabilities + $bank_balance;
	}
	private function getBankBalance() {
		$bank_balance = ($t = BankTransaction::latest ( 'date' )->first ()) ? $t->balance : 0;
		return $bank_balance;
	}
	private function getCashOnHand() {
		$bank_balance = ($t = BankTransaction::latest ( 'date' )->first ()) ? $t->balance : 0;
		$bank_balance = $bank_balance > 0 ? $bank_balance : 0;
		$cash = DB::select ( 'SELECT SUM(amount) `total` FROM cash_transactions' )[0]->total - DB::select ( "SELECT SUM(amount) `total` FROM bank_transactions WHERE app_type = 'CASHDEPOSIT'" )[0]->total;
		return $bank_balance + $cash;
	}
	private function getPayouts() {
		return - DB::select ( "SELECT SUM(amount) `total` FROM bank_transactions WHERE app_type = 'PAYOUT'" )[0]->total - DB::select ( "SELECT SUM(amount) `total` FROM cash_transactions WHERE app_type = 'PAYOUT'" )[0]->total;
	}
	private function getProfitTimeline() {
		// types :
		// cash - 1
		// bank - 2
		// transaction - 5
		$a = DB::select ( "SELECT 1 AS type, timestamp, amount, app_type AS transaction_type, NULL AS balance FROM cash_transactions UNION ALL
			SELECT 2, date, amount, app_type, balance FROM bank_transactions UNION ALL
			SELECT 5, timestamp, (quantity*price), NULL, NULL AS amount FROM transactions
			ORDER BY timestamp" );
		
		$payouts = 0;
		$stock_value = 0;
		$cash_balance = 0;
		$bank_balance = 0;
		$tab_assets = 0; // positive is asset, negative liability.
		$output = array ();
		foreach ( $a as $c ) {
			switch ($c->type) {
				case 1 : // cash-transaction
					if ($c->transaction_type === 'PAYOUT') {
						$payouts += - $c->amount;
					} elseif (strcmp ( $c->transaction_type, 'TABCREDIT' ) == 0) {
						$tab_assets -= $c->amount;
					} elseif ($c->transaction_type === 'PURCHASE') {
						$stock_value -= $c->amount;
					}
					$cash_balance += $c->amount;
					break;
				case 2 : // bank-transaction
					if ($c->transaction_type === 'CASHDEPOSIT')
						$cash_balance -= $c->amount;
					if ($c->transaction_type === 'PURCHASE')
						$stock_value -= $c->amount;
					if ($c->transaction_type === 'PAYOUT')
						$payouts -= $c->amount;
					if ($c->transaction_type === 'TABCREDIT')
						$tab_assets -= $c->amount;
					$bank_balance = $c->balance;
					break;
				case 5 : // transaction
					$tab_assets += $c->amount;
					break;
			}
			$output [] = [ 
					'time' => date ( 'c', strtotime ( $c->timestamp ) ),
					'equity' => ($stock_value + $cash_balance + $bank_balance + $tab_assets) / 100,
					'cash' => (($bank_balance > 0) ? $bank_balance : 0 + ($cash_balance > 0) ? $cash_balance : 0) / 100,
					'payouts' => $payouts / 100,
					'profit' => ($payouts + $stock_value + $cash_balance + $bank_balance + $tab_assets) / 100 
			];
		}
		// dd($stock_value);
		if (isset ( $output ))
			return json_encode ( $output );
	}
	private function getBankBalanceTimeline() {
		$bank_transactions = BankTransaction::all ();
		if (isset ( $bank_transactions ))
			foreach ( $bank_transactions as $transaction ) {
				$account_balance [] = [ 
						'time' => $transaction->date,
						'balance' => $transaction->balance / 100 
				];
			}
		;
		if (isset ( $account_balance ))
			return json_encode ( $account_balance );
	}
	private function getAccountBalanceTimeline($user) {
		$account_balance [] = [ 
				'time' => $user->date_created,
				'balance' => 0 
		];
		
		$transactions = DB::select ( "SELECT timestamp, (-quantity*price) AS amount FROM transactions WHERE user_id = ? UNION ALL
			SELECT date, amount FROM bank_transactions WHERE user_id = ? UNION ALL
			SELECT timestamp, amount FROM cash_transactions WHERE user_id = ?
			ORDER BY timestamp", array (
				$user->id,
				$user->id,
				$user->id 
		) );
		$balance = 0;
		foreach ( $transactions as $t ) {
			$account_balance [] = [ 
					'time' => $t->timestamp,
					'balance' => ($balance += $t->amount) / 100 
			];
		}
		$account_balance [] = [ 
				'time' => date ( 'Y-m-d G:i:s' ),
				'balance' => $user->balance / 100 
		];
		if (isset ( $account_balance ))
			return json_encode ( $account_balance );
	}
	private function getSkuCount($user) {
		if (isset ( $user )) {
			$transactions = $user->transaction;
		} else {
			$transactions = Transaction::all ();
		}
		foreach ( $transactions as $transaction ) {
			if ($transaction ['sku'] != NULL) {
				if (! isset ( $skus [$transaction ['sku'] ['id']] )) {
					$skus [$transaction ['sku'] ['id']] ['label'] = $transaction ['sku'] ['description'];
					$skus [$transaction ['sku'] ['id']] ['value'] = 0;
				}
				$skus [$transaction ['sku'] ['id']] ['value'] = $skus [$transaction ['sku'] ['id']] ['value'] + $transaction ['quantity'];
			}
		}
		
		if (isset ( $skus ))
			return json_encode ( array_values ( $skus ) );
	}
}

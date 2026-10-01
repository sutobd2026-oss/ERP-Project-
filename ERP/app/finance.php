<?php
declare(strict_types=1);

/* Shared finance helpers required by Dashboard, Cash, Banking, Expenses,
 * Transaction Entry, Returns and Delivery Challan modules. */

function normalize_payment_method(PDO $pdo,int $cid,string $rawMethod,?string $legacyAccount=null): array {
    $rawMethod=trim($rawMethod);
    if($rawMethod==='cash') return ['cash',null];
    if($rawMethod==='cheque') return ['cheque',null];
    if(str_starts_with($rawMethod,'bank|')) {
        $bankId=(int)substr($rawMethod,5);
        if($bankId<=0) throw new RuntimeException('Select a valid bank account.');
        $st=$pdo->prepare('SELECT id,name FROM bank_accounts WHERE id=? AND company_id=? AND active=1 LIMIT 1');
        $st->execute([$bankId,$cid]);
        $bank=$st->fetch();
        if(!$bank) throw new RuntimeException('Selected bank account is invalid or inactive.');
        return ['bank',(string)$bank['name']];
    }
    if($rawMethod==='bank' && trim((string)$legacyAccount)!=='') {
        $st=$pdo->prepare('SELECT id,name FROM bank_accounts WHERE company_id=? AND name=? AND active=1 LIMIT 1');
        $st->execute([$cid,trim((string)$legacyAccount)]);
        $bank=$st->fetch();
        if(!$bank) throw new RuntimeException('Selected bank account is invalid or inactive.');
        return ['bank',(string)$bank['name']];
    }
    throw new RuntimeException('Invalid payment method.');
}

function payment_select_options(array $bankRows,string $selectedMethod='cash',string $selectedAccount=''): string {
    $selected=$selectedMethod==='cheque' ? 'cheque' : ($selectedMethod==='cash' ? 'cash' : '');
    if($selectedMethod==='bank') {
        foreach($bankRows as $b){if((string)$b['name']===$selectedAccount){$selected='bank|'.(int)$b['id'];break;}}
    }
    $html='<option value="cash"'.($selected==='cash'?' selected':'').'>Cash</option>';
    $html.='<option value="cheque"'.($selected==='cheque'?' selected':'').'>Cheque</option>';
    foreach($bankRows as $b){
        $v='bank|'.(int)$b['id']; $label=(string)$b['name'];
        if(!empty($b['bank_name'])) $label.=' · '.(string)$b['bank_name'];
        $html.='<option value="'.e($v).'"'.($selected===$v?' selected':'').'>'.e($label).'</option>';
    }
    return $html;
}

function payment_account_code(string $method, ?string $account=null): array {
    $account=trim((string)($account??''));
    return match($method) {
        'cash' => ['1000','Cash In Hand'],
        'bank' => ['1010','Bank - '.($account ?: 'Bank Account')],
        'cheque' => ['1020','Cheque / Clearing'],
        default => throw new RuntimeException('Unsupported payment method.'),
    };
}

function company_cash_balance(int $cid): float {
    /* Exclude ledger rows belonging to transactions moved to Recycle Bin. */
    $st=db()->prepare("SELECT
        COALESCE((SELECT opening_balance FROM cash_accounts WHERE company_id=? AND active=1 LIMIT 1),0)
        + COALESCE((
            SELECT SUM(le.debit-le.credit)
            FROM ledger_entries le
            LEFT JOIN transactions t ON t.id=le.transaction_id AND t.company_id=le.company_id
            WHERE le.company_id=?
              AND le.account_code IN ('1000','1100')
              AND (le.transaction_id IS NULL OR (t.id IS NOT NULL AND t.deleted_at IS NULL))
        ),0) AS bal");
    $st->execute([$cid,$cid]);
    return (float)$st->fetchColumn();
}

function bank_balance(int $cid,int $bankId): float {
    $st=db()->prepare('SELECT b.opening_balance + COALESCE(SUM(CASE WHEN le.account_code=? THEN le.debit-le.credit ELSE 0 END),0) FROM bank_accounts b LEFT JOIN ledger_entries le ON le.company_id=b.company_id AND le.transaction_id IS NOT NULL AND le.account_name=CONCAT("Bank - ",b.name) LEFT JOIN transactions t ON t.id=le.transaction_id AND t.company_id=le.company_id WHERE b.company_id=? AND b.id=? AND (le.transaction_id IS NULL OR (t.id IS NOT NULL AND t.deleted_at IS NULL)) GROUP BY b.id');
    $st->execute(['1010',$cid,$bankId]);
    return (float)$st->fetchColumn();
}

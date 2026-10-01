<?php
/* sense modular v1 route module extracted from the current public/index.php master. */
if($route==='expense-new' || $route==='expense'){
    $u=require_login(); $cid=(int)$u['company_id']; $pdo=db();

    /* v87: Expense masters. */
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        $masterAction=$_POST['action']??'';
        if(in_array($masterAction,['save_expense_category','edit_expense_category','delete_expense_category','save_expense_item','edit_expense_item','delete_expense_item'],true)){
            try{
                if($masterAction==='save_expense_category'){
                    $name=trim($_POST['name']??''); $type=$_POST['expense_type']??'indirect';
                    if($name==='') throw new RuntimeException('Category name is required.');
                    if(!in_array($type,['direct','indirect'],true)) throw new RuntimeException('Invalid expense category type.');
                    $pdo->prepare('INSERT INTO expense_categories(company_id,name,expense_type,active) VALUES(?,?,?,1)')->execute([$cid,$name,$type]);
                    audit('create','expense_category',(int)$pdo->lastInsertId(),['name'=>$name,'expense_type'=>$type]); flash('success','Expense category added.'); redirect('expense');
                }
                if($masterAction==='edit_expense_category'){
                    $id=(int)($_POST['id']??0); $name=trim($_POST['name']??''); $type=$_POST['expense_type']??'indirect';
                    if($id<=0||$name==='') throw new RuntimeException('Invalid expense category.');
                    if(!in_array($type,['direct','indirect'],true)) throw new RuntimeException('Invalid expense category type.');
                    $pdo->prepare('UPDATE expense_categories SET name=?,expense_type=? WHERE id=? AND company_id=?')->execute([$name,$type,$id,$cid]); audit('update','expense_category',$id,['name'=>$name,'expense_type'=>$type]); flash('success','Expense category updated.'); redirect('expense');
                }
                if($masterAction==='delete_expense_category'){
                    $id=(int)($_POST['id']??0); if($id<=0) throw new RuntimeException('Invalid expense category.');
                    $q=$pdo->prepare('SELECT name FROM expense_categories WHERE id=? AND company_id=? AND active=1');$q->execute([$id,$cid]);$name=$q->fetchColumn(); if($name===false) throw new RuntimeException('Expense category not found.');
                    $use=$pdo->prepare('SELECT COUNT(*) FROM expense_details WHERE company_id=? AND category=?');$use->execute([$cid,$name]);
                    if((int)$use->fetchColumn()>0){$pdo->prepare('UPDATE expense_categories SET active=0 WHERE id=? AND company_id=?')->execute([$id,$cid]);$msg='Category is used by existing expenses, so it was deactivated.';} else {$pdo->prepare('DELETE FROM expense_categories WHERE id=? AND company_id=?')->execute([$id,$cid]);$msg='Expense category deleted.';}
                    audit('delete','expense_category',$id,['name'=>$name]); flash('success',$msg); redirect('expense');
                }
                if($masterAction==='save_expense_item'){
                    $name=trim($_POST['name']??'');
                    if($name==='') throw new RuntimeException('Expense item name is required.');
                    $pdo->prepare('INSERT INTO expense_items(company_id,name,category_id,active) VALUES(?,?,NULL,1)')->execute([$cid,$name]);
                    audit('create','expense_item',(int)$pdo->lastInsertId(),['name'=>$name]); flash('success','Expense item added.'); redirect('expense?tab=items');
                }
                if($masterAction==='edit_expense_item'){
                    $id=(int)($_POST['id']??0); $name=trim($_POST['name']??'');
                    if($id<=0||$name==='') throw new RuntimeException('Invalid expense item.');
                    $pdo->prepare('UPDATE expense_items SET name=?,category_id=NULL WHERE id=? AND company_id=?')->execute([$name,$id,$cid]);
                    audit('update','expense_item',$id,['name'=>$name]); flash('success','Expense item updated.'); redirect('expense?tab=items');
                }
                if($masterAction==='delete_expense_item'){
                    $id=(int)($_POST['id']??0); if($id<=0) throw new RuntimeException('Invalid expense item.');
                    $q=$pdo->prepare('SELECT name FROM expense_items WHERE id=? AND company_id=? AND active=1');$q->execute([$id,$cid]);$name=$q->fetchColumn(); if($name===false) throw new RuntimeException('Expense item not found.');
                    $use=$pdo->prepare('SELECT COUNT(*) FROM expense_item_links WHERE expense_item_id=?');$use->execute([$id]); $used=(int)$use->fetchColumn();
                    $pdo->prepare('UPDATE expense_items SET active=0 WHERE id=? AND company_id=?')->execute([$id,$cid]); audit('delete','expense_item',$id,['name'=>$name]); flash('success',$used>0?'Expense item moved to inactive because it has transactions.':'Expense item deleted.'); redirect('expense?tab=items');
                }
            }catch(Throwable $e){ flash('error',$e->getCode()==='23000'?'This name already exists.':$e->getMessage()); redirect('expense'.(in_array($masterAction,['save_expense_item','edit_expense_item','delete_expense_item'],true)?'?tab=items':'')); }
        }
    }

    /* Expense master screens: Categories and Items. */
    $masterTab=$_GET['tab']??'';
    if($masterTab==='categories'){
        $catQ=$pdo->prepare('SELECT id,name,expense_type,active FROM expense_categories WHERE company_id=? ORDER BY active DESC,name');
        $catQ->execute([$cid]); $masterCategories=$catQ->fetchAll(PDO::FETCH_ASSOC);
        page_start('Expense Categories');
        ?>
        <style>
        .exp-master{max-width:1180px;margin:0 auto;padding:4px 0 28px}.exp-master-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:16px}.exp-master-head h1{margin:0;color:#17324d;font-size:26px}.exp-master-head p{margin:5px 0 0;color:#718096;font-size:13px}.exp-tabs{display:flex;gap:6px;margin-bottom:14px}.exp-tab{padding:9px 14px;border:1px solid #d7dee7;border-radius:7px;text-decoration:none;color:#475569;background:#fff;font-size:13px}.exp-tab.active{background:#1877d2;border-color:#1877d2;color:#fff}.exp-master-grid{display:grid;grid-template-columns:330px 1fr;gap:14px}.exp-master-card{background:#fff;border:1px solid #e2e8f0;border-radius:9px;overflow:hidden}.exp-master-card-head{padding:14px 16px;border-bottom:1px solid #e2e8f0}.exp-master-card-head h2{margin:0;font-size:15px;color:#334155}.exp-master-body{padding:16px}.exp-field{margin-bottom:12px}.exp-field label{display:block;font-size:12px;color:#64748b;margin-bottom:6px}.exp-field input,.exp-field select{width:100%;height:40px;border:1px solid #cbd5e1;border-radius:6px;padding:0 10px;box-sizing:border-box;background:#fff}.exp-btn{height:38px;padding:0 15px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#334155;cursor:pointer}.exp-btn.primary{background:#1877d2;color:#fff;border-color:#1877d2}.exp-list{width:100%;border-collapse:collapse}.exp-list th{background:#f8fafc;color:#64748b;font-size:11px;text-align:left;padding:11px 12px;border-bottom:1px solid #e2e8f0}.exp-list td{padding:11px 12px;border-bottom:1px solid #edf2f7;font-size:13px;color:#334155;vertical-align:middle}.exp-actions{display:flex;gap:6px;flex-wrap:wrap}.exp-inline-form{display:flex;gap:6px;align-items:center}.exp-inline-form input,.exp-inline-form select{height:34px;border:1px solid #cbd5e1;border-radius:5px;padding:0 8px;box-sizing:border-box}.exp-inline-form input{width:220px}.exp-inline-form select{width:125px}.exp-danger{color:#b42318;border-color:#f1b5b0}.exp-badge{display:inline-block;padding:4px 8px;border-radius:999px;background:#f1f5f9;color:#475569;font-size:11px}.exp-badge.direct{background:#e0f2fe;color:#0369a1}.exp-badge.indirect{background:#f1f5f9;color:#475569}.exp-empty{text-align:center;color:#64748b;padding:28px}
        @media(max-width:850px){.exp-master-grid{grid-template-columns:1fr}.exp-master-head{flex-direction:column}}
        </style>
        <div class="exp-master">
          <div class="exp-master-head"><div><h1>Expense Categories</h1><p>Create and manage the categories used by your expense entries.</p></div><a class="exp-btn" href="<?=e(url('expense'))?>">← Back to Expenses</a></div>
          <div class="exp-tabs"><a class="exp-tab active" href="<?=e(url('expense?tab=categories'))?>">Categories</a><a class="exp-tab" href="<?=e(url('expense?tab=items'))?>">Items</a></div>
          <div class="exp-master-grid">
            <div class="exp-master-card"><div class="exp-master-card-head"><h2>ADD CATEGORY</h2></div><div class="exp-master-body"><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_expense_category"><div class="exp-field"><label>Category Name *</label><input name="name" required maxlength="100" placeholder="e.g. Office Expense"></div><div class="exp-field"><label>Expense Type *</label><select name="expense_type"><option value="indirect">Indirect Expense</option><option value="direct">Direct Expense</option></select></div><button class="exp-btn primary" type="submit">+ Add Category</button></form></div></div>
            <div class="exp-master-card"><div class="exp-master-card-head"><h2>CATEGORIES</h2></div><div style="overflow:auto"><table class="exp-list"><thead><tr><th>NAME</th><th>TYPE</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody>
            <?php foreach($masterCategories as $c): ?>
              <tr><td><strong><?=e($c['name'])?></strong></td><td><span class="exp-badge <?=e($c['expense_type'])?>"><?=e(ucfirst($c['expense_type']))?></span></td><td><?=((int)$c['active']===1)?'Active':'Inactive'?></td><td><div class="exp-actions"><form method="post" class="exp-inline-form"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="edit_expense_category"><input type="hidden" name="id" value="<?=$c['id']?>"><input name="name" value="<?=e($c['name'])?>" required><select name="expense_type"><option value="indirect" <?=($c['expense_type']==='indirect'?'selected':'')?>>Indirect</option><option value="direct" <?=($c['expense_type']==='direct'?'selected':'')?>>Direct</option></select><button class="exp-btn" type="submit">Save</button></form><form method="post" onsubmit="return confirm('Delete this category?');"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_expense_category"><input type="hidden" name="id" value="<?=$c['id']?>"><button class="exp-btn exp-danger" type="submit">Delete</button></form></div></td></tr>
            <?php endforeach; if(!$masterCategories): ?><tr><td colspan="4" class="exp-empty">No expense categories yet.</td></tr><?php endif; ?>
            </tbody></table></div></div>
          </div>
        </div>
        <?php page_end();exit;
    }
    if($masterTab==='items'){
        $itemQ=$pdo->prepare('SELECT id,name,active FROM expense_items WHERE company_id=? ORDER BY active DESC,name');
        $itemQ->execute([$cid]); $masterItems=$itemQ->fetchAll(PDO::FETCH_ASSOC);
        page_start('Expense Items');
        ?>
        <style>
        .exp-master{max-width:1180px;margin:0 auto;padding:4px 0 28px}.exp-master-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:16px}.exp-master-head h1{margin:0;color:#17324d;font-size:26px}.exp-master-head p{margin:5px 0 0;color:#718096;font-size:13px}.exp-tabs{display:flex;gap:6px;margin-bottom:14px}.exp-tab{padding:9px 14px;border:1px solid #d7dee7;border-radius:7px;text-decoration:none;color:#475569;background:#fff;font-size:13px}.exp-tab.active{background:#1877d2;border-color:#1877d2;color:#fff}.exp-master-grid{display:grid;grid-template-columns:330px 1fr;gap:14px}.exp-master-card{background:#fff;border:1px solid #e2e8f0;border-radius:9px;overflow:hidden}.exp-master-card-head{padding:14px 16px;border-bottom:1px solid #e2e8f0}.exp-master-card-head h2{margin:0;font-size:15px;color:#334155}.exp-master-body{padding:16px}.exp-field{margin-bottom:12px}.exp-field label{display:block;font-size:12px;color:#64748b;margin-bottom:6px}.exp-field input{width:100%;height:40px;border:1px solid #cbd5e1;border-radius:6px;padding:0 10px;box-sizing:border-box;background:#fff}.exp-btn{height:38px;padding:0 15px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#334155;cursor:pointer}.exp-btn.primary{background:#1877d2;color:#fff;border-color:#1877d2}.exp-list{width:100%;border-collapse:collapse}.exp-list th{background:#f8fafc;color:#64748b;font-size:11px;text-align:left;padding:11px 12px;border-bottom:1px solid #e2e8f0}.exp-list td{padding:11px 12px;border-bottom:1px solid #edf2f7;font-size:13px;color:#334155;vertical-align:middle}.exp-actions{display:flex;gap:6px;flex-wrap:wrap}.exp-inline-form{display:flex;gap:6px;align-items:center}.exp-inline-form input{height:34px;border:1px solid #cbd5e1;border-radius:5px;padding:0 8px;width:240px;box-sizing:border-box}.exp-danger{color:#b42318;border-color:#f1b5b0}.exp-empty{text-align:center;color:#64748b;padding:28px}
        @media(max-width:850px){.exp-master-grid{grid-template-columns:1fr}.exp-master-head{flex-direction:column}}
        </style>
        <div class="exp-master">
          <div class="exp-master-head"><div><h1>Expense Items</h1><p>Create and manage the items used on expense entries.</p></div><a class="exp-btn" href="<?=e(url('expense'))?>">← Back to Expenses</a></div>
          <div class="exp-tabs"><a class="exp-tab" href="<?=e(url('expense?tab=categories'))?>">Categories</a><a class="exp-tab active" href="<?=e(url('expense?tab=items'))?>">Items</a></div>
          <div class="exp-master-grid">
            <div class="exp-master-card"><div class="exp-master-card-head"><h2>ADD ITEM</h2></div><div class="exp-master-body"><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_expense_item"><div class="exp-field"><label>Expense Item Name *</label><input name="name" required maxlength="150" placeholder="e.g. Hosting"></div><button class="exp-btn primary" type="submit">+ Add Item</button></form></div></div>
            <div class="exp-master-card"><div class="exp-master-card-head"><h2>ITEMS</h2></div><div style="overflow:auto"><table class="exp-list"><thead><tr><th>NAME</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody>
            <?php foreach($masterItems as $it): ?>
              <tr><td><strong><?=e($it['name'])?></strong></td><td><?=((int)$it['active']===1)?'Active':'Inactive'?></td><td><div class="exp-actions"><form method="post" class="exp-inline-form"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="edit_expense_item"><input type="hidden" name="id" value="<?=$it['id']?>"><input name="name" value="<?=e($it['name'])?>" required><button class="exp-btn" type="submit">Save</button></form><form method="post" onsubmit="return confirm('Delete this expense item?');"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_expense_item"><input type="hidden" name="id" value="<?=$it['id']?>"><button class="exp-btn exp-danger" type="submit">Delete</button></form></div></td></tr>
            <?php endforeach; if(!$masterItems): ?><tr><td colspan="3" class="exp-empty">No expense items yet.</td></tr><?php endif; ?>
            </tbody></table></div></div>
          </div>
        </div>
        <?php page_end();exit;
    }

    /* Expense editor / saver.  v86 keeps the old accounting model but presents
       the entry screen as a full-page invoice-style form. */
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        try{
            $category=trim($_POST['category']??'');
            $expenseType=$_POST['expense_type']??'indirect';
            $date=transaction_datetime($_POST['txn_date']??null);
            $dueDate=($_POST['due_date']??'')!=='' ? $_POST['due_date'] : null;
            $notes=trim($_POST['notes']??'');
            $amount=round((float)($_POST['amount']??0),2);
            $editId=(int)($_POST['edit_id']??0);
            if($category==='') throw new RuntimeException('Expense category is required.');
            if(!in_array($expenseType,['direct','indirect'],true)) throw new RuntimeException('Invalid expense type.');

            /* v86 supports multiple item lines.  The total is calculated from
               the rows when present; otherwise the legacy amount field works. */
            $itemNames=$_POST['item_name']??[];
            $itemQty=$_POST['item_qty']??[];
            $itemPrice=$_POST['item_price']??[];
            $expenseRows=[]; $calculated=0;
            foreach($itemNames as $i=>$name){
                $name=trim((string)$name); $q=round((float)($itemQty[$i]??0),3); $pr=round((float)($itemPrice[$i]??0),2);
                if($name==='' && $q<=0 && $pr<=0) continue;
                if($name==='') continue;
                if($q<=0) $q=1;
                if($pr<0) $pr=0;
                $line=round($q*$pr,2); $calculated+= $line;
                $expenseRows[]=[$name,$q,$pr,$line];
            }
            if($calculated>0) $amount=round($calculated,2);
            if($amount<=0) throw new RuntimeException('Expense amount must be greater than zero.');

            $methods=$_POST['pay_method']??[];
            $pamount=$_POST['pay_amount']??[];
            $accounts=$_POST['pay_account']??[];
            $refs=$_POST['pay_reference']??($_POST['pay_ref']??[]);
            $dates=$_POST['pay_cheque_date']??[];
            $paid=0; $payments=[];
            foreach($methods as $i=>$rawMethod){$a=round((float)($pamount[$i]??0),2);if($a<=0)continue;[$m,$acct]=normalize_payment_method($pdo,$cid,(string)$rawMethod,trim($accounts[$i]??''));$ref=trim($refs[$i]??'');$cd=($dates[$i]??null)?:null;if($m==='cheque'&&$ref==='')throw new RuntimeException('Cheque number is required.');$paid+=$a;$payments[]=[$m,$a,$acct,$ref,$cd];}
            if($paid>$amount+0.01) throw new RuntimeException('Payment cannot be greater than expense amount.');
            $due=round($amount-$paid,2); $pdo=db(); $pdo->beginTransaction();

            if($editId>0){
                $q=$pdo->prepare('SELECT t.id,t.document_no FROM transactions t WHERE t.id=? AND t.company_id=? AND t.txn_type="expense" AND t.deleted_at IS NULL');
                $q->execute([$editId,$cid]); $existing=$q->fetch();
                if(!$existing) throw new RuntimeException('Expense not found.');
                $doc=$existing['document_no'];
                $pdo->prepare('UPDATE transactions SET txn_date=?,due_date=?,subtotal=?,total=?,paid=?,due=?,status=?,notes=? WHERE id=?')->execute([$date,$dueDate,$amount,$amount,$paid,$due,$due>0?'open':'final',$expenseType.' Expense: '.$category.'; '.$notes,$editId]);
                $pdo->prepare('UPDATE expense_details SET expense_type=?,category=? WHERE transaction_id=?')->execute([$expenseType,$category,$editId]);
                $pdo->prepare('DELETE FROM payment_lines WHERE transaction_id=?')->execute([$editId]);
                $pdo->prepare('DELETE FROM transaction_items WHERE transaction_id=?')->execute([$editId]);
                $pdo->prepare('DELETE FROM expense_item_links WHERE transaction_id=?')->execute([$editId]);
                $pdo->prepare('DELETE FROM ledger_entries WHERE transaction_id=?')->execute([$editId]);
                $tid=$editId;
                $auditAction='update';
            }else{
                $doc=next_document_in_transaction($pdo,$cid,'expense','EX-');
                $pdo->prepare('INSERT INTO transactions(company_id,txn_type,document_no,txn_date,due_date,subtotal,total,paid,due,currency_code,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$cid,'expense',$doc,$date,$dueDate,$amount,$amount,$paid,$due,$u['currency_code'],$due>0?'open':'final',$expenseType.' Expense: '.$category.'; '.$notes,$u['id']]);
                $tid=(int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO expense_details(transaction_id,company_id,expense_type,category) VALUES(?,?,?,?)')->execute([$tid,$cid,$expenseType,$category]);
                $auditAction='create';
            }
            foreach($payments as [$m,$a,$acct,$ref,$cd]){
                $pdo->prepare('INSERT INTO payment_lines(transaction_id,method,account_name,reference_no,cheque_date,amount,status) VALUES(?,?,?,?,?,?,?)')->execute([$tid,$m,$acct?:null,$ref?:null,$cd,$a,'completed']);
            }
            $itemInsert=$pdo->prepare('INSERT INTO transaction_items(transaction_id,item_id,qty,unit_price,discount,tax,amount) VALUES(?,?,?,?,?,?,?)');
            $itemLookup=$pdo->prepare('SELECT id FROM items WHERE company_id=? AND name=? AND active=1 LIMIT 1');
            $expenseItemLookup=$pdo->prepare('SELECT id FROM expense_items WHERE company_id=? AND name=? AND active=1 LIMIT 1');
            $expenseItemLink=$pdo->prepare('INSERT INTO expense_item_links(transaction_id,expense_item_id,qty,unit_price,amount) VALUES(?,?,?,?,?)');
            $itemNotes=[];
            foreach($expenseRows as [$name,$q,$pr,$line]){
                $itemLookup->execute([$cid,$name]); $iid=(int)($itemLookup->fetchColumn()?:0);
                if($iid>0) $itemInsert->execute([$tid,$iid,$q,$pr,0,0,$line]);
                $expenseItemLookup->execute([$cid,$name]); $eid=(int)($expenseItemLookup->fetchColumn()?:0);
                if($eid>0) $expenseItemLink->execute([$tid,$eid,$q,$pr,$line]);
                if($iid===0 && $eid===0) $itemNotes[]=$name.' × '.$q.' @ '.$pr;
            }
            if($itemNotes){ $extra=' Items: '.implode(', ',$itemNotes); $pdo->prepare('UPDATE transactions SET notes=CONCAT(COALESCE(notes,""),?) WHERE id=?')->execute([$extra,$tid]); }
            $lines=[['6000','Expense - '.$category,$amount,0,$doc]];
            foreach($payments as [$m,$a,$acct]){$ac=payment_account_code($m,$acct);$lines[]=[$ac[0],$ac[1],0,$a,$doc];}
            if($due>0)$lines[]=['2100','Expense Payable',0,$due,$doc];
            post_ledger($pdo,$cid,$tid,$date,$lines);
            audit($auditAction,'transaction',$tid,['type'=>'expense','document'=>$doc,'category'=>$category,'expense_type'=>$expenseType,'amount'=>$amount,'paid'=>$paid]);
            $pdo->commit(); flash('success','Expense '.$doc.($editId?' updated':' saved').' successfully.'); redirect('expense');
        }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();flash('error',$e->getMessage());redirect($route==='expense-new'?'expense-new':'expense');}
    }

    /* v120: standard expense module UI. */
    $editId=(int)($_GET['edit']??0);
    if($route==='expense-new'){
        $cats=$pdo->prepare('SELECT id,name,expense_type FROM expense_categories WHERE company_id=? AND active=1 ORDER BY name');
        $cats->execute([$cid]); $categories=$cats->fetchAll(PDO::FETCH_ASSOC);
        $itemsQ=$pdo->prepare('SELECT id,name FROM expense_items WHERE company_id=? AND active=1 ORDER BY name');
        $itemsQ->execute([$cid]); $expenseItems=$itemsQ->fetchAll(PDO::FETCH_ASSOC);
        $edit=null; $editLines=[]; $editPayments=[];
        if($editId){
            $q=$pdo->prepare('SELECT t.*,ed.expense_type,ed.category FROM transactions t JOIN expense_details ed ON ed.transaction_id=t.id WHERE t.id=? AND t.company_id=? AND t.txn_type="expense" AND t.deleted_at IS NULL');
            $q->execute([$editId,$cid]); $edit=$q->fetch();
            if($edit){
                $qq=$pdo->prepare('SELECT eil.qty,eil.unit_price,ei.name item_name FROM expense_item_links eil JOIN expense_items ei ON ei.id=eil.expense_item_id WHERE eil.transaction_id=? ORDER BY eil.id');
                $qq->execute([$editId]); $editLines=$qq->fetchAll(PDO::FETCH_ASSOC);
                $pp=$pdo->prepare('SELECT method,account_name,reference_no,cheque_date,amount FROM payment_lines WHERE transaction_id=? ORDER BY id');
                $pp->execute([$editId]); $editPayments=$pp->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        $rows=$editLines ?: [['item_name'=>'','qty'=>1,'unit_price'=>0]];
        $selectedCategory=(string)($edit['category']??'');
        $bankQ=$pdo->prepare('SELECT id,name,bank_name,account_number FROM bank_accounts WHERE company_id=? AND active=1 ORDER BY name');$bankQ->execute([$cid]);$bankRows=$bankQ->fetchAll(PDO::FETCH_ASSOC);
        page_start('Add Expense');
        ?>
        <style>
        .std-expense{background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 2px 10px rgba(15,23,42,.05);overflow:hidden}
        .std-expense-head{display:flex;justify-content:space-between;align-items:flex-start;padding:22px 24px;border-bottom:1px solid #e5e7eb}
        .std-expense-head h1{margin:0;font-size:24px;color:#17324d}.std-expense-head p{margin:6px 0 0;color:#718096;font-size:13px}
        .std-expense-actions{display:flex;gap:8px}.std-btn{display:inline-flex;align-items:center;justify-content:center;height:38px;padding:0 16px;border-radius:7px;border:1px solid #cbd5e1;background:#fff;color:#334155;text-decoration:none;cursor:pointer;font-size:13px}.std-btn.primary{background:#1877d2;border-color:#1877d2;color:#fff}.std-btn.danger{color:#b42318;border-color:#fecaca}
        .std-expense-body{padding:20px 24px 90px}.std-grid3{display:grid;grid-template-columns:1.3fr 1fr 1fr;gap:16px}.std-grid4{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:16px}.std-field label{display:block;font-size:12px;font-weight:600;color:#64748b;margin:0 0 6px}.std-field input,.std-field select,.std-field textarea{width:100%;height:40px;border:1px solid #cbd5e1;border-radius:6px;padding:0 11px;font-size:14px;box-sizing:border-box;background:#fff;color:#1f2937}.std-field textarea{height:84px;padding-top:10px;resize:vertical}.std-section{margin-top:20px;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden}.std-section-head{display:flex;justify-content:space-between;align-items:center;padding:13px 15px;background:#f8fafc;border-bottom:1px solid #e2e8f0}.std-section-head h2{font-size:14px;margin:0;color:#334155}.std-table{width:100%;border-collapse:collapse}.std-table th{font-size:11px;color:#64748b;text-align:left;background:#f8fafc;border-bottom:1px solid #e2e8f0;padding:10px}.std-table td{padding:9px 10px;border-bottom:1px solid #edf2f7;vertical-align:middle}.std-table input,.std-table select{height:36px;width:100%;border:1px solid #cbd5e1;border-radius:5px;padding:0 9px;box-sizing:border-box;background:#fff}.std-table .num{width:96px}.std-table .amt{width:120px;text-align:right}.std-row-action{width:42px;text-align:center}.std-remove{width:30px;height:30px;border:1px solid #fecaca;background:#fff;border-radius:5px;color:#b42318;cursor:pointer}.std-totalbar{display:flex;justify-content:flex-end;align-items:center;gap:28px;padding:13px 15px;background:#fff}.std-totalbar span{color:#64748b;font-size:13px}.std-totalbar b{font-size:19px;color:#0f172a;min-width:130px;text-align:right}.std-pay-grid{display:grid;grid-template-columns:1.2fr 1fr 1.2fr 1fr;gap:12px;align-items:end}.std-summary{display:flex;justify-content:flex-end;gap:26px;padding:18px 0 0}.std-summary .box{min-width:150px;text-align:right}.std-summary .label{font-size:12px;color:#64748b}.std-summary .val{font-size:18px;font-weight:700;color:#0f172a;margin-top:5px}.std-summary .due{color:#b42318}.std-form-footer{position:sticky;bottom:0;display:flex;justify-content:flex-end;gap:9px;padding:14px 24px;background:rgba(255,255,255,.97);border-top:1px solid #e2e8f0}.std-help{font-size:12px;color:#64748b;margin-top:7px}
        .cash-head-actions-v150{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.cash-bank-transfer-modal-v150{display:none;position:fixed;inset:0;z-index:2000;background:rgba(15,23,42,.48);align-items:center;justify-content:center;padding:24px 18px;box-sizing:border-box;overflow-y:auto}.cash-bank-transfer-modal-v150.show{display:flex}.cash-move-btn-v150{border:0!important;border-radius:8px!important;min-height:38px;padding:10px 15px!important;font-weight:600;box-shadow:0 2px 6px rgba(20,110,190,.12)}.cash-deposit-v150{background:#168fe9!important;color:#fff!important}.cash-withdraw-v150{background:#fff!important;color:#1683ea!important;border:1px solid #cfe0ef!important}.cash-bank-transfer-modal-v150{padding-top:54px}.cash-bank-transfer-box-v150{width:min(550px,100%);background:#fff;border-radius:8px;box-shadow:0 18px 55px rgba(15,23,42,.28);overflow:hidden}.cash-bank-transfer-head-v150{display:flex;justify-content:space-between;align-items:center;padding:17px 20px;border-bottom:1px solid #e6eaf0}.cash-bank-transfer-head-v150 h2{font-size:18px;font-weight:500;margin:0;color:#315367}.cash-bank-transfer-body-v150{padding:22px 32px 18px}.cash-bank-transfer-grid-v150{display:grid;grid-template-columns:1fr 1fr;gap:20px 24px}.cash-bank-transfer-field-v150 label{display:block;font-size:12px;color:#68768a;margin:0 0 6px}.cash-bank-transfer-field-v150 select,.cash-bank-transfer-field-v150 input{width:100%;height:38px;box-sizing:border-box;border:1px solid #d5dbe4;border-radius:5px;background:#fff;color:#27313f;padding:0 10px;outline:0;font-size:14px}.cash-bank-transfer-field-v150 select:disabled{background:#f5f7f9;color:#6b7685;cursor:not-allowed}.cash-bank-transfer-full-v150{grid-column:1/-1}.cash-bank-transfer-foot-v150{padding:10px 32px 16px;display:flex;justify-content:flex-end;gap:8px}.cash-bank-transfer-box-v150 form{margin:0}.cash-bank-transfer-modal-v150 .bank-secondary-v122,.cash-bank-transfer-modal-v150 .bank-primary-v122{min-width:90px}
/* v154: Cash In Hand modal intentionally reuses the exact Bank Accounts transfer modal skin. */
#cashBankTransferModalV150{display:none;}
#cashBankTransferModalV150.show{display:flex;}
#cashBankTransferModalV150 .bank-transfer-box-v147{width:min(550px,calc(100vw - 36px));max-height:calc(100vh - 48px);overflow:auto;}
/* v153: force Cash Deposit/Withdraw to render as a top-level modal; avoids legacy bank-modal CSS conflicts. */
.cash-bank-transfer-modal-v150{display:none!important;position:fixed!important;inset:0!important;z-index:2147483640!important;align-items:center!important;justify-content:center!important;padding:24px 18px!important;box-sizing:border-box!important;background:rgba(15,23,42,.58)!important;}
.cash-bank-transfer-modal-v150.show{display:flex!important;}
.cash-bank-transfer-box-v150{position:relative!important;z-index:2147483641!important;width:min(550px,calc(100vw - 36px))!important;max-height:calc(100vh - 48px)!important;overflow:auto!important;background:#fff!important;border-radius:8px!important;box-shadow:0 18px 55px rgba(15,23,42,.30)!important;}
    @media(max-width:900px){.std-grid3,.std-grid4,.std-pay-grid{grid-template-columns:1fr 1fr}.std-expense-head{flex-direction:column;gap:14px}.std-expense-body{padding-bottom:100px}.std-table{min-width:820px}.std-section{overflow:auto}}
        @media(max-width:560px){.std-grid3,.std-grid4,.std-pay-grid{grid-template-columns:1fr}}
        </style>
        <div class="std-expense">
          <div class="std-expense-head">
            <div><h1><?= $edit ? 'Edit Expense' : 'New Expense' ?></h1><p>Record a business expense, payment and outstanding amount in one place.</p></div>
            <div class="std-expense-actions"><a class="std-btn" href="<?=e(url('expense'))?>">Back</a><button type="button" class="std-btn" onclick="window.print()">Print</button></div>
          </div>
          <form method="post" id="stdExpenseForm">
            <div class="std-expense-body">
              <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
              <input type="hidden" name="edit_id" value="<?=$editId?>">
              <input type="hidden" name="amount" id="stdExpTotalInput" value="<?=e((string)($edit['total']??0))?>">
              <input type="hidden" name="expense_type" id="stdExpType" value="<?=e($edit['expense_type']??'indirect')?>">
              <div class="std-grid3">
                <div class="std-field"><label>Expense Category *</label><select name="category" id="stdExpCategory" required><option value="">Select category</option><?php foreach($categories as $c):?><option value="<?=e($c['name'])?>" data-type="<?=e($c['expense_type'])?>" <?=($selectedCategory===$c['name'])?'selected':''?>><?=e($c['name'])?> (<?=e(ucfirst($c['expense_type']))?>)</option><?php endforeach;?></select></div>
                <div class="std-field"><label>Expense Date *</label><input type="date" name="txn_date" value="<?=e($edit['txn_date']??date('Y-m-d'))?>" required></div>
                <div class="std-field"><label>Due Date</label><input type="date" name="due_date" value="<?=e($edit['due_date']??'')?>"></div>
              </div>
              <div class="std-grid4" style="margin-top:16px">
                <div class="std-field"><label>Expense No.</label><input value="<?=e($edit['document_no']??'Auto generated')?>" readonly></div>
                <div class="std-field"><label>Reference / Bill No.</label><input name="reference_no" value="" placeholder="Optional reference"></div>
                <div class="std-field"><label>Expense Type</label><input id="stdExpTypeLabel" value="<?=e(ucfirst($edit['expense_type']??'indirect'))?> Expense" readonly></div>
                <div class="std-field"><label>Notes</label><input name="notes" value="<?=e($edit ? preg_replace('/^.*?; /','',$edit['notes']??'') : '')?>" placeholder="Optional note"></div>
              </div>

              <div class="std-section">
                <div class="std-section-head"><h2>Expense Items</h2><button type="button" class="std-btn" id="stdAddExpenseRow">+ Add Row</button></div>
                <div style="overflow:auto">
                  <table class="std-table"><thead><tr><th style="width:46px">#</th><th>Expense Item / Description</th><th style="width:110px">QTY</th><th style="width:150px">PRICE / UNIT</th><th style="width:140px">AMOUNT</th><th style="width:50px"></th></tr></thead>
                  <tbody id="stdExpenseRows">
                  <?php foreach($rows as $i=>$r): ?><tr>
                    <td class="std-row-no"><?=($i+1)?></td>
                    <td><select name="item_name[]" required><option value="">Select expense item</option><?php foreach($expenseItems as $it):?><option value="<?=e($it['name'])?>" <?=((string)($r['item_name']??'')===$it['name'])?'selected':''?>><?=e($it['name'])?></option><?php endforeach;?></select></td>
                    <td><input class="stdQty" name="item_qty[]" type="number" min="0.01" step="0.01" value="<?=e((string)($r['qty']??1))?>"></td>
                    <td><input class="stdPrice" name="item_price[]" type="number" min="0" step="0.01" value="<?=e((string)($r['unit_price']??0))?>"></td>
                    <td><input class="stdAmount" type="number" step="0.01" value="0.00"></td>
                    <td class="std-row-action"><button type="button" class="std-remove" title="Remove row">×</button></td>
                  </tr><?php endforeach; ?>
                  </tbody></table>
                </div>
                <div class="std-totalbar"><span>Expense Total</span><b id="stdExpenseTotal">৳0.00</b></div>
              </div>

              <div class="std-section">
                <div class="std-section-head"><h2>Payment</h2><span class="std-help">Payment may be partial; remaining amount becomes due.</span></div>
                <div style="padding:15px">
                  <div id="stdPaymentRows">
                    <?php $payRow=$editPayments[0]??['method'=>'cash','account_name'=>'','reference_no'=>'','cheque_date'=>'','amount'=>0]; ?>
                    <div class="std-pay-grid std-payment-row">
                      <div class="std-field"><label>Payment Method</label><select name="pay_method[]" onchange="toggleExpensePayment(this)"><?=payment_select_options($bankRows,(string)($payRow['method']??'cash'),(string)($payRow['account_name']??''))?></select></div>
                      <div class="std-field std-cheque-ref" style="<?=($payRow['method']??'')==='cheque'?'':'display:none'?>"><label>Cheque No.</label><input name="pay_reference[]" value="<?=e($payRow['reference_no']??'')?>" placeholder="Cheque number"></div>
                      <div class="std-field"><label>Paid Amount</label><input class="stdPaid" name="pay_amount[]" type="number" min="0" step="0.01" value="<?=e((string)($payRow['amount']??0))?>"></div>
                    </div>
                  </div>
                  <button type="button" class="std-btn" id="stdAddPayment" style="margin-top:10px">+ Add Payment</button>
                </div>
              </div>

              <div class="std-summary">
                <div class="box"><div class="label">TOTAL</div><div class="val" id="stdSumTotal">৳0.00</div></div>
                <div class="box"><div class="label">PAID</div><div class="val" id="stdSumPaid">৳0.00</div></div>
                <div class="box"><div class="label">DUE</div><div class="val due" id="stdSumDue">৳0.00</div></div>
              </div>
            </div>
            <div class="std-form-footer"><a class="std-btn" href="<?=e(url('expense'))?>">Cancel</a><button class="std-btn primary" type="submit">Save Expense</button></div>
          </form>
        </div>
        <script>
        (function(){
          const rows=document.getElementById('stdExpenseRows'), totalEl=document.getElementById('stdExpenseTotal'), totalInput=document.getElementById('stdExpTotalInput');
          const sumTotal=document.getElementById('stdSumTotal'), sumPaid=document.getElementById('stdSumPaid'), sumDue=document.getElementById('stdSumDue');
          const money=n=>'৳'+Number(n||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
          function recalc(){let total=0;rows.querySelectorAll('tr').forEach((r,i)=>{r.querySelector('.std-row-no').textContent=i+1;const q=Math.max(0,parseFloat(r.querySelector('.stdQty')?.value||0));const p=Math.max(0,parseFloat(r.querySelector('.stdPrice')?.value||0));const a=Math.round(q*p*100)/100;total+=a;const amt=r.querySelector('.stdAmount');if(amt)amt.value=a.toFixed(2);});total=Math.round(total*100)/100;totalInput.value=total.toFixed(2);totalEl.textContent=money(total);sumTotal.textContent=money(total);let paid=0;document.querySelectorAll('.stdPaid').forEach(x=>paid+=Math.max(0,parseFloat(x.value||0)));sumPaid.textContent=money(paid);sumDue.textContent=money(Math.max(0,total-paid));}
          function addRow(){const first=rows.querySelector('tr');const r=first.cloneNode(true);r.querySelectorAll('input').forEach(i=>{if(i.classList.contains('stdQty'))i.value='1';else if(i.classList.contains('stdPrice'))i.value='0';else if(i.classList.contains('stdAmount'))i.value='0.00';});const s=r.querySelector('select');if(s)s.selectedIndex=0;rows.appendChild(r);recalc();}
          function addPayment(){const wrap=document.getElementById('stdPaymentRows'), first=wrap.querySelector('.std-payment-row');const r=first.cloneNode(true);r.querySelectorAll('input').forEach(i=>i.value=i.classList.contains('stdPaid')?'0':'');r.querySelectorAll('select').forEach(s=>s.selectedIndex=0);wrap.appendChild(r);}
          rows.addEventListener('input',recalc);rows.addEventListener('change',recalc);rows.addEventListener('click',e=>{const b=e.target.closest('.std-remove');if(!b)return;const all=rows.querySelectorAll('tr');if(all.length===1){all[0].querySelector('select').selectedIndex=0;all[0].querySelector('.stdQty').value='1';all[0].querySelector('.stdPrice').value='0';}else b.closest('tr').remove();recalc();});
          document.getElementById('stdAddExpenseRow').addEventListener('click',addRow);document.getElementById('stdAddPayment').addEventListener('click',addPayment);document.getElementById('stdPaymentRows').addEventListener('input',recalc);
          const cat=document.getElementById('stdExpCategory'), type=document.getElementById('stdExpType'), label=document.getElementById('stdExpTypeLabel');cat.addEventListener('change',()=>{const t=cat.selectedOptions[0]?.dataset.type||'indirect';type.value=t;if(label)label.value=(t==='direct'?'Direct':'Indirect')+' Expense';});
          document.getElementById('stdExpenseForm').addEventListener('submit',e=>{recalc();const total=parseFloat(totalInput.value||0);let paid=0;document.querySelectorAll('.stdPaid').forEach(x=>paid+=Math.max(0,parseFloat(x.value||0)));if(!cat.value){e.preventDefault();alert('Please select an expense category.');return;}if(total<=0){e.preventDefault();alert('Please add an expense item with a valid amount.');return;}if(paid>total+0.01){e.preventDefault();alert('Paid amount cannot be greater than the expense total.');return;}});
          const initial=cat.selectedOptions[0]?.dataset.type||type.value||'indirect';type.value=initial;label.value=(initial==='direct'?'Direct':'Indirect')+' Expense';recalc();
        })();
        </script>
        <?php page_end();exit;
    }

    if(isset($_GET['delete'])){
        $did=(int)$_GET['delete'];
        try{
            $q=$pdo->prepare('SELECT id,document_no FROM transactions WHERE id=? AND company_id=? AND txn_type="expense" AND deleted_at IS NULL');$q->execute([$did,$cid]);$tx=$q->fetch();
            if(!$tx) throw new RuntimeException('Expense not found.');
            $pdo->beginTransaction();$pdo->prepare('UPDATE transactions SET deleted_at=NOW() WHERE id=?')->execute([$did]);audit('delete','transaction',$did,['type'=>'expense','document'=>$tx['document_no']]);$pdo->commit();flash('success','Expense '.$tx['document_no'].' moved to Recycle Bin.');
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());}
        redirect('expense');
    }

    $q=trim($_GET['q']??''); $from=trim($_GET['from']??date('Y-m-01')); $to=trim($_GET['to']??date('Y-m-d'));
    $where='';$params=[$cid];
    if($q!==''){ $where.=' AND (t.document_no LIKE ? OR ed.category LIKE ? OR t.notes LIKE ?)'; $like='%'.$q.'%'; array_push($params,$like,$like,$like); }
    if($from!==''){ $where.=' AND DATE(t.txn_date)>=?'; $params[]=$from; }
    if($to!==''){ $where.=' AND DATE(t.txn_date)<=?'; $params[]=$to; }
    $st=$pdo->prepare('SELECT t.id,t.document_no,t.txn_date,t.total,t.paid,t.due,t.status,ed.expense_type,ed.category,(SELECT GROUP_CONCAT(DISTINCT CASE WHEN pl.method="cash" THEN "Cash" WHEN pl.method="bank" THEN COALESCE(pl.account_name,"Bank") WHEN pl.method="cheque" THEN "Cheque" WHEN pl.method="mobile_banking" THEN "Mobile Banking" WHEN pl.method="card" THEN "Card" ELSE "Other" END ORDER BY pl.id SEPARATOR ", ") FROM payment_lines pl WHERE pl.transaction_id=t.id) payment_methods FROM transactions t JOIN expense_details ed ON ed.transaction_id=t.id WHERE t.company_id=? AND t.txn_type="expense" AND t.deleted_at IS NULL'.$where.' ORDER BY t.txn_date DESC,t.id DESC LIMIT 300');
    $st->execute($params); $expenses=$st->fetchAll(PDO::FETCH_ASSOC);
    $totMonth=(float)$pdo->query('SELECT COALESCE(SUM(total),0) FROM transactions WHERE company_id='.(int)$cid.' AND txn_type="expense" AND deleted_at IS NULL AND DATE_FORMAT(txn_date,"%Y-%m")="'.date('Y-m').'"')->fetchColumn();
    $paidMonth=(float)$pdo->query('SELECT COALESCE(SUM(paid),0) FROM transactions WHERE company_id='.(int)$cid.' AND txn_type="expense" AND deleted_at IS NULL AND DATE_FORMAT(txn_date,"%Y-%m")="'.date('Y-m').'"')->fetchColumn();
    $dueMonth=round($totMonth-$paidMonth,2);
    $catStmt=$pdo->prepare('SELECT id,name,expense_type FROM expense_categories WHERE company_id=? AND active=1 ORDER BY name');$catStmt->execute([$cid]);$categories=$catStmt->fetchAll(PDO::FETCH_ASSOC);
    $itemStmt=$pdo->prepare('SELECT id,name FROM expense_items WHERE company_id=? AND active=1 ORDER BY name');$itemStmt->execute([$cid]);$expenseItems=$itemStmt->fetchAll(PDO::FETCH_ASSOC);
    page_start('Expenses');
    ?>
    <style>
    .expense-standard{padding:4px 0 24px}.expense-standard-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}.expense-standard-head h1{margin:0;font-size:26px;color:#17324d}.expense-standard-head p{margin:5px 0 0;color:#718096;font-size:13px}.expense-head-actions{display:flex;gap:8px}.es-btn{height:38px;padding:0 15px;border:1px solid #cbd5e1;border-radius:7px;background:#fff;color:#334155;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;font-size:13px}.es-btn.primary{background:#1877d2;color:#fff;border-color:#1877d2}.es-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:16px}.es-card{background:#fff;border:1px solid #e2e8f0;border-radius:9px;padding:16px 18px}.es-card .k{font-size:12px;color:#64748b}.es-card .v{font-size:23px;font-weight:700;color:#0f172a;margin-top:4px}.es-card .v.due{color:#b42318}.es-toolbar{background:#fff;border:1px solid #e2e8f0;border-radius:9px;padding:14px;display:flex;justify-content:space-between;align-items:end;gap:10px;margin-bottom:14px}.es-filters{display:grid;grid-template-columns:1.4fr 150px 150px auto;gap:10px;flex:1}.es-field label{display:block;font-size:11px;color:#64748b;margin-bottom:5px}.es-field input{height:38px;width:100%;border:1px solid #cbd5e1;border-radius:6px;padding:0 10px;box-sizing:border-box}.es-panel{background:#fff;border:1px solid #e2e8f0;border-radius:9px;overflow:hidden}.es-panel-head{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px solid #e2e8f0}.es-panel-head h2{font-size:15px;margin:0;color:#334155}.es-table-wrap{overflow:auto}.es-table{width:100%;min-width:900px;border-collapse:collapse}.es-table th{font-size:11px;color:#64748b;text-align:left;background:#f8fafc;padding:11px 10px;border-bottom:1px solid #e2e8f0}.es-table td{font-size:13px;color:#334155;padding:12px 10px;border-bottom:1px solid #edf2f7}.es-table .amount{text-align:right;font-weight:600}.es-table .actions{white-space:nowrap}.es-action{display:inline-flex;align-items:center;justify-content:center;width:32px;height:30px;border:1px solid #cbd5e1;border-radius:5px;background:#fff;color:#475569;text-decoration:none;margin-left:4px}.es-status{display:inline-flex;align-items:center;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:600}.es-status.paid{background:#ecfdf3;color:#15803d}.es-status.open{background:#fff7ed;color:#c2410c}.es-mini{padding:16px;border-top:1px solid #e2e8f0}.es-mini h3{font-size:13px;margin:0 0 10px;color:#334155}.es-chips{display:flex;flex-wrap:wrap;gap:7px}.es-chip{font-size:12px;border:1px solid #e2e8f0;background:#f8fafc;padding:6px 9px;border-radius:999px;color:#475569}
    @media(max-width:900px){.es-cards{grid-template-columns:1fr}.es-toolbar{flex-direction:column;align-items:stretch}.es-filters{grid-template-columns:1fr 1fr}.expense-standard-head{align-items:flex-start;gap:10px;flex-direction:column}}
    </style>
    <div class="expense-standard">
      <div class="expense-standard-head"><div><h1>Expenses</h1><p>Record, track and manage your business expenses.</p></div><div class="expense-head-actions"><a class="es-btn" href="<?=e(url('expense?tab=categories'))?>">Manage Categories</a><a class="es-btn" href="<?=e(url('expense?tab=items'))?>">Manage Items</a><a class="es-btn primary" href="<?=e(url('expense-new'))?>">+ Add Expense</a></div></div>
      <div class="es-cards"><div class="es-card"><div class="k">THIS MONTH</div><div class="v"><?=money($totMonth)?></div></div><div class="es-card"><div class="k">PAID THIS MONTH</div><div class="v"><?=money($paidMonth)?></div></div><div class="es-card"><div class="k">DUE THIS MONTH</div><div class="v due"><?=money($dueMonth)?></div></div></div>
      <div class="es-toolbar"><form method="get" class="es-filters"><input type="hidden" name="q" value="<?=e($q)?>"><div class="es-field"><label>SEARCH</label><input name="q" value="<?=e($q)?>" placeholder="Expense no, category, notes"></div><div class="es-field"><label>FROM</label><input type="date" name="from" value="<?=e($from)?>"></div><div class="es-field"><label>TO</label><input type="date" name="to" value="<?=e($to)?>"></div><div><button class="es-btn primary" type="submit">Filter</button></div></form><a class="es-btn" href="<?=e(url('expense'))?>">Reset</a></div>
      <div class="es-panel"><div class="es-panel-head"><h2>Expense Transactions</h2><span style="font-size:12px;color:#64748b"><?=count($expenses)?> record(s)</span></div><div class="es-table-wrap"><table class="es-table"><thead><tr><th>DATE</th><th>EXPENSE NO.</th><th>CATEGORY</th><th>TYPE</th><th>PAYMENT</th><th>AMOUNT</th><th>PAID</th><th>DUE</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody>
      <?php foreach($expenses as $r): ?><tr><td><?=e(date('d/m/Y',strtotime($r['txn_date'])))?></td><td><b><?=e($r['document_no'])?></b></td><td><?=e($r['category'])?></td><td><?=e(ucfirst($r['expense_type']))?></td><td><?=e($r['payment_methods']?:'—')?></td><td class="amount"><?=money((float)$r['total'])?></td><td class="amount"><?=money((float)$r['paid'])?></td><td class="amount"><?=money((float)$r['due'])?></td><td><span class="es-status <?=((float)$r['due']>0)?'open':'paid'?>"><?=((float)$r['due']>0)?'Open':'Paid'?></span></td><td class="actions"><a class="es-action" href="<?=e(url('expense-new?edit='.(int)$r['id']))?>" title="Edit">✎</a><a class="es-action" href="<?=e(url('expense?delete='.(int)$r['id']))?>" onclick="return confirm('Move this expense to Recycle Bin?')" title="Delete">🗑</a></td></tr><?php endforeach; if(!$expenses):?><tr><td colspan="10" style="text-align:center;padding:28px;color:#64748b">No expense transactions found.</td></tr><?php endif; ?></tbody></table></div></div>
      <div class="es-panel" style="margin-top:14px"><div class="es-panel-head"><h2>Expense Setup</h2></div><div class="es-mini"><h3>Categories</h3><div class="es-chips"><?php foreach($categories as $c):?><span class="es-chip"><?=e($c['name'])?> · <?=e(ucfirst($c['expense_type']))?></span><?php endforeach;if(!$categories):?><span class="es-chip">No categories</span><?php endif;?></div></div><div class="es-mini"><h3>Expense Items</h3><div class="es-chips"><?php foreach($expenseItems as $it):?><span class="es-chip"><?=e($it['name'])?></span><?php endforeach;if(!$expenseItems):?><span class="es-chip">No expense items</span><?php endif;?></div></div></div>
    </div>
    <?php page_end();exit;
}


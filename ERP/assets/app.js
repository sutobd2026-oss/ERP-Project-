function qs(s,p=document){return p.querySelector(s)}
function qsa(s,p=document){return [...p.querySelectorAll(s)]}
function toggleMenu(id){const el=qs('#'+id);if(el)el.classList.toggle('show')}
function closeModal(id){const el=qs('#'+id);if(el)el.classList.remove('show')}
function openModal(id){const el=qs('#'+id);if(el)el.classList.add('show')}
function bindDots(){qsa('.dots').forEach(b=>b.addEventListener('click',e=>{e.stopPropagation();const m=b.nextElementSibling;qsa('.row-menu').forEach(x=>{if(x!==m)x.classList.remove('show')});m?.classList.toggle('show')}))}
function fmt(n){return '৳'+Number(n||0).toLocaleString('en-BD',{minimumFractionDigits:2,maximumFractionDigits:2})}
function recalc(){let sub=0;qsa('.sale-row').forEach(r=>{const qty=parseFloat(qs('.qty',r)?.value||0),price=parseFloat(qs('.price',r)?.value||0),disc=Math.max(0,parseFloat(qs('.line-disc',r)?.value||0));const amt=Math.max(0,qty*price-disc);if(qs('.amount',r))qs('.amount',r).textContent=fmt(amt);sub+=amt});const inv=Math.max(0,parseFloat(qs('#invoiceDiscount')?.value||0)),tax=Math.max(0,parseFloat(qs('#tax')?.value||0)),direct=Math.max(0,parseFloat(qs('#directExpense')?.value||0));const total=Math.max(0,sub-inv+tax+direct);if(qs('#grandTotal'))qs('#grandTotal').textContent=fmt(total);if(qs('#subTotal'))qs('#subTotal').textContent=fmt(sub);const received=qs('#receivedToggle')?.checked;if(received){const first=qs('#paymentRows [name="pay_amount[]"]');if(first){first.value=total.toFixed(2);first.dispatchEvent(new Event('input',{bubbles:true}));}qsa('#paymentRows [name="pay_amount[]"]').slice(1).forEach(x=>{x.value='0';});}let paid=0;qsa('[name="pay_amount[]"]').forEach(x=>paid+=Math.max(0,parseFloat(x.value||0)));if(qs('#paidPreview'))qs('#paidPreview').textContent=fmt(paid);if(qs('#duePreview'))qs('#duePreview').textContent=fmt(Math.max(0,total-paid))}
function renumberRows(){qsa('#entryRows .sale-row').forEach((r,i)=>{const n=qs('.txn-row-number',r);if(n)n.textContent=i+1})}
function makeTxnRowKey(){return 'row-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2,10)}
function addRow(type){const rows=qs('#entryRows');if(!rows)return;const first=qs('.sale-row',rows);if(!first)return;const clone=first.cloneNode(true);const key=qs('input[name="bundle_row_key[]"]',clone);if(key){key.value=makeTxnRowKey();clone.dataset.bundleRowKey=key.value;}const parent=qs('input[name="bundle_parent_key[]"]',clone);if(parent){parent.value='';clone.dataset.bundleParentKey='';}const child=qs('input[name="bundle_child[]"]',clone);if(child){child.value='0';clone.dataset.bundleChild='0';}qsa('.item-live-search',clone).forEach(el=>{el.dataset.itemSearchBound='';el.removeAttribute('data-item-search-bound');const res=qs('.item-search-results',el);if(res){res.hidden=true;res.innerHTML='';}});qsa('input',clone).forEach(i=>{if(i.classList.contains('qty'))i.value='1';else if(i.classList.contains('price')||i.classList.contains('line-disc'))i.value='0';else if(i.classList.contains('item-search-input'))i.value='';else if(i.classList.contains('serial-number-input'))i.value='';});qsa('select',clone).forEach(s=>s.selectedIndex=0);const clear=qs('.item-search-clear',clone);if(clear)clear.style.display='none';qs('.item-selected-summary',clone)?.remove();const unit=qs('.unit-label',clone);if(unit)unit.textContent='—';const serialBox=qs('.serial-entry-box',clone);if(serialBox)serialBox.hidden=true;qs('.amount',clone).textContent=fmt(0);rows.appendChild(clone);bindEntryRow(clone);renumberRows();if(typeof window.SutoInitItemSearch==='function')window.SutoInitItemSearch(clone);if(typeof window.SutoBindSerialEntry==='function')window.SutoBindSerialEntry(clone);}
function removeRow(btn){const rows=qsa('#entryRows .sale-row');if(rows.length<=1){const r=rows[0];qsa('select',r).forEach(x=>x.selectedIndex=0);qsa('input',r).forEach(x=>{if(x.classList.contains('qty'))x.value='1';else if(x.classList.contains('price')||x.classList.contains('line-disc'))x.value='0';else if(x.classList.contains('item-search-input'))x.value='';});qs('.serial-number-input',r)&&(qs('.serial-number-input',r).value='');qs('.serial-entry-box',r)&&(qs('.serial-entry-box',r).hidden=true);qs('.item-search-clear',r)&&(qs('.item-search-clear',r).style.display='none');qs('.item-selected-summary',r)?.remove();const u=qs('.unit-label',r);if(u)u.textContent='—';qs('.amount',r).textContent=fmt(0)}else{btn.closest('.sale-row')?.remove();renumberRows();}recalc()}
function addPayment(){const wrap=qs('#paymentRows');if(!wrap)return;const first=qs('.payment-line',wrap);const clone=first.cloneNode(true);qsa('input',clone).forEach(i=>i.value='');qsa('select',clone).forEach(s=>s.selectedIndex=0);const acct=qs('.pay-account',clone);if(acct){acct.style.display='none';acct.name='pay_account[]'}const cd=qs('.pay-cheque-date',clone);if(cd){cd.style.display='none';cd.value=''}wrap.appendChild(clone);bindPaymentLine(clone);recalc()}
function togglePaymentFields(sel){const row=sel&&sel.closest('.payment-line,.std-payment-row,.std-pay-grid');if(!row)return;const method=sel.value||'';const cd=qs('.pay-cheque-date',row);const ref=qs('.pay-ref',row)||qs('[name="pay_reference[]"]',row)||qs('[name="pay_ref[]"]',row)||qs('.std-cheque-ref',row);const isCheque=method==='cheque';if(cd)cd.style.display=isCheque?'block':'none';if(ref)ref.style.display=isCheque?'block':'none';if(!isCheque&&ref&&ref.querySelector('input'))ref.querySelector('input').value='';}
function updatePrice(sel){const row=sel?.closest('.sale-row');const opt=sel?.selectedOptions?.[0];if(row&&opt){const price=qs('.price',row);const unit=qs('.unit-label',row);const txType=document.body.dataset.txntype||'sale';if(price)price.value=(txType==='purchase'?opt.dataset.buy:opt.dataset.sale)||0;if(unit)unit.textContent=opt.dataset.unit||'—';if(typeof window.SutoUpdateSerialUI==='function')window.SutoUpdateSerialUI(row,opt);if(row.dataset.bundleChild!=='1'){const mainRows=qsa('#entryRows .sale-row:not(.bundle-child-row)');if(mainRows[mainRows.length-1]===row&&typeof addRow==='function')addRow(txType);}if(txType==='sale'&&typeof window.syncBundleForRow==='function'&&row.dataset.bundleChild!=='1')window.syncBundleForRow(row);if(typeof recalc==='function')recalc()}}
function bindEntryRow(row){qsa('.qty,.price,.line-disc',row).forEach(x=>x.addEventListener('input',function(){recalc();if(x.classList.contains('qty')&&row.dataset.bundleChild!=='1'&&document.body.dataset.txntype==='sale'&&typeof window.syncBundleForRow==='function')window.syncBundleForRow(row);if(typeof window.SutoSyncSerialMeta==='function')window.SutoSyncSerialMeta(row)}));qs('.item-select',row)?.addEventListener('change',e=>updatePrice(e.target));if(typeof window.SutoBindSerialEntry==='function')window.SutoBindSerialEntry(row);const sel=qs('.item-select',row);if(sel&&sel.value&&typeof window.SutoUpdateSerialUI==='function')window.SutoUpdateSerialUI(row,sel.selectedOptions[0]);}
function bindPaymentLine(row){qs('select[name="pay_method[]"]',row)?.addEventListener('change',e=>togglePaymentFields(e.target));qs('input[name="pay_amount[]"]',row)?.addEventListener('input',recalc)}
/* v118: serial-number tracking UI */
(function(){
  function norm(raw){return String(raw||'').split(/[\r\n,;]+/).map(function(v){return v.trim();}).filter(Boolean).filter(function(v,i,a){return a.findIndex(function(x){return x.toLowerCase()===v.toLowerCase();})===i;});}
  function updateUI(row,opt){
    const box=qs('.serial-entry-box',row); if(!box)return;
    const tracked=opt&&opt.dataset&&opt.dataset.serialTracked==='1';
    box.hidden=!tracked;
    if(tracked){
      const mode=box.dataset.mode||document.body.dataset.txntype||'sale';
      const hint=qs('.serial-entry-hint',box);
      if(hint)hint.textContent=mode==='purchase'?'Required · one serial per unit.':'Optional · blank = auto-select available serials FIFO.';
      syncMeta(row);
    } else { const ta=qs('.serial-number-input',box); if(ta)ta.value=''; }
  }
  async function syncMeta(row){
    const sel=qs('.item-select',row),box=qs('.serial-entry-box',row),meta=qs('.serial-entry-meta',row); if(!sel||!box||!meta||box.hidden)return;
    const opt=sel.selectedOptions[0], tracked=opt&&opt.dataset.serialTracked==='1'; if(!tracked){meta.textContent='';return;}
    const itemId=sel.value,q=Math.max(0,parseFloat(qs('.qty',row)?.value||0));
    if(document.body.dataset.txntype==='purchase'){meta.textContent='Enter '+Math.round(q||0)+' unique serial number'+(Math.round(q||0)===1?'':'s')+'.';return;}
    try{const u=new URL((window.SutoSerialSearchConfig||{}).url||'',location.origin);u.searchParams.set('item_id',itemId);u.searchParams.set('q','');const res=await fetch(u.toString(),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});const data=await res.json();if(data&&data.ok){meta.textContent='Available serials: '+(Array.isArray(data.items)?data.items.length:0)+' · Enter specific serials to sell, or leave blank for FIFO.';}}
    catch(_){meta.textContent='Serial tracking enabled.';}
  }
  function bind(row){if(!row||row.dataset.serialBound==='1')return;row.dataset.serialBound='1';const ta=qs('.serial-number-input',row);if(ta){ta.addEventListener('input',function(){const n=norm(ta.value);ta.value=n.join('\n');syncMeta(row);});} }
  window.SutoUpdateSerialUI=updateUI;window.SutoSyncSerialMeta=syncMeta;window.SutoBindSerialEntry=bind;
  document.addEventListener('DOMContentLoaded',function(){qsa('.sale-row').forEach(function(row){bind(row);const sel=qs('.item-select',row);if(sel&&sel.value)updateUI(row,sel.selectedOptions[0]);});});
})();

function validateTransactionForm(){const totalText=qs('#grandTotal')?.textContent||'৳0.00';const total=Number(totalText.replace(/[^0-9.-]/g,''))||0;let paid=0;qsa('[name="pay_amount[]"]').forEach(x=>paid+=Math.max(0,parseFloat(x.value||0)));if(paid>total+0.01){alert('Payment cannot be greater than invoice total.');return false}return true}
function toggleStock(){const svc=qs('input[name=item_type]:checked')?.value==='service'||qs('#item_type')?.value==='service';qsa('.stock-field').forEach(x=>x.style.display=svc?'none':'block')}
/* v145: delegated bundle sync fallback for live item-selection changes */
document.addEventListener('change',function(e){
  const sel=e.target && e.target.matches && e.target.matches('#entryRows .item-source-select');
  if(!sel)return;
  const row=sel.closest('.sale-row');
  if(!row || row.dataset.bundleChild==='1')return;
  if(document.body.dataset.txntype==='sale' && typeof window.syncBundleForRow==='function'){
    try{window.syncBundleForRow(row);}catch(err){console.error('Delegated bundle sync failed:',err);}
  }
});
document.addEventListener('wheel',function(e){if(e.target&&e.target.matches&&e.target.matches('input[type=number]')){e.preventDefault();}}, {passive:false});
/* v146: delegated bundle selection sync */
document.addEventListener('change',function(e){
  const sel=e.target && e.target.matches && e.target.matches('#entryRows .item-source-select');
  if(!sel)return;
  const row=sel.closest('.sale-row');
  if(!row || row.dataset.bundleChild==='1')return;
  if(document.body.dataset.txntype==='sale' && typeof window.syncBundleForRow==='function'){
    try{window.syncBundleForRow(row);}catch(err){console.error('Delegated bundle selection sync failed:',err);}
  }
});
document.addEventListener('click',e=>{
  const dot=e.target.closest('.dots');
  if(dot){
    e.preventDefault();
    e.stopPropagation();
    const menu=dot.parentElement?.querySelector('.row-menu') || dot.nextElementSibling;
    if(menu){
      qsa('.row-menu').forEach(x=>{if(x!==menu)x.classList.remove('show')});
      menu.classList.toggle('show');
    }
    return;
  }
  if(e.target.closest('.row-menu')) return;
  qsa('.row-menu').forEach(x=>x.classList.remove('show'));
  qs('#addMore')?.classList.remove('show');
});
document.addEventListener('DOMContentLoaded',()=>{bindDots();qsa('.qty,.price,.line-disc,[name="invoice_discount"],[name="tax"],[name="direct_expense"]').forEach(x=>x.addEventListener('input',recalc));qsa('.sale-row').forEach(bindEntryRow);qsa('.payment-line').forEach(bindPaymentLine);qsa('#receivedToggle')?.addEventListener('change',recalc);qsa('#addMoreBtn').forEach(x=>x.addEventListener('click',e=>{e.stopPropagation();toggleMenu('addMore')}));qs('#item_type')?.addEventListener('change',toggleStock);toggleStock();recalc()})
function addExpensePayment(){const wrap=qs('#expensePaymentRows');if(!wrap)return;const first=qs('.expense-payment-line',wrap);const clone=first.cloneNode(true);qsa('input',clone).forEach(i=>i.value='');qsa('select',clone).forEach(s=>s.selectedIndex=0);wrap.appendChild(clone);toggleExpensePayment(qs('select',clone));expenseRecalc();}
function toggleExpensePayment(sel){const row=sel?.closest('.expense-payment-line')||sel?.closest('.expense-entry-payment-row')||sel?.closest('.std-payment-row');if(!row)return;const ref=qs('.std-cheque-ref',row)||qs('.pay-ref',row)||qs('[name="pay_reference[]"]',row);const show=(sel.value||'')==='cheque';if(ref)ref.style.display=show?'block':'none';}
function expenseRecalc(){const total=parseFloat(qs('#expenseAmount')?.value||0)||0;let paid=0;qsa('[name="pay_amount[]"]').forEach(x=>paid+=Math.max(0,parseFloat(x.value||0)));if(qs('#expenseTotalPreview'))qs('#expenseTotalPreview').textContent=fmt(total);if(qs('#expensePaidPreview'))qs('#expensePaidPreview').textContent=fmt(paid);if(qs('#expenseDuePreview'))qs('#expenseDuePreview').textContent=fmt(Math.max(0,total-paid));}
function validateExpenseForm(){const total=parseFloat(qs('#expenseAmount')?.value||0)||0;let paid=0;qsa('[name="pay_amount[]"]').forEach(x=>paid+=Math.max(0,parseFloat(x.value||0)));if(paid>total+0.01){alert('Payment cannot be greater than expense amount.');return false}return true}
document.addEventListener('DOMContentLoaded',()=>{qs('#expenseAmount')?.addEventListener('input',expenseRecalc);qsa('[name="pay_amount[]"]').forEach(x=>x.addEventListener('input',expenseRecalc));qsa('.expense-payment-line select').forEach(toggleExpensePayment);expenseRecalc()});

/* v51: robust item action popup - always anchored to the 3-dot button and rendered in body */
(function(){
  var active = null;
  var originalParent = new WeakMap();
  var originalNext = new WeakMap();

  function closeMenu(){
    if(!active) return;
    var btn=active.btn, panel=active.panel, parent=originalParent.get(panel), next=originalNext.get(panel);
    if(panel){
      panel.hidden=true;
      panel.style.cssText='';
      if(parent){
        if(next && next.parentNode===parent) parent.insertBefore(panel,next);
        else parent.appendChild(panel);
      }
    }
    if(btn) btn.setAttribute('aria-expanded','false');
    active=null;
  }
  function placeMenu(btn,panel){
    if(!btn || !panel) return;
    panel.hidden=false;
    var r=btn.getBoundingClientRect();
    var gap=8, width=150, height=panel.offsetHeight||80;
    var left=r.right+gap;
    var top=r.top;
    if(left+width > window.innerWidth-8) left=Math.max(8,r.left-width-gap);
    if(top+height > window.innerHeight-8) top=Math.max(8,window.innerHeight-height-8);
    panel.style.position='fixed';
    panel.style.left=Math.round(left)+'px';
    panel.style.top=Math.round(top)+'px';
    panel.style.width=width+'px';
    panel.style.zIndex='2147483647';
  }
  function openMenu(btn){
    if(active && active.btn===btn){ closeMenu(); return; }
    closeMenu();
    var wrap=btn.closest('.item-master-actions');
    var panel=wrap && wrap.querySelector('.item-master-menu-panel');
    if(!panel) return;
    originalParent.set(panel,panel.parentNode);
    originalNext.set(panel,panel.nextSibling);
    panel.hidden=false;
    document.body.appendChild(panel);
    btn.setAttribute('aria-expanded','true');
    active={btn:btn,panel:panel};
    placeMenu(btn,panel);
    requestAnimationFrame(function(){ if(active && active.panel===panel) placeMenu(btn,panel); });
  }
  document.addEventListener('click',function(e){
    var btn=e.target.closest('.item-action-btn');
    if(btn){ e.preventDefault(); e.stopPropagation(); openMenu(btn); return; }
    if(active && e.target.closest('.item-master-menu-panel')) return;
    closeMenu();
  },true);
  window.addEventListener('resize',function(){if(active)placeMenu(active.btn,active.panel);});
  window.addEventListener('scroll',function(){if(active)placeMenu(active.btn,active.panel);},true);
})();



/* v104: Expense Item selector is handled from the shared external JS bundle.
   The previous page used inline onchange handlers, but the inline page script was
   not executing in the live page, producing "v98CategoryChanged is not defined".
   Keep these functions on window/global scope so inline HTML handlers can resolve. */
(function(){
  let expenseItemsCache = [];
  let expenseItemsLoaded = false;
  let expenseItemsLoading = null;

  function getExpenseCategoryId(){
    const cat = document.getElementById('expenseCategorySelect');
    return cat?.selectedOptions?.[0]?.dataset?.catId || '';
  }

  function populateExpenseItemSelect(sel, items){
    if(!sel) return;
    const current = sel.value || sel.dataset.selected || '';
    const catId = getExpenseCategoryId();
    let list = Array.isArray(items) ? items.slice() : [];
    if(catId){
      const linked = list.filter(it => String(it.category_id || '') === String(catId));
      if(linked.length) list = linked;
    }
    sel.innerHTML = '';
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Select expense item';
    sel.appendChild(placeholder);
    for(const it of list){
      const o = document.createElement('option');
      o.value = String(it.name || '');
      o.textContent = String(it.name || '');
      o.dataset.catId = String(it.category_id || '');
      if(String(it.name || '') === String(current)) o.selected = true;
      sel.appendChild(o);
    }
    if(!list.length){
      const empty = document.createElement('option');
      empty.value = '';
      empty.disabled = true;
      empty.textContent = expenseItemsLoaded ? 'No expense items found' : 'Loading...';
      sel.appendChild(empty);
    }
  }

  async function loadExpenseItems(categoryId=''){
    if(expenseItemsLoaded && !categoryId){
      qsa('.v94-item-select').forEach(s => populateExpenseItemSelect(s, expenseItemsCache));
      return expenseItemsCache;
    }
    if(expenseItemsLoading && !categoryId) return expenseItemsLoading;
    const base = '/ERP/public/expense-items-api.php';
    const url = categoryId ? `${base}?category_id=${encodeURIComponent(categoryId)}` : base;
    const req = fetch(url, {credentials:'same-origin', cache:'no-store', headers:{'Accept':'application/json'}})
      .then(async r => {
        const text = await r.text();
        if(!r.ok) throw new Error(`Expense item API HTTP ${r.status}`);
        let data;
        try { data = JSON.parse(text); } catch(e) { throw new Error('Expense item API returned non-JSON data'); }
        if(!data?.ok) throw new Error(data?.error || 'Expense item API failed');
        const items = Array.isArray(data.items) ? data.items : [];
        if(!categoryId) { expenseItemsCache = items; expenseItemsLoaded = true; }
        // The category-scoped API is authoritative for the selected category.
        const list = categoryId ? items : expenseItemsCache;
        qsa('.v94-item-select').forEach(s => populateExpenseItemSelect(s, list));
        return list;
      })
      .catch(err => {
        console.error('Expense item selector:', err);
        qsa('.v94-item-select').forEach(s => {
          s.innerHTML = '';
          const o = document.createElement('option');
          o.value = '';
          o.textContent = 'No expense items found';
          o.disabled = true;
          s.appendChild(o);
        });
        return [];
      });
    if(!categoryId) expenseItemsLoading = req;
    try { return await req; } finally { if(!categoryId) expenseItemsLoading = null; }
  }

  window.v98CategoryChanged = function(sel){
    const cat = sel || document.getElementById('expenseCategorySelect');
    const type = cat?.selectedOptions?.[0]?.dataset?.expenseType || 'indirect';
    const hidden = document.getElementById('expenseTypeHidden');
    if(hidden) hidden.value = type;
    const catId = cat?.selectedOptions?.[0]?.dataset?.catId || '';
    loadExpenseItems(catId);
    if(typeof window.v94Recalc === 'function') window.v94Recalc();
  };
  window.v98ItemChanged = function(){
    if(typeof window.v94Recalc === 'function') window.v94Recalc();
  };
  window.v94CategoryChanged = window.v98CategoryChanged;
  window.v94ItemChanged = window.v98ItemChanged;

  document.addEventListener('DOMContentLoaded', function(){
    if(!document.getElementById('expenseEntryForm')) return;
    loadExpenseItems('');
    const cat = document.getElementById('expenseCategorySelect');
    if(cat){
      window.v98CategoryChanged(cat);
      cat.addEventListener('change', function(){ window.v98CategoryChanged(this); });
    }
  });
})();

/* v86 expense editor helpers */
function addExpenseRow(){
  const body=qs('#expenseItemRows'); if(!body) return;
  const row=qs('.expense-item-row',body); if(!row) return;
  const clone=row.cloneNode(true);
  qsa('input',clone).forEach((x,i)=>{ if(x.name==='item_name[]') x.value=''; else if(x.name==='item_qty[]') x.value='1'; else x.value='0'; });
  const amount=qs('.expense-line-amount',clone); if(amount) amount.textContent='৳0.00';
  body.appendChild(clone); renumberExpenseRows(); expenseEntryRecalc();
}
function removeExpenseRow(btn){
  const rows=qsa('#expenseItemRows .expense-item-row');
  if(rows.length<=1){ qsa('input',rows[0]||document).forEach(x=>{if(x.name==='item_name[]')x.value='';else if(x.name==='item_qty[]')x.value='1';else if(x.name==='item_price[]')x.value='0'}); }
  else btn.closest('.expense-item-row')?.remove();
  renumberExpenseRows(); expenseEntryRecalc();
}
function renumberExpenseRows(){qsa('#expenseItemRows .expense-item-row').forEach((r,i)=>{const n=qs('.row-no',r);if(n)n.textContent=i+1})}
function addExpensePaymentEntry(){
  const wrap=qs('#expenseEntryPayments'); if(!wrap) return;
  const row=qs('.expense-entry-payment-row',wrap); if(!row) return;
  const clone=row.cloneNode(true); qsa('input',clone).forEach(x=>x.value=x.name==='pay_amount[]'?'0':''); qsa('select',clone).forEach((x,i)=>x.selectedIndex=0);
  const acct=qs('.expense-entry-bank',clone); if(acct)acct.style.display='none'; wrap.appendChild(clone); bindExpenseEntryPayment(clone); expenseEntryRecalc();
}
function removeExpensePaymentEntry(btn){const rows=qsa('#expenseEntryPayments .expense-entry-payment-row');if(rows.length<=1){const r=rows[0];if(r){qsa('input',r).forEach(x=>x.value=x.name==='pay_amount[]'?'0':'');}}else btn.closest('.expense-entry-payment-row')?.remove();expenseEntryRecalc()}
function bindExpenseEntryPayment(row){qs('select[name="pay_method[]"]',row)?.addEventListener('change',e=>toggleExpensePayment(e.target));qs('input[name="pay_amount[]"]',row)?.addEventListener('input',expenseEntryRecalc)}
function expenseEntryRecalc(){
  let total=0;
  qsa('#expenseItemRows .expense-item-row').forEach(r=>{const q=Math.max(0,parseFloat(qs('.expense-line-qty',r)?.value||0)),p=Math.max(0,parseFloat(qs('.expense-line-price',r)?.value||0));const a=Math.round(q*p*100)/100;total+=a;const out=qs('.expense-line-amount',r);if(out)out.textContent=fmt(a)});
  const amt=qs('#expenseAmount');if(amt)amt.value=total.toFixed(2);
  let paid=0;qsa('#expenseEntryPayments [name="pay_amount[]"]').forEach(x=>paid+=Math.max(0,parseFloat(x.value||0)));
  const due=Math.max(0,total-paid);
  if(qs('#expenseGrandTotal'))qs('#expenseGrandTotal').textContent=fmt(total);
  if(qs('#expenseTotalPreview'))qs('#expenseTotalPreview').textContent=fmt(total);
  if(qs('#expensePaidPreview'))qs('#expensePaidPreview').textContent=fmt(paid);
  if(qs('#expenseDuePreview'))qs('#expenseDuePreview').textContent=fmt(due);
}
function toggleExpensePayment(sel){const row=sel?.closest('.expense-entry-payment-row') || sel?.closest('.expense-payment-line');const acct=row&&qs('.expense-entry-bank',row);if(acct)acct.style.display=sel.value==='bank'?'block':'none';const old=row&&qs('.expense-bank-field',row);if(old)old.style.display=sel.value==='bank'?'block':'none'}
function validateExpenseForm(){expenseEntryRecalc();const total=parseFloat(qs('#expenseAmount')?.value||0)||0;let paid=0;qsa('[name="pay_amount[]"]').forEach(x=>paid+=Math.max(0,parseFloat(x.value||0)));if(total<=0){alert('Please add at least one expense item with a valid price.');return false}if(paid>total+0.01){alert('Payment cannot be greater than expense amount.');return false}return true}
document.addEventListener('DOMContentLoaded',()=>{if(qs('#expenseEntryForm')){qsa('#expenseItemRows .expense-line-qty,#expenseItemRows .expense-line-price').forEach(x=>x.addEventListener('input',expenseEntryRecalc));qsa('#expenseEntryPayments .expense-entry-payment-row').forEach(bindExpenseEntryPayment);qsa('#expenseEntryPayments select[name="pay_method[]"]').forEach(toggleExpensePayment);renumberExpenseRows();expenseEntryRecalc();}});

/* v130: payment methods are limited to Cash, Cheque and active Bank Accounts. */
(function(){
  function syncPaymentFields(select){
    if(!select) return;
    var row=select.closest('.payment-line'); if(!row) return;
    var raw=select.value||'';
    var isBank=raw.indexOf('bank::')===0;
    var isCheque=raw==='cheque';
    var account=row.querySelector('[name="pay_account[]"]');
    var ref=row.querySelector('[name="pay_ref[]"]');
    var chequeDate=row.querySelector('[name="pay_cheque_date[]"]');
    if(account) account.value=isBank?raw.slice(6):'';
    if(ref){ ref.style.display=isCheque?'':'none'; if(!isCheque) ref.value=''; }
    if(chequeDate){ chequeDate.style.display=isCheque?'':'none'; if(!isCheque) chequeDate.value=''; }
  }
  window.togglePaymentFields=syncPaymentFields;
  window.toggleStdPaymentFields=function(select){
    if(!select) return;
    var row=select.closest('.std-payment-row'); if(!row) return;
    var raw=select.value||'';
    var isBank=raw.indexOf('bank::')===0;
    var isCheque=raw==='cheque';
    var account=row.querySelector('[name="pay_account[]"]');
    var ref=row.querySelector('[name="pay_reference[]"]');
    if(account) account.value=isBank?raw.slice(6):'';
    var wrap=row.querySelector('.std-pay-ref'); if(wrap) wrap.style.display=isCheque?'':'none';
    if(ref && !isCheque) ref.value='';
  };
  window.addPayment=function(){
    var wrap=qs('#paymentRows'); if(!wrap) return;
    var first=qs('.payment-line',wrap); if(!first) return;
    var clone=first.cloneNode(true);
    qsa('input',clone).forEach(function(i){
      if(i.name==='pay_amount[]') i.value='0';
      else i.value='';
    });
    qsa('select',clone).forEach(function(s){s.selectedIndex=0;});
    wrap.appendChild(clone);
    var m=qs('[name="pay_method[]"]',clone); if(m) syncPaymentFields(m);
    if(typeof recalc==='function') recalc();
  };
  document.addEventListener('DOMContentLoaded',function(){
    qsa('.payment-line select[name="pay_method[]"]').forEach(syncPaymentFields);
    qsa('.std-payment-row select[name="pay_method[]"]').forEach(function(s){window.toggleStdPaymentFields(s);});
  });
})();

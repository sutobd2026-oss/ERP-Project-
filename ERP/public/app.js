function qs(s,p=document){return p.querySelector(s)}
function qsa(s,p=document){return [...p.querySelectorAll(s)]}
function toggleMenu(id){const el=qs('#'+id);if(el)el.classList.toggle('show')}
function closeModal(id){const el=qs('#'+id);if(el)el.classList.remove('show')}
function openModal(id){const el=qs('#'+id);if(el)el.classList.add('show')}
function bindDots(){qsa('.dots').forEach(b=>b.addEventListener('click',e=>{e.stopPropagation();const m=b.nextElementSibling;qsa('.row-menu').forEach(x=>{if(x!==m)x.classList.remove('show')});m?.classList.toggle('show')}))}
function fmt(n){return '৳'+Number(n||0).toLocaleString('en-BD',{minimumFractionDigits:2,maximumFractionDigits:2})}
function recalc(){let sub=0;qsa('.sale-row').forEach(r=>{const qty=parseFloat(qs('.qty',r)?.value||0),price=parseFloat(qs('.price',r)?.value||0),disc=Math.max(0,parseFloat(qs('.line-disc',r)?.value||0));const amt=Math.max(0,qty*price-disc);if(qs('.amount',r))qs('.amount',r).textContent=fmt(amt);sub+=amt});const inv=Math.max(0,parseFloat(qs('#invoiceDiscount')?.value||0)),tax=Math.max(0,parseFloat(qs('#tax')?.value||0)),direct=Math.max(0,parseFloat(qs('#directExpense')?.value||0));const total=Math.max(0,sub-inv+tax+direct);if(qs('#grandTotal'))qs('#grandTotal').textContent=fmt(total);if(qs('#subTotal'))qs('#subTotal').textContent=fmt(sub);let paid=0;qsa('[name="pay_amount[]"]').forEach(x=>paid+=Math.max(0,parseFloat(x.value||0)));if(qs('#paidPreview'))qs('#paidPreview').textContent=fmt(paid);if(qs('#duePreview'))qs('#duePreview').textContent=fmt(Math.max(0,total-paid))}
function renumberRows(){qsa('#entryRows .sale-row').forEach((r,i)=>{const c=r.children[0];if(c)c.textContent=i+1})}
function addRow(type){const rows=qs('#entryRows');if(!rows)return;const first=qs('.sale-row',rows);const clone=first.cloneNode(true);qsa('.item-live-search',clone).forEach(el=>{el.dataset.itemSearchBound='';el.removeAttribute('data-item-search-bound');const res=qs('.item-search-results',el);if(res){res.hidden=true;res.innerHTML='';}});qsa('input',clone).forEach(i=>{if(i.classList.contains('qty'))i.value='1';else if(i.classList.contains('price')||i.classList.contains('line-disc'))i.value='0';else if(i.classList.contains('item-search-input'))i.value='';});qsa('select',clone).forEach(s=>s.selectedIndex=0);const clear=qs('.item-search-clear',clone);if(clear)clear.style.display='none';qs('.item-selected-summary',clone)?.remove();const unit=qs('.unit-label',clone);if(unit)unit.textContent='—';qs('.amount',clone).textContent=fmt(0);rows.appendChild(clone);bindEntryRow(clone);renumberRows();if(typeof window.SutoInitItemSearch==='function')window.SutoInitItemSearch(clone);}
function removeRow(btn){const rows=qsa('#entryRows .sale-row');if(rows.length<=1){const r=rows[0];qsa('select',r).forEach(x=>x.selectedIndex=0);qsa('input',r).forEach(x=>{if(x.classList.contains('qty'))x.value='1';else if(x.classList.contains('price')||x.classList.contains('line-disc'))x.value='0';else if(x.classList.contains('item-search-input'))x.value=''});qs('.item-search-clear',r)&&(qs('.item-search-clear',r).style.display='none');qs('.item-selected-summary',r)?.remove();const u=qs('.unit-label',r);if(u)u.textContent='—';qs('.amount',r).textContent=fmt(0)}else{btn.closest('.sale-row')?.remove();renumberRows();}recalc()}
function addPayment(){const wrap=qs('#paymentRows');if(!wrap)return;const first=qs('.payment-line',wrap);if(!first)return;const clone=first.cloneNode(true);qsa('input',clone).forEach(i=>{if(i.type!=='date')i.value='';});qsa('select',clone).forEach(s=>s.selectedIndex=0);const acct=qs('.pay-account',clone);if(acct){acct.style.display='none';acct.name='pay_account[]'}const cd=qs('.pay-cheque-date',clone);if(cd){cd.style.display='none';cd.value=''}const ref=qs('.pay-ref',clone)||qs('[name="pay_ref[]"]',clone);if(ref)ref.value='';const remove=qs('.payment-remove',clone);if(remove){remove.style.visibility='visible';remove.style.pointerEvents='auto'}wrap.appendChild(clone);bindPaymentLine(clone);recalc()}
function removePayment(btn){const wrap=qs('#paymentRows');if(!wrap||!btn)return;const row=btn.closest('.payment-line');if(!row)return;const rows=qsa('.payment-line',wrap);if(rows.length<=1)return;row.remove();recalc();const first=qs('.payment-line',wrap);if(first){const b=qs('.payment-remove',first);if(b){b.style.visibility='hidden';b.style.pointerEvents='none'}}}
function togglePaymentFields(sel){const row=sel&&sel.closest('.payment-line,.std-payment-row,.std-pay-grid');if(!row)return;const method=sel.value||'';const cd=qs('.pay-cheque-date',row);const ref=qs('.pay-ref',row)||qs('[name="pay_reference[]"]',row)||qs('[name="pay_ref[]"]',row)||qs('.std-cheque-ref',row);const isCheque=method==='cheque';if(cd)cd.style.display=isCheque?'block':'none';if(ref)ref.style.display=isCheque?'block':'none';if(!isCheque&&ref&&ref.querySelector('input'))ref.querySelector('input').value='';}
function serialConfig(){return window.SutoSerialSearchConfig||{};}
function selectedSerialValues(row){const ta=qs('.serial-number-input',row);if(!ta)return [];return ta.value.split(/[\r\n,;]+/).map(v=>v.trim()).filter(Boolean)}
function renderSerialMeta(row){const box=qs('.serial-entry-box',row),ta=qs('.serial-number-input',row),meta=qs('.serial-entry-meta',row),sel=qs('.item-select',row),qty=parseFloat(qs('.qty',row)?.value||0)||0;if(!box||!ta||!meta)return;const tracked=sel?.selectedOptions?.[0]?.dataset?.serialTracked==='1';if(!tracked){box.hidden=true;meta.textContent='';return;}box.hidden=false;const count=selectedSerialValues(row).length;const mode=box.dataset.mode||'sale';if(mode==='purchase')meta.textContent='Required: '+count+' serial number(s) entered for quantity '+qty+'. One serial per unit.';else meta.textContent=count>0?count+' serial number(s) selected for quantity '+qty+'. Leave blank to auto-select available serials.':'You may enter/select serial numbers, or leave blank for automatic available-serial selection.';}
async function openSerialPicker(row){const sel=qs('.item-select',row),box=qs('.serial-entry-box',row),ta=qs('.serial-number-input',row);if(!sel||!box||!ta)return;const itemId=Number(sel.value||0),tracked=sel.selectedOptions?.[0]?.dataset?.serialTracked==='1';if(!itemId||!tracked)return;let panel=qs('.serial-picker-panel',box);if(!panel){panel=document.createElement('div');panel.className='serial-picker-panel';panel.innerHTML='<div class=\"serial-picker-head\"><strong>Select Available Serial(s)</strong><button type=\"button\" class=\"serial-picker-close\">×</button></div><input type=\"text\" class=\"serial-picker-search\" placeholder=\"Search serial number...\" autocomplete=\"off\"><div class=\"serial-picker-results\"></div>';box.appendChild(panel);panel.querySelector('.serial-picker-close').addEventListener('click',()=>panel.hidden=true);panel.querySelector('.serial-picker-search').addEventListener('input',()=>loadSerialOptions(row,panel));}panel.hidden=false;loadSerialOptions(row,panel);}
async function loadSerialOptions(row,panel){const sel=qs('.item-select',row),ta=qs('.serial-number-input',row),results=qs('.serial-picker-results',panel),q=qs('.serial-picker-search',panel)?.value.trim()||'';if(!sel||!ta||!results)return;const itemId=Number(sel.value||0);if(!itemId){results.innerHTML='<div class=\"serial-picker-empty\">Select an item first.</div>';return;}const url=serialConfig().url;if(!url){results.innerHTML='<div class=\"serial-picker-empty\">Serial search is unavailable.</div>';return;}results.innerHTML='<div class=\"serial-picker-empty\">Loading…</div>';try{const u=new URL(url,location.origin);u.searchParams.set('item_id',itemId);if(q)u.searchParams.set('q',q);const res=await fetch(u.toString(),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});const data=await res.json();if(!res.ok||!data.ok)throw new Error(data.error||'Failed');const selected=new Set(selectedSerialValues(row));if(!data.items?.length){results.innerHTML='<div class=\"serial-picker-empty\">No available serial found.</div>';return;}results.innerHTML=data.items.map(it=>{const active=selected.has(String(it.serial_number));return '<button type=\"button\" class=\"serial-picker-option '+(active?'selected':'')+'\" data-serial=\"'+String(it.serial_number).replace(/&/g,'&amp;').replace(/\"/g,'&quot;')+'\"><span>'+String(it.serial_number).replace(/[&<>]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[m]))+'</span><b>'+(active?'Selected':'Select')+'</b></button>';}).join('');results.querySelectorAll('.serial-picker-option').forEach(btn=>btn.addEventListener('click',()=>{const sn=btn.dataset.serial||'';let vals=selectedSerialValues(row);const i=vals.indexOf(sn);if(i>=0)vals.splice(i,1);else vals.push(sn);ta.value=vals.join('\n');renderSerialMeta(row);loadSerialOptions(row,panel);}));}catch(e){results.innerHTML='<div class=\"serial-picker-empty\">Unable to load serials.</div>';console.error(e)}}
function updatePrice(sel){const row=sel?.closest('.sale-row');const opt=sel?.selectedOptions?.[0];if(row&&opt){const price=qs('.price',row);const unit=qs('.unit-label',row);if(price)price.value=(document.body.dataset.txntype==='purchase'?opt.dataset.buy:opt.dataset.sale)||0;if(unit)unit.textContent=opt.dataset.unit||'—';renderSerialMeta(row);if(document.body.dataset.txntype==='sale'&&row.dataset.bundleChild!=='1'&&typeof window.syncBundleForRow==='function')window.syncBundleForRow(row);recalc()}}
function bindEntryRow(row){qsa('.qty,.price,.line-disc',row).forEach(x=>x.addEventListener('input',()=>{if(x.classList.contains('qty')){renderSerialMeta(row);if(document.body.dataset.txntype==='sale'&&row.dataset.bundleChild!=='1'&&typeof syncBundleForRow==='function')syncBundleForRow(row);}recalc()}));qs('.item-select',row)?.addEventListener('change',e=>updatePrice(e.target));qs('.serial-number-input',row)?.addEventListener('input',()=>renderSerialMeta(row));qs('.serial-picker-btn',row)?.addEventListener('click',()=>openSerialPicker(row));renderSerialMeta(row);}
function bindPaymentLine(row){qs('select[name="pay_method[]"]',row)?.addEventListener('change',e=>togglePaymentFields(e.target));qs('input[name="pay_amount[]"]',row)?.addEventListener('input',recalc)}
function validateTransactionForm(){const totalText=qs('#grandTotal')?.textContent||'৳0.00';const total=Number(totalText.replace(/[^0-9.-]/g,''))||0;let paid=0;qsa('[name="pay_amount[]"]').forEach(x=>paid+=Math.max(0,parseFloat(x.value||0)));if(paid>total+0.01){alert('Payment cannot be greater than invoice total.');return false}return true}
function toggleStock(){const svc=qs('input[name=item_type]:checked')?.value==='service'||qs('#item_type')?.value==='service';qsa('.stock-field').forEach(x=>x.style.display=svc?'none':'block')}
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
document.addEventListener('DOMContentLoaded',()=>{bindDots();qsa('.qty,.price,.line-disc,[name="invoice_discount"],[name="tax"],[name="direct_expense"]').forEach(x=>x.addEventListener('input',recalc));qsa('.sale-row').forEach(bindEntryRow);qsa('.payment-line').forEach(bindPaymentLine);qsa('#addMoreBtn').forEach(x=>x.addEventListener('click',e=>{e.stopPropagation();toggleMenu('addMore')}));qs('#item_type')?.addEventListener('change',toggleStock);toggleStock();recalc()})
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
    const baseRoot = (document.querySelector('meta[name="base-url"]')?.getAttribute('content') || '').replace(/\/+$/, '');
    const base = baseRoot + '/public/expense-items-api.php';
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
function toggleExpensePayment(sel){const row=sel?.closest('.expense-entry-payment-row') || sel?.closest('.expense-payment-line') || sel?.closest('.std-payment-row');if(!row)return;const v=sel.value||'';const isCheque=v==='cheque';const acct=row&&qs('.expense-entry-bank',row);if(acct)acct.style.display=v.startsWith('bank|')?'block':'none';const old=row&&qs('.expense-bank-field',row);if(old)old.style.display=v.startsWith('bank|')?'block':'none';const ref=row&&qs('.std-cheque-ref',row);if(ref)ref.style.display=isCheque?'block':'none';const payRef=row&&qs('input[name="pay_reference[]"]',row);if(payRef&&ref===null)payRef.closest('.std-field').style.display=isCheque?'block':'none';}
function validateExpenseForm(){expenseEntryRecalc();const total=parseFloat(qs('#expenseAmount')?.value||0)||0;let paid=0;qsa('[name="pay_amount[]"]').forEach(x=>paid+=Math.max(0,parseFloat(x.value||0)));if(total<=0){alert('Please add at least one expense item with a valid price.');return false}if(paid>total+0.01){alert('Payment cannot be greater than expense amount.');return false}return true}
document.addEventListener('DOMContentLoaded',()=>{if(qs('#expenseEntryForm')){qsa('#expenseItemRows .expense-line-qty,#expenseItemRows .expense-line-price').forEach(x=>x.addEventListener('input',expenseEntryRecalc));qsa('#expenseEntryPayments .expense-entry-payment-row').forEach(bindExpenseEntryPayment);qsa('#expenseEntryPayments select[name="pay_method[]"]').forEach(toggleExpensePayment);renumberExpenseRows();expenseEntryRecalc();}});
document.addEventListener('change',e=>{const sel=e.target.closest('.std-payment-row select[name="pay_method[]"]');if(sel)toggleExpensePayment(sel);});


/* v139: sidebar collapse toggle */
(function(){
  function applySidebarState(collapsed){
    document.body.classList.toggle('sidebar-collapsed', collapsed);
    var b=document.getElementById('sidebarCollapseBtn');
    if(b){
      b.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      b.setAttribute('aria-label', collapsed ? 'Expand menu' : 'Collapse menu');
    }
  }
  function init(){
    var collapsed=localStorage.getItem('suto_sidebar_collapsed')==='1';
    applySidebarState(collapsed);
    var b=document.getElementById('sidebarCollapseBtn');
    if(!b) return;
    b.addEventListener('click',function(){
      var next=!document.body.classList.contains('sidebar-collapsed');
      localStorage.setItem('suto_sidebar_collapsed', next ? '1' : '0');
      applySidebarState(next);
    });
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();

// v192: Notification quick panel
(function(){
  function bindNotificationQuickPanel(){
    var btn=document.getElementById('notificationQuickBtn');
    var panel=document.getElementById('notificationQuickPanel');
    if(!btn||!panel) return;
    btn.addEventListener('click',function(e){e.stopPropagation();var open=panel.hasAttribute('hidden');if(open){panel.removeAttribute('hidden');btn.setAttribute('aria-expanded','true')}else{panel.setAttribute('hidden','');btn.setAttribute('aria-expanded','false')}});
    document.addEventListener('click',function(e){if(!panel.contains(e.target)&&e.target!==btn){panel.setAttribute('hidden','');btn.setAttribute('aria-expanded','false')}});
    document.addEventListener('keydown',function(e){if(e.key==='Escape'){panel.setAttribute('hidden','');btn.setAttribute('aria-expanded','false')}});
  }
  document.addEventListener('DOMContentLoaded',bindNotificationQuickPanel);
})();

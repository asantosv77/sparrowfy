(()=>{'use strict';
window.sfMembershipPicker=async({title,load,save,trigger})=>{
 const d=document.createElement('dialog');d.className='sf-membership-picker';
 const h=document.createElement('h3');h.textContent=title;
 const search=document.createElement('input');search.type='search';search.placeholder='Buscar…';search.setAttribute('aria-label','Buscar destino');
 const status=document.createElement('p');status.setAttribute('role','status');
 const list=document.createElement('div');list.className='sf-membership-options';
 const close=document.createElement('button');close.type='button';close.className='btn';close.textContent='Cerrar';
 let items=[],busy=false;
 const finish=()=>{if(!busy)d.close();};close.onclick=finish;
 d.addEventListener('cancel',e=>{if(busy)e.preventDefault();});d.addEventListener('close',()=>{d.remove();trigger?.focus();});
 d.append(h,search,status,list,close);document.body.append(d);d.showModal();search.focus();
 function render(){list.replaceChildren();const shown=items.filter(x=>x.name.toLocaleLowerCase().includes(search.value.toLocaleLowerCase()));
 for(const item of shown){const row=document.createElement('label'),check=document.createElement('input'),name=document.createElement('span');check.type='checkbox';check.checked=!!item.selected;check.disabled=busy;name.textContent=item.name;row.append(check,name);list.append(row);
 check.onchange=async()=>{if(busy)return;const enabled=check.checked;busy=true;close.disabled=true;search.disabled=true;status.textContent='Guardando…';list.querySelectorAll('input').forEach(x=>x.disabled=true);
 try{await save(item,enabled);items=await load();status.textContent=enabled?'Añadida.':'Retirada.';}catch(e){status.textContent=e.message;try{items=await load();}catch{status.textContent+=' Cierra y vuelve a abrir para actualizar.';}}finally{busy=false;close.disabled=false;search.disabled=false;render();}}
 }if(!shown.length){const p=document.createElement('p');p.textContent=items.length?'Sin coincidencias.':'No hay destinos disponibles.';list.append(p);}}
 search.oninput=render;status.textContent='Cargando…';try{items=await load();status.textContent='Marca para añadir; desmarca para retirar.';render();}catch(e){status.textContent=e.message;}
};})();

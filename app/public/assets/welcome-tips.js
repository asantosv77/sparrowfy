(()=>{'use strict';
if(window.sfWelcomeTipsLoaded)return;window.sfWelcomeTipsLoaded=true;
async function openWelcome(){
 try{
  const response=await fetch('api_user_tips.php',{cache:'no-store'});if(!response.ok)return;
  const prefs=await response.json();if(!prefs.show_tips)return;
  const seenKey='sf-tips-opened-'+prefs.user_id;
  try{if(sessionStorage.getItem(seenKey))return;}catch(_){}
  const tips=[
   ['Tus favoritas, siempre a mano','Pulsa la estrella junto a una canción para añadirla o quitarla de tus favoritos. También puedes hacerlo desde la cola del reproductor.'],
   ['Crea tu propia selección','En Mis listas puedes crear listas de reproducción. Usa el menú Listas de cada canción para añadirla o quitarla con un clic; la palomita indica que ya pertenece a esa lista.'],
   ['Comparte tu música','Desde Mis listas puedes generar un enlace para compartir una copia o dar acceso por nombre de usuario de Sparrow-Fy. Compartir por usuario permite seguir las actualizaciones de la lista.'],
   ['La música te acompaña','Minimiza el reproductor para seguir navegando por la webapp mientras escuchas. Usa sus controles para volver a la pista anterior, avanzar o pausar.'],
   ['Encuentra una canción','La búsqueda del encabezado te ayuda a encontrar canciones, artistas y álbumes. En Biblioteca musical puedes elegir 25, 50 o 100 elementos por página.'],
   ['A tu ritmo','El botón de reproducción aleatoria cambia entre orden continuo y aleatorio. Activa Normalizar para reducir las diferencias de volumen entre canciones.'],
   ['Letras y ecualizador','Activa Mostrar letra en el reproductor para consultar las letras disponibles. El botón EQ abre el ecualizador para ajustar el sonido.'],
   ['Cuida tu consumo','En Mi cuenta puedes consultar tu consumo de datos y elegir el perfil de reproducción HLS. La calidad Auto se adapta a la conexión y a las calidades disponibles de la pista.'],
   ['Revisa tus accesos','En Mi cuenta, entra en Dispositivos y sesiones para revisar tus accesos y cerrar las sesiones que no reconozcas.'],
   ['Tu estilo','Abre Temas y colores para elegir la paleta y la organización de la interfaz que prefieras.'],
   ['Sparrow-Fy como aplicación','Pulsa el emblema de la esquina superior izquierda para ver Acerca de Sparrow-Fy y las opciones o instrucciones de instalación como PWA.']
  ];
  if(prefs.can_manage)tips.push(
   ['Organiza las pistas sin colección','Desde Biblioteca musical puedes subir pistas sin crear un álbum. Después puedes moverlas a una colección o gestionar sus opciones desde el menú de tres puntos.'],
   ['Completa la información','En el menú de las pistas sin asignar a colección, usa Editar para actualizar sus metadatos, buscar una portada o intentar identificarlas mediante AcoustID si está configurado.'],
   ['Mejora una fuente de audio','Usa Reemplazar en el menú de una pista para subir otro archivo o indicar un enlace de YouTube. Puedes cerrar la ventana mientras el trabajo continúa en el servidor; recibirás el resultado en una notificación.'],
   ['Reutiliza sin duplicar','Puedes añadir una canción existente a otra colección desde las opciones de gestión. El sistema conserva sus referencias sin necesitar otra copia del audio.']
  );
  if(prefs.is_admin)tips.push(['Mantenimiento bajo control','Desde Configuración puedes consultar respaldos e integridad, revisar los hallazgos de la auditoría y administrar los archivos enviados a cuarentena.']);
  let position=0;const cursorKey='sf-tips-next-'+prefs.user_id;
  try{position=Number(localStorage.getItem(cursorKey))||0;}catch(_){}
  const tip=tips[Math.abs(position)%tips.length];

  const dialog=document.createElement('dialog');dialog.className='sf-welcome-tips';dialog.setAttribute('aria-labelledby','sf-welcome-title');
  dialog.innerHTML=`<header class="sf-tips-heading"><div><p class="sf-tips-eyebrow">BIENVENIDO A SPARROW-FY</p><h2 id="sf-welcome-title">Tu laboratorio de audio adaptativo</h2></div><button type="button" class="btn" data-close>Cerrar</button></header><p>Sube MP3, FLAC, OGG, WMA, M4A o WAV. Se genera HLS en calidades <strong>acordes al bitrate real</strong> del archivo (sin inventar 320k desde un 192k).</p><article class="sf-tip-card"><span class="sf-tip-icon" aria-hidden="true">✦</span><div><p class="sf-tips-eyebrow">¿SABÍAS QUE…?</p><h3 data-tip-title></h3><p data-tip-text></p></div></article><footer><label><input type="checkbox" checked data-show-tips> Mostrar tips</label><p class="muted">Puedes volver a activarlos desde Mi cuenta.</p><p data-save-status role="status" aria-live="polite"></p></footer>`;
  dialog.querySelector('[data-tip-title]').textContent=tip[0];dialog.querySelector('[data-tip-text]').textContent=tip[1];
  const checkbox=dialog.querySelector('[data-show-tips]'),status=dialog.querySelector('[data-save-status]');
  checkbox.addEventListener('change',async()=>{
   const enabled=checkbox.checked;checkbox.disabled=true;status.textContent='Guardando…';
   try{const body=new FormData();body.set('show_tips',enabled?'1':'0');body.set('csrf_token',document.querySelector('meta[name="sf-csrf"]')?.content||document.querySelector('[name="csrf_token"]')?.value||'');const r=await fetch('api_user_tips.php',{method:'POST',body});if(!r.ok)throw Error();status.textContent=enabled?'Mostrar tips activado.':'No volverás a ver los tips hasta activarlos en Mi cuenta.';}
   catch(_){checkbox.checked=!enabled;status.textContent='No se pudo guardar. Inténtalo de nuevo.';}finally{checkbox.disabled=false;}
  });
  dialog.querySelector('[data-close]').onclick=()=>dialog.close();
  dialog.addEventListener('close',()=>dialog.remove(),{once:true});
  document.body.append(dialog);dialog.showModal();
  try{sessionStorage.setItem(seenKey,'1');localStorage.setItem(cursorKey,String(position+1));}catch(_){}
 }catch(_){}
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',openWelcome,{once:true});else openWelcome();
})();

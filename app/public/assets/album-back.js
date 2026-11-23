(()=>{'use strict';document.addEventListener('click',event=>{
 const link=event.target.closest('[data-album-back]');if(!link||event.defaultPrevented||event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;
 if(window!==window.parent && typeof window.parent.labCloseAlbumManager==='function'){event.preventDefault();window.parent.labCloseAlbumManager();return;}
 try{const previous=new URL(document.referrer);if(previous.origin===location.origin && previous.href!==location.href && history.length>1){event.preventDefault();history.back();}}catch{}
});})();

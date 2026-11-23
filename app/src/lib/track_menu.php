<?php require_once __DIR__.'/management_view.php';
function sf_track_menu(array $t,string $album,bool $delete=false):void{
 if(!lab_ui_can_manage_albums()){echo '<details class="sf-track-options" data-queue-only hidden><summary aria-label="Opciones de pista">⋮</summary><div class="sf-track-options-panel"><button type="button" data-queue-add="track" data-queue-id="'.lab_h((string)$t['id']).'" hidden>Añadir a cola</button></div></details>';return;}
 $id=lab_h((string)$t['id']);$aid=lab_h($album);$ref=!empty($t['_ref_source'])?'1':'0';
 echo '<details class="sf-track-options"><summary aria-label="Opciones de pista">⋮</summary><div class="sf-track-options-panel"><button type="button" class="btn" data-track-properties="'.$id.'">Propiedades</button>';
 if($album==='fffffffffffffffe')echo '<button type="button" class="btn" data-edit-loose="'.$id.'">Editar</button>';
 if(!empty($t['hls']))echo '<button type="button" class="btn" data-replace-track="'.$id.'" data-replace-title="'.lab_h((string)($t['name']??$t['title']??'')).'" data-replace-album="'.lab_h((string)($t['_ref_source']??$album)).'">Reemplazar</button>';
 if($delete)echo '<button type="button" class="btn" data-track-delete="'.$id.'" data-track-album="'.$aid.'" data-track-ref="'.$ref.'">Eliminar</button>';
 echo '</div></details>';
}

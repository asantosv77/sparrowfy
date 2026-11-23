<?php
// Included by index.php; each section has an independent page number.
function sf_page(array $items,string $key): array {
 $size=(int)($_GET['per_page']??25);if(!in_array($size,[25,50,100],true))$size=25;
 $total=count($items);$pages=max(1,(int)ceil($total/$size));
 $page=max(1,min($pages,(int)($_GET[$key]??1)));
 return ['items'=>array_slice($items,($page-1)*$size,$size),'total'=>$total,'size'=>$size,'page'=>$page,'pages'=>$pages,'key'=>$key];
}
function sf_pager(array $p,string $label): void {
 $params=array_intersect_key($_GET,array_flip(['view','q','per_page','albums_page','loose_page','tracks_page','loose_sort']));
 $params['view']=(string)($_GET['view']??'library');$params['per_page']=$p['size'];
 echo '<nav class="sf-pagination" aria-label="'.lab_h($label).'"><span class="muted">';
 echo $p['total']? (($p['page']-1)*$p['size']+1).'–'.min($p['total'],$p['page']*$p['size']).' de '.$p['total'] : '0 elementos';
 echo '</span>';
 if($p['pages']>1){
 foreach(array_unique([1,max(1,$p['page']-1),$p['page'],min($p['pages'],$p['page']+1),$p['pages']]) as $n){
 $params[$p['key']]=$n;
 echo '<a class="btn sf-compact" '.($n===$p['page']?'aria-current="page" ':'').'href="index.php?'.lab_h(http_build_query($params)).'">'.$n.'</a>';
 }
 echo '<span class="muted">Página '.$p['page'].' de '.$p['pages'].'</span>';
 }echo '</nav>';
}
function sf_page_size(): void {
 $size=(int)($_GET['per_page']??25);if(!in_array($size,[25,50,100],true))$size=25;
 echo '<form class="sf-page-size" method="get" action="index.php"><input type="hidden" name="view" value="'.lab_h((string)($_GET['view']??'library')).'"><input type="hidden" name="q" value="'.lab_h((string)($_GET['q']??'')).'"><label>Elementos por página <select name="per_page">';
 foreach([25,50,100] as $n)echo '<option value="'.$n.'"'.($n===$size?' selected':'').'>'.$n.'</option>';
 echo '</select></label>';
 if(($_GET['view']??'')==='library')echo '<input type="hidden" name="loose_sort" value="'.lab_h(sf_loose_order()).'">';
 echo ' <button class="btn sf-compact" type="submit">Aplicar</button></form>';
}

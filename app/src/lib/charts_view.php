<?php

declare(strict_types=1);

require_once __DIR__.'/music_charts.php';

function sfc_home():void {

 try{$settings=sfc_settings();if(!$settings['enabled'])return;$db=lab_auth_pdo();$charts=$db->query('SELECT * FROM sf_music_charts WHERE enabled=1 ORDER BY chart_key')->fetchAll(PDO::FETCH_ASSOC);}

 catch(Throwable $e){return;}

 ?><section class="card sf-charts-section" aria-labelledby="musicChartsTitle" data-music-charts><header class="sf-charts-heading"><div><span class="sf-charts-kicker">DESCUBRE ESTA SEMANA</span><h2 id="musicChartsTitle">Music Charts</h2></div><div class="sf-charts-links"><a class="btn" href="music_charts.php">Rankings y resumen</a><?php if(lab_auth_is_admin()):?><a class="btn" href="music_charts_config.php">Configurar</a><?php endif;?></div></header>

 <p class="muted">Selecciones de rankings oficiales, actualizadas cada semana. <?=$settings['genre_filter_enabled']?'Selección filtrada por géneros; las posiciones originales se conservan. ':''?> Se reutiliza el audio de tu biblioteca cuando coincide.</p><div class="sf-chart-grid">

 <?php if(!$charts):?><p class="muted">Preparando los primeros rankings. El servicio los consultará en segundo plano.</p><?php endif;?>

 <?php foreach($charts as $chart):$rows=sfc_entry_rows($db,$chart['chart_key']);$ready=count(array_filter($rows,static fn($r)=>$r['state']==='ready'));$review=count(array_filter($rows,static fn($r)=>in_array($r['state'],['review','genre_review'],true)));$unavailable=count(array_filter($rows,static fn($r)=>$r['state']==='review'));$genreReview=count(array_filter($rows,static fn($r)=>$r['state']==='genre_review'));$art=array_values(array_filter(array_column(array_column($rows,'song'),'artwork')));$country=$chart['provider']==='deezer'?str_replace('Deezer · ','',$chart['title']):(['mx'=>'México','us'=>'Estados Unidos','global'=>'Global'][$chart['market']]??$chart['market']);?><article class="sf-chart-card sf-chart-<?=lab_h($chart['market'])?>"><?php if($chart['album_id']):$queueIds=array_values(array_filter(array_map(static fn($r)=>$r['state']==='ready'?$r['track_id']:null,$rows)));$canCopy=lab_auth_can_manage_albums();?><details class="sf-chart-menu" <?=$canCopy?'':'data-queue-only hidden'?>><summary aria-label="Opciones del TOP <?=lab_h($chart['title'])?>" title="Opciones del TOP">⋮</summary><div class="sf-chart-menu-panel"><button type="button" data-queue-add="album" data-queue-id="<?=lab_h($chart['album_id'])?>" data-queue-tracks="<?=lab_h(json_encode($queueIds))?>" hidden>Agregar a cola</button><?php if($canCopy):?><a href="album_edit.php?id=<?=lab_h($chart['album_id'])?>">Gestionar álbum</a><button type="button" data-chart-copy="<?=lab_h($chart['chart_key'])?>">Guardar TOP como colección</button><?php endif;?></div></details><?php endif;?>

 <a class="sf-chart-play" href="<?=$ready?'player.php?album='.lab_h((string)$chart['album_id']):'music_charts.php#chart-'.lab_h($chart['chart_key'])?>" aria-label="<?=$ready?'Reproducir':'Ver estado de'?> <?=lab_h($chart['title'])?>"><div class="sf-chart-cover"><div class="sf-chart-mosaic" aria-hidden="true"><?php foreach(array_slice($art,0,4) as $image):?><img src="<?=lab_h($image)?>" alt="" loading="eager" referrerpolicy="no-referrer"><?php endforeach;?></div><span class="sf-chart-provider"><?=lab_h(['apple'=>'Apple Music','itunes_christian'=>'iTunes · Cristiana','billboard_christian'=>'Billboard · Cristiana','spotify'=>'Spotify Charts','deezer'=>'Deezer','billboard_hot100'=>'Billboard · Hot 100','billboard_latin_pop'=>'Billboard · Pop latino','billboard_rock'=>'Billboard · Rock','billboard_global'=>'Billboard · Global 200'][$chart['provider']]??$chart['provider'])?></span><strong>TOP <?=count($rows)?:$settings['tracks_per_chart']?></strong><span class="sf-chart-country"><?=lab_h($country)?></span><span class="sf-chart-play-icon" aria-hidden="true"><?=$ready?'▶':'◌'?></span></div><h3><?=lab_h($chart['title'])?></h3></a>

 <?php if($rows&&$ready<count($rows)&&$ready+$review<count($rows)):?><div class="sf-charts-busy" role="status" aria-label="Preparando las pistas del ranking"></div><?php endif;?><?php if(lab_auth_is_admin()):?><p class="sf-chart-status"><?=$rows?$ready.' / '.count($rows).' disponibles':($settings['genre_filter_enabled']&&!empty($chart['last_week'])?'Sin coincidencias de los géneros elegidos':'Esperando datos verificados')?><?=$unavailable?' · '.$unavailable.' no obtenidas':''?><?=$genreReview?' · '.$genreReview.' pendientes de género':''?></p><?php endif;?><?php if($chart['source_updated']):?><small>Fuente: <?=lab_h(date('d/m/Y',strtotime($chart['source_updated'])))?></small><?php endif;?><?php if($chart['error']):?><p class="sf-chart-error"><?=lab_h($chart['error'])?></p><?php endif;?>

 </article><?php endforeach;?></div>

 <p class="muted sf-charts-footnote">La fecha del proveedor y la captura están en el resumen. Las variaciones usan la posición anterior publicada por el proveedor cuando existe. Las pistas son obtenidas en best effort, no siempre se puede lograr obtener el TOP 20 completo.</p></section><?php

}

function sfc_report_html(array $report):void {

 ?><p class="muted">Resumen generado el <time datetime="<?=lab_h($report['created_at'])?>"><?=lab_h($report['created_at'])?></time>. Semana de gestión desde <?=lab_h($report['week'])?>.</p>

 <?php foreach($report['sections'] as $section):?><section class="card sf-chart-report"><h3><?=lab_h($section['title'])?></h3><p><a href="<?=lab_h($section['source_url']??'#')?>" target="_blank" rel="noopener noreferrer">Consultar fuente oficial ↗</a></p><?php if($section['error']):?><p class="sf-chart-error"><?=lab_h($section['error'])?></p><?php endif;?>

 <p class="muted">Ranking actual capturado: <time datetime="<?=lab_h($section['captured_at']??'')?>"><?=lab_h($section['captured_at']??'No disponible')?></time>. Actualización declarada: <?=lab_h($section['source_updated']??'No disponible')?>.<br>Periodo oficial: <?=($section['period_kind']??'')==='weekly'?'edición semanal del '.lab_h($section['issue_date']??$section['source_updated']).($section['period_start']?' ('.lab_h($section['period_start'].' — '.$section['period_end']).')':''):($section['period_start']?lab_h($section['period_start'].' — '.$section['period_end']):'no publicado en el feed; se muestra una captura del ranking actual')?>.</p>

 <?php if(($section['period_kind']??'')==='weekly'):?><p>Variación respecto a la posición de la semana anterior publicada por el proveedor. Sin dato cuando la fuente no la proporciona.</p><?php elseif($section['compared_at']):?><p>Variación respecto a la captura del <time datetime="<?=lab_h($section['compared_at'])?>"><?=lab_h($section['compared_at'])?></time>.</p><?php else:?><p class="muted">Primera captura: todavía no hay datos para afirmar subidas, bajadas o nuevas entradas.</p><?php endif;?>

 <div class="sf-charts-table-wrap"><table class="sf-charts-table"><thead><tr><th>#</th><th>Canción</th><th>Artista</th><th>Variación</th></tr></thead><tbody><?php foreach($section['tracks'] as $t):?><tr><td><?=$t['rank']?></td><td><a href="<?=lab_h($t['source_url'])?>" target="_blank" rel="noopener noreferrer"><?=lab_h($t['title'])?></a><?=!empty($t['genre_review'])?' · género por verificar':''?></td><td><?=lab_h($t['artist'])?></td><td><?=$t['new_entry']?'Nueva entrada':($t['change']===null?'Sin dato':($t['change']>0?'↑ '.$t['change']:($t['change']<0?'↓ '.abs($t['change']):'Sin cambio')))?></td></tr><?php endforeach;?></tbody></table></div>

 <p><strong>Artistas con mayor presencia en esta selección:</strong> <?php $parts=[];foreach($section['artist_presence'] as $name=>$count)$parts[]=$name.' ('.$count.')';echo lab_h(implode(' · ',$parts)?:'Sin datos'); ?>.</p><p><strong>Géneros de las canciones seleccionadas:</strong> <?php $parts=[];foreach($section['genres'] as $name=>$count)$parts[]=$name.' ('.$count.')';echo lab_h(implode(' · ',$parts)?:'Sin datos'); ?>.</p></section><?php endforeach;?>

 <details><summary>Cómo interpretar este informe</summary><ul><?php foreach($report['notes'] as $note):?><li><?=lab_h($note)?></li><?php endforeach;?></ul></details><?php

}


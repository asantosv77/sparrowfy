<?php require_once __DIR__.'/management_view.php'; require_once __DIR__.'/cover_url.php'; function sf_home_cards(array $recentItems):void{ $canManageAlbums=lab_ui_can_manage_albums(); $canFavorite=is_array(lab_auth_current_account()); ?>
        <?php foreach($recentItems as $item):
          $a=$item['album']??null;$t=$item['track']??null;
          $cover=sf_cover_url($a);
          $href='player.php?album='.rawurlencode((string)($a['id']??$t['album_id']));
          if($t)$href.='&i='.(int)$t['index'];
        ?>
          <article class="album-card" <?php if($a): ?>data-album-id="<?= lab_h($a['id']) ?>" data-queue-tracks="<?= lab_h(json_encode(array_column($a['tracks']??[],'id'))) ?>" data-album-title="<?= lab_h($a['title']??'') ?>" data-album-artist="<?= lab_h($a['artist']??'') ?>"<?php endif; ?>>
            <div class="album-cover-wrap">
              <?php if($a && $canFavorite): ?><button type="button" class="sf-album-star" data-sf-album-fav="<?= lab_h($a['id']) ?>" aria-pressed="false" title="Añadir colección a favoritos" aria-label="Añadir colección a favoritos">☆</button><?php endif; ?>
              <?php if($a): ?><button type="button" class="album-menu-btn" <?= !$canManageAlbums?'data-queue-only hidden':'' ?> title="Opciones" aria-label="Opciones del álbum">⋮</button>
                <?php else: ?><div class="sf-home-track-menu"><?php sf_track_menu($t,(string)$t['album_id'],true); ?></div><?php endif; ?>
            <a class="album-cover-link" aria-label="<?= lab_h('Reproducir o continuar: '.(string)($a['title']??$t['name']??'Sin título')) ?>" href="<?= lab_h($href) ?>"><div class="album-cover" style="<?= $cover?'background-image:url('.lab_h($cover).')':'' ?>"><?php if(!$cover): ?><span class="cover-placeholder">♫</span><?php endif; ?><span class="play-badge">▶</span></div></a>
            </div>
            <strong class="album-card-title"><?= lab_h((string)($a['title']??$t['name']??'Sin título')) ?></strong>
            <span class="muted"><?= $a?count($a['tracks']??[]).(count($a['tracks']??[])===1?' pista':' pistas').' · Colección':'Pista suelta' ?></span>
          </article>
        <?php endforeach; ?>
<?php } ?>

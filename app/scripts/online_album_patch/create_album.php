<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
lab_auth_require_album_manager();
?><!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="application-name" content="HLS Music Lab">
  <title>Crear álbum · HLS Music Lab</title>
  <link rel="stylesheet" href="assets/lab.css">
  <style>
    .create-album-main { max-width: 1040px; width: calc(100% - 32px); margin: 1.5rem auto; }
    .create-album-intro { margin-bottom: 1rem; }
    .create-album-options { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:1rem; }
    .create-album-option { display:flex; flex-direction:column; align-items:flex-start; gap:.8rem; padding:1.35rem; min-width:0; border:1px solid #263344; border-radius:14px; background:#111827; }
    .create-album-option.online { border:1px dashed #08b889; }
    .create-album-icon { display:grid; place-items:center; width:48px; height:48px; border-radius:12px; color:#10b981; background:#092d2a; }
    .create-album-icon svg { width:28px; height:28px; stroke:currentColor; fill:none; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }
    .create-album-option h2 { margin:.1rem 0; font-size:1.2rem; }
    .create-album-option p { margin:0; color:#a8b9d2; line-height:1.55; }
    .create-album-option .btn { margin-top:auto; }
    .create-album-note { margin-top:1rem; }
    @media(max-width:700px) { .create-album-options { grid-template-columns:1fr; } .create-album-main { width:calc(100% - 20px); } }
  </style>
</head>
<body>
  <header class="lab-header">
    <div>
      <h1>Crear un álbum</h1>
      <p class="muted">Elige cómo quieres organizar tu música.</p>
    </div>
    <div class="header-actions">
      <a class="btn" href="index.php?view=library">Biblioteca</a>
      <a class="btn" href="index.php">Inicio</a>
    </div>
  </header>
  <main class="create-album-main">
    <div class="create-album-options">
      <section class="create-album-option">
        <div class="create-album-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/><path d="M4 3h16a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"/></svg>
        </div>
        <h2>Álbum con archivos de audio</h2>
        <p>Crea un álbum tradicional, sube tus archivos de audio y conviértelos a HLS para reproducirlos con calidad adaptativa.</p>
        <a class="btn primary" href="album_edit.php#createForm">Crear álbum con archivos</a>
      </section>
      <section class="create-album-option online">
        <div class="create-album-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M22 8.2a2.8 2.8 0 0 0-2-2C18.2 5.7 12 5.7 12 5.7s-6.2 0-8 .5a2.8 2.8 0 0 0-2 2A29 29 0 0 0 1.5 12 29 29 0 0 0 2 15.8a2.8 2.8 0 0 0 2 2c1.8.5 8 .5 8 .5s6.2 0 8-.5a2.8 2.8 0 0 0 2-2 29 29 0 0 0 .5-3.8 29 29 0 0 0-.5-3.8Z"/><path d="m10 9 5 3-5 3Z"/></svg>
        </div>
        <span class="idea-tag">Álbum virtual · sin descargar</span>
        <h2>Álbum desde YouTube Music</h2>
        <p>Pega enlaces de canciones, inspecciónalos y crea una lista virtual sin descargar ni convertir los audios a HLS.</p>
        <a class="btn primary" href="album_edit.php#onlineAlbumCard">Crear álbum desde enlaces</a>
      </section>
    </div>
    <p class="muted create-album-note">También puedes elegir cualquiera de los dos métodos desde el editor. Los álbumes virtuales dependen de la disponibilidad y las restricciones de YouTube; no ofrecen HLS adaptativo ni controlan anuncios.</p>
  </main>
  <script src="assets/shell-player.js"></script>
  <link rel="stylesheet" href="assets/player.css">
</body>
</html>

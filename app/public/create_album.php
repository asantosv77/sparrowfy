<?php
declare(strict_types=1);
// La creación de álbumes vive en album_edit.php#createForm. Este punto de
// entrada se conserva para enlaces antiguos y redirige directo al formulario
// de «Crear álbum y añadir pistas». Los álbumes virtuales de YouTube ya no se
// ofrecen: todas las pistas se convierten a HLS para conservar el control de
// la reproducción.
require __DIR__ . '/lib.php';
lab_auth_require_album_manager();
header('Location: album_edit.php#createForm', true, 303);
exit;

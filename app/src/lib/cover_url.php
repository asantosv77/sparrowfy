<?php
declare(strict_types=1);
/**
 * URL pública de la portada del álbum, versionada con la fecha del archivo.
 * El navegador y la PWA pueden cachear la imagen, pero al cambiar la portada
 * cambia el sufijo ?v= y la descargan de nuevo.
 */
if (!function_exists('sf_cover_url')) {
    function sf_cover_url(?array $album): string {
        if (!$album || empty($album['cover']) || empty($album['id'])) return '';
        $id = (string)$album['id'];
        $cover = (string)$album['cover'];
        $base = defined('LAB_ALBUMS') ? LAB_ALBUMS : dirname(__DIR__).'/data/albums';
        $file = rtrim($base, '/\\').'/'.$id.'/'.$cover;
        $ver = is_file($file) ? (string)filemtime($file) : '';
        return 'data/albums/'.rawurlencode($id).'/'.rawurlencode($cover).($ver !== '' ? '?v='.$ver : '');
    }
}

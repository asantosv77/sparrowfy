package com.sparrowfy.player

import android.content.Context
import android.net.Uri
import org.json.JSONObject
import java.io.File
import java.security.MessageDigest

/**
 * Descargas HLS locales: manifiesto y segmentos quedan en almacenamiento interno,
 * nunca en la carpeta compartida. Todo se borra al cerrar sesión o al perder la marca
 * de sesión en cacheDir.
 */
object OfflineStore {
    data class Track(
        val id: String,
        val name: String,
        val artist: String,
        val coverUrl: String,
        val bytes: Long,
        val segments: Int,
        val quality: String,
        val createdAt: Long
    )

    private lateinit var app: Context

    fun init(context: Context) {
        if (!::app.isInitialized) app = context.applicationContext
    }

    private fun hash(value: String): String =
        MessageDigest.getInstance("SHA-256")
            .digest(value.toByteArray(Charsets.UTF_8))
            .joinToString("") { byte -> "%02x".format(byte.toInt() and 0xff) }

    private fun root(): File = File(app.filesDir, "offline_tracks")

    fun userRoot(userId: String): File = File(root(), hash(userId))

    private fun trackDir(userId: String, trackId: String): File = File(userRoot(userId), trackId)

    private fun metadataFile(userId: String, trackId: String): File =
        File(trackDir(userId, trackId), "track.json")

    fun playlistFile(userId: String, trackId: String): File =
        File(trackDir(userId, trackId), "playlist.m3u8")

    fun list(userId: String): List<Track> {
        val dirs = userRoot(userId).listFiles() ?: return emptyList()
        return dirs
            .filter { it.isDirectory && !it.name.startsWith('.') }
            .mapNotNull { dir -> readMetadata(File(dir, "track.json")) }
            .filter { has(userId, it.id) }
            .sortedByDescending { it.createdAt }
    }

    fun get(userId: String, trackId: String): Track? =
        readMetadata(metadataFile(userId, trackId))?.takeIf { has(userId, trackId) }

    fun has(userId: String, trackId: String): Boolean =
        playlistFile(userId, trackId).isFile && metadataFile(userId, trackId).isFile

    private fun readMetadata(file: File): Track? = try {
        val o = JSONObject(file.readText(Charsets.UTF_8))
        Track(
            id = o.optString("id"),
            name = o.optString("name"),
            artist = o.optString("artist"),
            coverUrl = o.optString("cover_url"),
            bytes = o.optLong("bytes"),
            segments = o.optInt("segments"),
            quality = o.optString("quality"),
            createdAt = o.optLong("created_at")
        )
    } catch (_: Exception) {
        null
    }

    private fun writeMetadata(file: File, track: Track) {
        JSONObject()
            .put("id", track.id)
            .put("name", track.name)
            .put("artist", track.artist)
            .put("cover_url", track.coverUrl)
            .put("bytes", track.bytes)
            .put("segments", track.segments)
            .put("quality", track.quality)
            .put("created_at", track.createdAt)
            .let { file.writeText(it.toString(), Charsets.UTF_8) }
    }

    fun usedBytes(userId: String, excludeId: String? = null): Long =
        list(userId).filter { it.id != excludeId }.sumOf { it.bytes }

    /** Directorio temporal dentro del mismo usuario para poder renombrar al terminar. */
    fun prepare(userId: String, trackId: String): File {
        userRoot(userId).mkdirs()
        val temp = File(userRoot(userId), ".tmp-$trackId-${System.currentTimeMillis()}")
        temp.deleteRecursively()
        check(temp.mkdirs()) { "No se pudo crear el espacio de descarga" }
        return temp
    }

    fun discard(temp: File) {
        temp.deleteRecursively()
    }

    fun commit(userId: String, temp: File, track: Track) {
        val finalDir = trackDir(userId, track.id)
        if (finalDir.exists()) finalDir.deleteRecursively()
        check(temp.renameTo(finalDir)) { "No se pudo guardar la descarga" }
        writeMetadata(metadataFile(userId, track.id), track)
    }

    fun remove(userId: String, trackId: String) {
        trackDir(userId, trackId).deleteRecursively()
    }

    fun clear() {
        root().deleteRecursively()
    }

    /** Se usa desde el reproductor: la pista descargada se reproduce sin red. */
    fun playlistUriForTrack(trackId: String): String? {
        val users = root().listFiles() ?: return null
        for (userDir in users) {
            if (!userDir.isDirectory || userDir.name.startsWith('.')) continue
            val playlist = File(userDir, "$trackId/playlist.m3u8")
            if (playlist.isFile && File(userDir, "$trackId/track.json").isFile) {
                return Uri.fromFile(playlist).toString()
            }
        }
        return null
    }

    /** Si el usuario borró la caché, no debe quedar ninguna descarga ni sesión. */
    fun ensureActiveSession() {
        if (::app.isInitialized && !TokenStore.sessionActive()) {
            clear()
            TokenStore.clear(app)
        }
    }
}

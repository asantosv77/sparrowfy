package com.sparrowfy.player

import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.net.URLEncoder
import java.util.concurrent.TimeUnit

/** La API responde 401 cuando el token expiró o fue revocado desde otra sesión. */
class SessionRevokedException : Exception("Sesión revocada")

object SparrowApi {
    private fun base() = Settings.server.trimEnd('/') + "/api/v1/index.php"
    private val json = "application/json; charset=utf-8".toMediaType()
    private val client = OkHttpClient.Builder()
        .connectTimeout(8, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .build()

    data class Track(
        val id: String,
        val name: String,
        val subtitle: String,
        val coverUrl: String = ""
    )
    data class Album(
        val id: String,
        val title: String,
        val artist: String,
        val coverUrl: String = "",
        val trackCount: Int = 0
    )
    data class Playlist(
        val id: String,
        val name: String,
        val description: String,
        val trackCount: Int,
        val ownerId: String,
        val ownerUsername: String,
        val isOwner: Boolean
    )
    data class User(val id: String, val username: String, val role: String)
    data class Lyrics(
        val synced: String?,
        val plain: String?,
        val source: String?,
        val title: String,
        val artist: String
    )

    private fun enc(value: String) = URLEncoder.encode(value, "UTF-8")
    private fun bearer() = TokenStore.token.orEmpty()

    /** La API devuelve rutas absolutas del servidor (/data/albums/...). */
    fun absoluteUrl(path: String): String {
        val value = path.trim()
        if (value.isBlank()) return ""
        if (value.startsWith("http://", true) || value.startsWith("https://", true)) return value
        return Settings.server.trimEnd('/') + "/" + value.trimStart('/')
    }

    private fun call(
        method: String,
        route: String,
        params: List<Pair<String, String>> = emptyList(),
        body: JSONObject? = null
    ): JSONObject {
        val url = StringBuilder(base()).append("?r=").append(enc(route))
        params.forEach { (key, value) ->
            url.append('&').append(enc(key)).append('=').append(enc(value))
        }
        val request = Request.Builder()
            .url(url.toString())
            .header("Authorization", "Bearer ${bearer()}")
            .method(
                method,
                body?.toString()?.toRequestBody(json)
                    ?: if (method == "GET") null else "".toRequestBody(json)
            )
            .build()
        // Solo GET: un 500 eventual por bloqueo SQLite se reintenta sin duplicar escrituras.
        val attempts = if (method == "GET") 2 else 1
        for (attempt in 0 until attempts) {
            client.newCall(request).execute().use { response ->
                val text = response.body?.string().orEmpty()
                if (response.isSuccessful) return JSONObject(text)
                if (response.code == 401) {
                    SessionState.markRevoked()
                    throw SessionRevokedException()
                }
                if (method == "GET" && response.code == 500 && attempt == 0) {
                    Thread.sleep(300)
                    return@use
                }
                throw Exception("$route ${response.code}: $text")
            }
        }
        throw Exception("$route: sin respuesta")
    }

    private fun JSONObject.array(vararg keys: String): JSONArray {
        for (key in keys) {
            val value = optJSONArray(key)
            if (value != null) return value
        }
        return JSONArray()
    }

    private fun trackFrom(o: JSONObject) = Track(
        id = o.optString("id"),
        name = o.optString("name")
            .ifBlank { o.optString("title") }
            .ifBlank { o.optString("original_name") }
            .ifBlank { o.optString("id") },
        subtitle = o.optString("album_title")
            .ifBlank { o.optString("album_artist") }
            .ifBlank { o.optString("artist") },
        coverUrl = absoluteUrl(o.optString("cover_url"))
    )

    private fun JSONArray.tracks() = (0 until length()).map { i -> trackFrom(getJSONObject(i)) }

    private fun albumFrom(o: JSONObject) = Album(
        id = o.optString("id"),
        title = o.optString("title").ifBlank { o.optString("id") },
        artist = o.optString("artist"),
        coverUrl = absoluteUrl(o.optString("cover_url")),
        trackCount = o.optInt("track_count")
    )

    private fun playlistFrom(o: JSONObject, currentUserId: String) = Playlist(
        id = o.optString("id"),
        name = o.optString("name").ifBlank { o.optString("id") },
        description = o.optString("description"),
        trackCount = o.optInt("track_count"),
        ownerId = o.optString("owner_id"),
        ownerUsername = o.optString("owner_username"),
        isOwner = o.optString("owner_id") == currentUserId
    )

    fun login(username: String, password: String): String {
        val body = JSONObject()
            .put("username", username)
            .put("password", password)
            .put("device_label", "Android")
        val result = call("POST", "auth/login", body = body)
        val token = result.optString("access_token")
        if (token.isBlank()) throw Exception("Sin access_token: $result")
        return token
    }

    fun me(): User {
        val user = call("GET", "auth/me").getJSONObject("user")
        return User(
            id = user.optString("id"),
            username = user.optString("username"),
            role = user.optString("role")
        )
    }

    fun logout() {
        call("POST", "auth/logout")
    }

    fun hlsProfile(): String {
        val profile = call("GET", "me/preferences").optString("hls_profile")
        return if (profile.isBlank()) "balanced_mobile" else profile
    }

    fun search(query: String, limit: Int = 30, offset: Int = 0): List<Track> =
        call("GET", "search", listOf("q" to query, "limit" to limit.toString(), "offset" to offset.toString()))
            .array("tracks", "items").tracks()

    fun libraryTracks(
        query: String = "",
        albumId: String? = null,
        limit: Int = 50,
        offset: Int = 0
    ): List<Track> {
        val params = mutableListOf("q" to query, "limit" to limit.toString(), "offset" to offset.toString())
        if (albumId != null) params.add("album_id" to albumId)
        return call("GET", "library/tracks", params).array("items").tracks()
    }

    /** ID reservado por la web para las pistas que no pertenecen a ninguna colección. */
    fun unassignedTracks(limit: Int = 50, offset: Int = 0): List<Track> =
        libraryTracks(albumId = "fffffffffffffffe", limit = limit, offset = offset)

    fun libraryAlbums(
        query: String = "",
        limit: Int = 50,
        offset: Int = 0,
        recent: Boolean = false
    ): List<Album> {
        val params = mutableListOf("q" to query, "limit" to limit.toString(), "offset" to offset.toString())
        if (recent) params.add("order" to "recent")
        return call("GET", "library/albums", params).array("items").let { arr ->
            (0 until arr.length()).map { i -> albumFrom(arr.getJSONObject(i)) }
        }
    }

    fun albumTracks(albumId: String): List<Track> {
        val result = call("GET", "library/albums/$albumId")
        val cover = absoluteUrl(result.optJSONObject("album")?.optString("cover_url").orEmpty())
        return result.array("tracks", "items").tracks().map { track ->
            if (track.coverUrl.isBlank()) track.copy(coverUrl = cover) else track
        }
    }

    fun favorites(): List<Track> =
        call("GET", "me/favorites").array("items").tracks()

    fun addFavorite(trackId: String) {
        call("POST", "me/favorites", body = JSONObject().put("track_id", trackId))
    }

    fun removeFavorite(trackId: String) {
        call("DELETE", "me/favorites/$trackId")
    }

    /** Colecciones favoritas: se guarda el álbum, no sus pistas una a una. */
    fun favoriteAlbums(limit: Int = 100, offset: Int = 0): List<Album> =
        call("GET", "me/favorite-albums", listOf("limit" to limit.toString(), "offset" to offset.toString()))
            .array("items").let { arr ->
                (0 until arr.length()).map { i -> albumFrom(arr.getJSONObject(i)) }
            }

    fun addFavoriteAlbum(albumId: String) {
        call("POST", "me/favorite-albums", body = JSONObject().put("album_id", albumId))
    }

    fun removeFavoriteAlbum(albumId: String) {
        call("DELETE", "me/favorite-albums/$albumId")
    }

    fun recent(limit: Int = 50, offset: Int = 0): List<Track> =
        call("GET", "me/recent", listOf("limit" to limit.toString(), "offset" to offset.toString()))
            .array("items").tracks()

    /** Ranking de reproducciones de la cuenta, según recent_tracks.play_count. */
    fun charts(limit: Int = 10, offset: Int = 0): List<Track> =
        call("GET", "charts", listOf("limit" to limit.toString(), "offset" to offset.toString()))
            .array("items").tracks()

    fun registerRecent(trackId: String) {
        call("POST", "me/recent", body = JSONObject().put("track_id", trackId))
    }

    /** Carátula externa solo cuando la pista no tiene portada local; el servidor la cachea. */
    fun trackCover(trackId: String): String = try {
        absoluteUrl(call("GET", "tracks/$trackId/cover").optString("cover_url"))
    } catch (_: Exception) {
        ""
    }

    /** Letra sincronizada de LRCLIB con fallback a letra plana, cacheada en el servidor. */
    fun trackLyrics(trackId: String): Lyrics {
        val result = call("GET", "tracks/$trackId/lyrics")
        return Lyrics(
            synced = result.optString("synced").takeIf { it.isNotBlank() },
            plain = result.optString("plain").takeIf { it.isNotBlank() },
            source = result.optString("source").takeIf { it.isNotBlank() },
            title = result.optString("title"),
            artist = result.optString("artist")
        )
    }

    fun playlists(currentUserId: String): List<Playlist> =
        call("GET", "me/playlists").array("items").let { arr ->
            (0 until arr.length()).map { i -> playlistFrom(arr.getJSONObject(i), currentUserId) }
        }

    fun sharedPlaylists(currentUserId: String): List<Playlist> =
        call("GET", "me/playlists/shared").array("items").let { arr ->
            (0 until arr.length()).map { i -> playlistFrom(arr.getJSONObject(i), currentUserId) }
        }

    fun createPlaylist(name: String, description: String = ""): Playlist {
        val result = call("POST", "me/playlists", body = JSONObject()
            .put("name", name)
            .put("description", description))
        return Playlist(
            id = result.optString("id"),
            name = result.optString("name"),
            description = "",
            trackCount = 0,
            ownerId = "",
            ownerUsername = "",
            isOwner = true
        )
    }

    fun playlistTracks(playlistId: String): List<Track> =
        call("GET", "me/playlists/$playlistId").array("tracks", "items").tracks()

    fun addToPlaylist(playlistId: String, trackId: String) {
        call("POST", "me/playlists/$playlistId/tracks", body = JSONObject().put("track_id", trackId))
    }

    fun removeFromPlaylist(playlistId: String, trackId: String) {
        call("DELETE", "me/playlists/$playlistId/tracks/$trackId")
    }

    fun sharePlaylist(playlistId: String, username: String, shared: Boolean = true) {
        call("POST", "me/playlists/$playlistId/share", body = JSONObject()
            .put("username", username)
            .put("shared", shared))
    }

    fun sendSuggestion(subject: String, message: String) {
        call("POST", "me/suggestions", body = JSONObject()
            .put("subject", subject)
            .put("message", message))
    }
}

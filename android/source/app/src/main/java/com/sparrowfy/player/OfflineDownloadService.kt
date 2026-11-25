package com.sparrowfy.player

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.os.IBinder
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import java.io.ByteArrayOutputStream
import java.io.File
import java.net.HttpURLConnection
import java.net.URI
import java.net.URL
import java.util.ArrayDeque
import java.util.Locale

object OfflineDownloads {
    data class Progress(
        val trackId: String,
        val percent: Int,
        val message: String,
        val done: Boolean = false,
        val error: String? = null
    )

    data class Job(val userId: String, val track: SparrowApi.Track)

    private val _state = kotlinx.coroutines.flow.MutableStateFlow<Map<String, Progress>>(emptyMap())
    val state: kotlinx.coroutines.flow.StateFlow<Map<String, Progress>> = _state

    fun enqueue(context: Context, userId: String, tracks: List<SparrowApi.Track>) {
        if (tracks.isEmpty()) return
        val intent = Intent(context, OfflineDownloadService::class.java).apply {
            action = OfflineDownloadService.ACTION_DOWNLOAD
            putExtra(OfflineDownloadService.EXTRA_USER_ID, userId)
            putExtra(OfflineDownloadService.EXTRA_IDS, tracks.map { it.id }.toTypedArray())
            putExtra(OfflineDownloadService.EXTRA_NAMES, tracks.map { it.name }.toTypedArray())
            putExtra(OfflineDownloadService.EXTRA_SUBTITLES, tracks.map { it.subtitle }.toTypedArray())
            putExtra(OfflineDownloadService.EXTRA_COVERS, tracks.map { it.coverUrl }.toTypedArray())
        }
        context.startForegroundService(intent)
    }

    fun remove(context: Context, userId: String, trackId: String) {
        val intent = Intent(context, OfflineDownloadService::class.java).apply {
            action = OfflineDownloadService.ACTION_REMOVE
            putExtra(OfflineDownloadService.EXTRA_USER_ID, userId)
            putExtra(OfflineDownloadService.EXTRA_ID, trackId)
        }
        context.startForegroundService(intent)
    }

    fun clear(context: Context) {
        context.startForegroundService(Intent(context, OfflineDownloadService::class.java).apply {
            action = OfflineDownloadService.ACTION_CLEAR
        })
    }

    internal fun update(progress: Progress) {
        _state.value = _state.value + (progress.trackId to progress)
    }

    internal fun forget(trackId: String) {
        _state.value = _state.value - trackId
    }

    internal fun clearState() {
        _state.value = emptyMap()
    }
}

class OfflineDownloadService : Service() {

    companion object {
        const val ACTION_DOWNLOAD = "com.sparrowfy.player.OFFLINE_DOWNLOAD"
        const val ACTION_REMOVE = "com.sparrowfy.player.OFFLINE_REMOVE"
        const val ACTION_CLEAR = "com.sparrowfy.player.OFFLINE_CLEAR"

        const val EXTRA_USER_ID = "user_id"
        const val EXTRA_ID = "track_id"
        const val EXTRA_IDS = "track_ids"
        const val EXTRA_NAMES = "track_names"
        const val EXTRA_SUBTITLES = "track_subtitles"
        const val EXTRA_COVERS = "track_covers"

        private const val CHANNEL = "sparrowfy_offline"
        private const val NOTIFICATION_ID = 2
        private val BANDWIDTH = Regex("""BANDWIDTH=(\d+)""")
        private const val MAX_PLAYLIST_BYTES = 1024 * 1024
        private const val MAX_SEGMENT_BYTES = 5 * 1024 * 1024
        private const val MAX_SEGMENTS = 10_000
        private const val MAX_AUTO_BITRATE = 160_000
    }

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val pending = ArrayDeque<OfflineDownloads.Job>()
    private var drainJob: Job? = null
    private var activeName = ""

    override fun onCreate() {
        super.onCreate()
        TokenStore.init(applicationContext)
        Settings.init(applicationContext)
        OfflineStore.init(applicationContext)
        createChannel()
        startForeground(NOTIFICATION_ID, notification())
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_DOWNLOAD -> {
                val userId = intent.getStringExtra(EXTRA_USER_ID).orEmpty()
                val ids = intent.getStringArrayExtra(EXTRA_IDS).orEmpty()
                val names = intent.getStringArrayExtra(EXTRA_NAMES).orEmpty()
                val subtitles = intent.getStringArrayExtra(EXTRA_SUBTITLES).orEmpty()
                val covers = intent.getStringArrayExtra(EXTRA_COVERS).orEmpty()
                ids.indices.forEach { i ->
                    pending.add(
                        OfflineDownloads.Job(
                            userId = userId,
                            track = SparrowApi.Track(
                                id = ids[i],
                                name = names.getOrElse(i) { ids[i] },
                                subtitle = subtitles.getOrElse(i) { "" },
                                coverUrl = covers.getOrElse(i) { "" }
                            )
                        )
                    )
                }
                drain()
            }

            ACTION_REMOVE -> {
                val userId = intent.getStringExtra(EXTRA_USER_ID).orEmpty()
                val id = intent.getStringExtra(EXTRA_ID).orEmpty()
                OfflineStore.remove(userId, id)
                OfflineDownloads.forget(id)
                finishIfIdle()
            }

            ACTION_CLEAR -> {
                OfflineStore.clear()
                OfflineDownloads.clearState()
                pending.clear()
                finishIfIdle()
            }
        }
        return START_NOT_STICKY
    }

    private fun drain() {
        if (drainJob?.isActive == true) return
        drainJob = scope.launch {
            while (isActive && pending.isNotEmpty()) {
                val job = pending.removeFirst()
                activeName = job.track.name
                updateNotification(0, job.track.name)
                try {
                    download(job)
                } catch (e: SessionRevokedException) {
                    SessionState.markRevoked()
                    fail(job.track, getString(R.string.session_expired))
                } catch (e: Exception) {
                    fail(job.track, e.message ?: getString(R.string.offline_download_failed_short))
                }
            }
            activeName = ""
            finishIfIdle()
        }
    }

    private suspend fun download(job: OfflineDownloads.Job) {
        val track = job.track
        OfflineDownloads.update(
            OfflineDownloads.Progress(track.id, 0, getString(R.string.offline_downloading))
        )
        OfflineStore.ensureActiveSession()
        if (OfflineStore.has(job.userId, track.id)) {
            val existing = OfflineStore.get(job.userId, track.id)
            OfflineDownloads.update(
                OfflineDownloads.Progress(
                    track.id,
                    100,
                    getString(R.string.offline_already_downloaded),
                    done = true
                )
            )
            activeName = ""
            return
        }

        val masterUrl = Settings.streamUrl(track.id)
        val master = String(fetch(masterUrl, MAX_PLAYLIST_BYTES), Charsets.UTF_8)
        if (!master.lineSequence().firstOrNull().orEmpty().trim().startsWith("#EXTM3U")) {
            throw IllegalStateException(getString(R.string.offline_invalid_playlist))
        }

        val variant = selectVariant(master, masterUrl)
        val lines = variant.body.lineSequence().map { it.trim() }.filter { it.isNotEmpty() }.toList()
        if (lines.first().startsWith("#EXT-X-KEY") || lines.any { it.startsWith("#EXT-X-KEY") } ||
            lines.any { it.startsWith("#EXT-X-MAP") }
        ) {
            throw IllegalStateException(getString(R.string.offline_encrypted))
        }
        if (lines.none { it == "#EXT-X-ENDLIST" }) {
            throw IllegalStateException(getString(R.string.offline_incomplete))
        }

        val segmentLines = lines.filterNot { it.startsWith("#") }
        if (segmentLines.isEmpty() || segmentLines.size > MAX_SEGMENTS) {
            throw IllegalStateException(getString(R.string.offline_invalid_playlist))
        }

        val used = OfflineStore.usedBytes(job.userId, excludeId = track.id)
        val quota = Settings.offlineQuotaBytes
        val temp = OfflineStore.prepare(job.userId, track.id)
        var totalBytes = 0L
        try {
            val playlist = mutableListOf<String>()
            var index = 0
            var endListWritten = false
            for (raw in lines) {
                OfflineStore.ensureActiveSession()
                if (raw.startsWith("#")) {
                    if (raw.startsWith("#EXT-X-BYTERANGE")) continue
                    playlist.add(raw)
                    if (raw == "#EXT-X-ENDLIST") endListWritten = true
                    continue
                }
                val segmentUrl = resolve(variant.url, raw)
                val data = fetch(segmentUrl, MAX_SEGMENT_BYTES)
                if (used + totalBytes + data.size > quota) {
                    throw IllegalStateException(getString(R.string.offline_quota_exceeded))
                }
                val name = String.format(Locale.US, "seg_%06d.%s", index, extensionOf(segmentUrl))
                File(temp, name).writeBytes(data)
                totalBytes += data.size
                playlist.add(name)
                index++
                val percent = (index * 100 / segmentLines.size).coerceAtMost(99)
                val message = getString(R.string.offline_download_segment, index, segmentLines.size)
                OfflineDownloads.update(OfflineDownloads.Progress(track.id, percent, message))
                updateNotification(percent, track.name)
            }
            if (!endListWritten) playlist.add("#EXT-X-ENDLIST")
            File(temp, "playlist.m3u8").writeText(playlist.joinToString("\n") + "\n", Charsets.UTF_8)

            OfflineStore.commit(
                job.userId,
                temp,
                OfflineStore.Track(
                    id = track.id,
                    name = track.name,
                    artist = track.subtitle,
                    coverUrl = track.coverUrl,
                    bytes = totalBytes,
                    segments = index,
                    quality = variant.quality,
                    createdAt = System.currentTimeMillis()
                )
            )
            OfflineDownloads.update(
                OfflineDownloads.Progress(
                    track.id,
                    100,
                    getString(R.string.offline_download_done),
                    done = true
                )
            )
            updateNotification(100, track.name, getString(R.string.offline_download_done))
        } catch (e: Exception) {
            OfflineStore.discard(temp)
            throw e
        }
    }

    private data class Variant(val url: String, val body: String, val quality: String)

    private fun selectVariant(master: String, masterUrl: String): Variant {
        val lines = master.lines()
        val candidates = mutableListOf<Pair<Int, String>>()
        for (i in lines.indices) {
            val tag = lines[i].trim()
            if (!tag.startsWith("#EXT-X-STREAM-INF")) continue
            val bandwidth = BANDWIDTH.find(tag)?.groupValues?.get(1)?.toIntOrNull() ?: 0
            val uri = lines.drop(i + 1).firstOrNull { it.isNotBlank() && !it.trim().startsWith("#") }
                ?.trim() ?: continue
            candidates.add(bandwidth to resolve(masterUrl, uri))
        }
        if (candidates.isEmpty()) return Variant(masterUrl, master, getString(R.string.offline_quality_original))

        val lowBitrate = candidates.filter { it.first <= MAX_AUTO_BITRATE }
        val chosen = (lowBitrate.maxByOrNull { it.first } ?: candidates.minByOrNull { it.first })!!
        val body = String(fetch(chosen.second, MAX_PLAYLIST_BYTES), Charsets.UTF_8)
        val quality = if (chosen.first > 0) "~${chosen.first / 1000} kbps"
        else getString(R.string.offline_quality_original)
        return Variant(chosen.second, body, quality)
    }

    private fun fetch(url: String, maxBytes: Int): ByteArray {
        val connection = URL(url).openConnection() as HttpURLConnection
        connection.instanceFollowRedirects = true
        connection.connectTimeout = 10_000
        connection.readTimeout = 30_000
        connection.setRequestProperty("Authorization", "Bearer ${TokenStore.token.orEmpty()}")
        connection.setRequestProperty("Accept", "*/*")
        try {
            val code = connection.responseCode
            if (code == 401) {
                SessionState.markRevoked()
                throw SessionRevokedException()
            }
            if (code !in 200..299) throw IllegalStateException("HTTP $code")
            val stream = connection.inputStream
            val output = ByteArrayOutputStream()
            val buffer = ByteArray(16 * 1024)
            var total = 0
            stream.use { input ->
                while (true) {
                    val read = input.read(buffer)
                    if (read < 0) break
                    total += read
                    if (total > maxBytes) throw IllegalStateException(getString(R.string.offline_file_too_large))
                    output.write(buffer, 0, read)
                }
            }
            return output.toByteArray()
        } finally {
            connection.disconnect()
        }
    }

    private fun resolve(base: String, reference: String): String =
        URI(base).resolve(URI(reference)).toString()

    private fun extensionOf(url: String): String {
        val path = try { URI(url).path } catch (_: Exception) { "" }
        val ext = path.substringAfterLast('.', "")
        return if (ext in setOf("ts", "aac", "m4s", "mp4")) ext else "ts"
    }

    private fun fail(track: SparrowApi.Track, message: String) {
        OfflineDownloads.update(
            OfflineDownloads.Progress(track.id, 0, message, error = message)
        )
        updateNotification(0, track.name, message)
    }

    private fun createChannel() {
        val manager = getSystemService(NotificationManager::class.java)
        if (manager.getNotificationChannel(CHANNEL) == null) {
            manager.createNotificationChannel(
                NotificationChannel(CHANNEL, "Descargas sin conexión", NotificationManager.IMPORTANCE_LOW)
            )
        }
    }

    private fun notification(
        percent: Int = 0,
        title: String = "",
        text: String = getString(R.string.offline_downloading)
    ): android.app.Notification {
        val open = PendingIntent.getActivity(
            this,
            0,
            Intent(this, MainActivity::class.java),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )
        return NotificationCompat.Builder(this, CHANNEL)
            .setSmallIcon(R.drawable.ic_launcher)
            .setContentTitle(title.ifBlank { getString(R.string.offline_section) })
            .setContentText(text)
            .setContentIntent(open)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setProgress(100, percent, percent == 0 && text == getString(R.string.offline_downloading))
            .build()
    }

    private fun updateNotification(
        percent: Int,
        title: String,
        text: String = getString(R.string.offline_downloading)
    ) {
        NotificationManagerCompat.from(this).notify(NOTIFICATION_ID, notification(percent, title, text))
    }

    private fun finishIfIdle() {
        if (pending.isNotEmpty() || drainJob?.isActive == true) return
        stopForeground(android.app.Service.STOP_FOREGROUND_REMOVE)
        stopSelf()
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        scope.cancel()
        super.onDestroy()
    }
}

package com.sparrowfy.player

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Intent
import android.net.Uri
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.media3.common.C
import androidx.media3.common.MediaItem
import androidx.media3.common.MediaMetadata
import androidx.media3.common.PlaybackException
import androidx.media3.common.Player
import androidx.media3.datasource.DefaultDataSource
import androidx.media3.datasource.HttpDataSource
import androidx.media3.exoplayer.DefaultLoadControl
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.exoplayer.LoadControl
import androidx.media3.exoplayer.hls.HlsMediaSource
import androidx.media3.session.MediaSession
import androidx.media3.session.MediaSessionService
import androidx.media3.session.MediaStyleNotificationHelper
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

class PlaybackService : MediaSessionService() {

    companion object {
        const val ACTION_PLAY = "com.sparrowfy.player.PLAY"
        const val ACTION_PLAY_QUEUE = "com.sparrowfy.player.PLAY_QUEUE"
        const val ACTION_ADD = "com.sparrowfy.player.ADD"
        const val ACTION_REMOVE = "com.sparrowfy.player.REMOVE"
        const val ACTION_MOVE = "com.sparrowfy.player.MOVE"
        const val ACTION_TOGGLE_SHUFFLE = "com.sparrowfy.player.SHUFFLE"
        const val ACTION_CYCLE_REPEAT = "com.sparrowfy.player.REPEAT"
        const val ACTION_SEEK = "com.sparrowfy.player.SEEK"
        const val ACTION_PAUSE_RESUME = "com.sparrowfy.player.TOGGLE"
        const val ACTION_NEXT = "com.sparrowfy.player.NEXT"
        const val ACTION_PREVIOUS = "com.sparrowfy.player.PREVIOUS"
        const val ACTION_STOP = "com.sparrowfy.player.STOP"

        const val EXTRA_TRACK_ID = "track_id"
        const val EXTRA_TITLE = "title"
        const val EXTRA_SUBTITLE = "subtitle"
        const val EXTRA_IDS = "ids"
        const val EXTRA_TITLES = "titles"
        const val EXTRA_SUBTITLES = "subtitles"
        const val EXTRA_COVERS = "covers"
        const val EXTRA_START = "start"
        const val EXTRA_INDEX = "index"
        const val EXTRA_FROM = "from"
        const val EXTRA_TO = "to"
        const val EXTRA_MS = "ms"

        private fun streamUrl() = Settings.server.trimEnd('/') + "/api/v1/stream.php"
        private const val CHANNEL = "sparrowfy_playback"
        private const val NOTIFICATION_ID = 1
    }

    private lateinit var player: ExoPlayer
    private var session: MediaSession? = null
    private val queue = mutableListOf<SparrowApi.Track>()
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
    private var lastReportedId: String? = null
    private var progressJob: Job? = null
    private var suppressRecent = false

    override fun onCreate() {
        super.onCreate()
        TokenStore.init(applicationContext)
        Settings.init(applicationContext)
        OfflineStore.init(applicationContext)
        val profile = try {
            SparrowApi.hlsProfile()
        } catch (_: Exception) {
            "balanced_mobile"
        }
        player = ExoPlayer.Builder(this)
            // DefaultDataSource lee archivos locales; el factory base envía Bearer en red.
            .setMediaSourceFactory(
                HlsMediaSource.Factory(DefaultDataSource.Factory(this, BearerDataSourceFactory))
            )
            .setLoadControl(loadControl(profile))
            // Mantiene CPU y Wi-Fi despiertos con la pantalla apagada (clave frente al PWA).
            .setWakeMode(C.WAKE_MODE_NETWORK)
            .build()
        player.addListener(playerListener)
        progressJob = scope.launch {
            while (isActive) {
                PlayerState.positionMs.value = player.currentPosition
                delay(500)
            }
        }

        val openApp = PendingIntent.getActivity(
            this, 0, Intent(this, MainActivity::class.java),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )
        session = MediaSession.Builder(this, player).setSessionActivity(openApp).build()
        addSession(session!!)
    }

    private val playerListener = object : Player.Listener {
        override fun onPlayerError(error: PlaybackException) {
            var cause: Throwable? = error
            while (cause != null) {
                if (cause is HttpDataSource.InvalidResponseCodeException && cause.responseCode == 401) {
                    SessionState.markRevoked()
                    return
                }
                cause = cause.cause
            }
        }

        override fun onIsPlayingChanged(isPlaying: Boolean) {
            PlayerState.isPlaying.value = isPlaying
            refreshNotification()
            if (isPlaying) reportRecent()
        }

        override fun onMediaItemTransition(mediaItem: MediaItem?, reason: Int) {
            PlayerState.currentIndex.value = player.currentMediaItemIndex
            refreshNotification()
            reportRecent()
        }

        override fun onPlaybackStateChanged(playbackState: Int) {
            if (playbackState == Player.STATE_READY) {
                PlayerState.durationMs.value = player.duration
            }
        }

        override fun onPositionDiscontinuity(
            oldPosition: Player.PositionInfo,
            newPosition: Player.PositionInfo,
            reason: Int
        ) {
            PlayerState.positionMs.value = player.currentPosition
        }

        override fun onShuffleModeEnabledChanged(shuffleModeEnabled: Boolean) {
            PlayerState.shuffle.value = shuffleModeEnabled
        }

        override fun onRepeatModeChanged(repeatMode: Int) {
            PlayerState.repeatMode.value = repeatMode
        }
    }

    private fun reportRecent() {
        if (suppressRecent) return
        val id = player.currentMediaItem?.mediaId ?: return
        if (OfflineStore.playlistUriForTrack(id) != null) return
        if (id == lastReportedId) return
        lastReportedId = id
        scope.launch(Dispatchers.IO) {
            try {
                SparrowApi.registerRecent(id)
            } catch (_: Exception) {
            }
        }
    }

    /** Perfil ABR de la cuenta: ajusta búfer, no fija bitrate. */
    private fun loadControl(profile: String): LoadControl {
        val ms = when (profile) {
            "continuity" -> intArrayOf(24000, 45000, 2000, 5000)
            "audio_quality" -> intArrayOf(24000, 45000, 1500, 4000)
            else -> intArrayOf(20000, 36000, 2000, 4000)
        }
        return DefaultLoadControl.Builder()
            .setBufferDurationsMs(ms[0], ms[1], ms[2], ms[3])
            .build()
    }

    private fun buildItems(list: List<SparrowApi.Track>) = list.map { track ->
        val metadata = MediaMetadata.Builder()
            .setTitle(track.name)
            .setArtist(track.subtitle)
        if (track.coverUrl.isNotBlank()) metadata.setArtworkUri(Uri.parse(track.coverUrl))
        MediaItem.Builder()
            .setUri(
                OfflineStore.playlistUriForTrack(track.id)
                    ?: "${streamUrl()}?track_id=${track.id}"
            )
            .setMediaId(track.id)
            .setMediaMetadata(metadata.build())
            .build()
    }

    private fun tracksFrom(intent: Intent): List<SparrowApi.Track> {
        val ids = intent.getStringArrayExtra(EXTRA_IDS) ?: return emptyList()
        val titles = intent.getStringArrayExtra(EXTRA_TITLES) ?: Array(ids.size) { "" }
        val subtitles = intent.getStringArrayExtra(EXTRA_SUBTITLES) ?: Array(ids.size) { "" }
        val covers = intent.getStringArrayExtra(EXTRA_COVERS) ?: Array(ids.size) { "" }
        return ids.indices.map { i ->
            SparrowApi.Track(
                id = ids[i],
                name = titles.getOrElse(i) { "" },
                subtitle = subtitles.getOrElse(i) { "" },
                coverUrl = covers.getOrElse(i) { "" }
            )
        }
    }

    private fun publishQueue() {
        PlayerState.queue.value = queue.toList()
        PlayerState.currentIndex.value = player.currentMediaItemIndex
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        createChannel()
        if (intent?.action == ACTION_STOP) {
            stopPlaybackAndNotification()
            return START_NOT_STICKY
        }
        startForeground(NOTIFICATION_ID, notification())

        when (intent?.action) {
            ACTION_PLAY_QUEUE -> {
                val tracks = tracksFrom(intent)
                if (tracks.isNotEmpty()) {
                    queue.clear()
                    queue.addAll(tracks)
                    val start = intent.getIntExtra(EXTRA_START, 0).coerceIn(0, tracks.lastIndex)
                    player.setMediaItems(buildItems(tracks), start, 0)
                    player.prepare()
                    player.play()
                    publishQueue()
                }
            }

            ACTION_PLAY -> {
                val id = intent.getStringExtra(EXTRA_TRACK_ID)
                if (!id.isNullOrBlank()) {
                    val track = SparrowApi.Track(
                        id = id,
                        name = intent.getStringExtra(EXTRA_TITLE)?.takeIf { it.isNotBlank() } ?: id,
                        subtitle = intent.getStringExtra(EXTRA_SUBTITLE).orEmpty(),
                        coverUrl = SparrowApi.absoluteUrl(
                            intent.getStringArrayExtra(EXTRA_COVERS)?.firstOrNull().orEmpty()
                        )
                    )
                    queue.clear()
                    queue.add(track)
                    player.setMediaItems(buildItems(queue))
                    player.prepare()
                    player.play()
                    publishQueue()
                }
            }

            ACTION_ADD -> {
                val tracks = tracksFrom(intent)
                if (tracks.isNotEmpty()) {
                    queue.addAll(tracks)
                    player.addMediaItems(buildItems(tracks))
                    publishQueue()
                }
            }

            ACTION_REMOVE -> {
                val index = intent.getIntExtra(EXTRA_INDEX, -1)
                if (index in queue.indices) {
                    queue.removeAt(index)
                    player.removeMediaItem(index)
                    publishQueue()
                }
            }

            ACTION_MOVE -> {
                val from = intent.getIntExtra(EXTRA_FROM, -1)
                val to = intent.getIntExtra(EXTRA_TO, -1)
                if (from in queue.indices && to in queue.indices) {
                    queue.add(to, queue.removeAt(from))
                    player.moveMediaItem(from, to)
                    publishQueue()
                }
            }

            ACTION_TOGGLE_SHUFFLE -> player.shuffleModeEnabled = !player.shuffleModeEnabled

            ACTION_CYCLE_REPEAT -> {
                player.repeatMode = when (player.repeatMode) {
                    Player.REPEAT_MODE_ALL -> Player.REPEAT_MODE_ONE
                    Player.REPEAT_MODE_ONE -> Player.REPEAT_MODE_OFF
                    else -> Player.REPEAT_MODE_ALL
                }
            }

            ACTION_SEEK -> player.seekTo(intent.getLongExtra(EXTRA_MS, 0L).coerceAtLeast(0L))

            ACTION_PAUSE_RESUME -> {
                if (player.isPlaying) player.pause() else player.play()
            }

            ACTION_NEXT -> player.seekToNext()
            ACTION_PREVIOUS -> player.seekToPrevious()
        }
        try {
            NotificationManagerCompat.from(this).notify(NOTIFICATION_ID, notification())
        } catch (_: SecurityException) {
        }
        return START_STICKY
    }

    private fun createChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val manager = getSystemService(NotificationManager::class.java)
            if (manager.getNotificationChannel(CHANNEL) == null) {
                manager.createNotificationChannel(
                    NotificationChannel(CHANNEL, "Reproducción", NotificationManager.IMPORTANCE_LOW)
                )
            }
        }
    }

    private fun refreshNotification() {
        if (session == null) return
        NotificationManagerCompat.from(this).notify(NOTIFICATION_ID, notification())
    }

    private fun serviceIntent(action: String, requestCode: Int): PendingIntent =
        PendingIntent.getService(
            this,
            requestCode,
            Intent(this, PlaybackService::class.java).setAction(action),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )

    private fun openAppIntent(): PendingIntent = PendingIntent.getActivity(
        this,
        0,
        Intent(this, MainActivity::class.java),
        PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
    )

    private fun notification(): android.app.Notification {
        val item = player.currentMediaItem?.mediaMetadata
        val stopIntent = serviceIntent(ACTION_STOP, 5)
        val builder = NotificationCompat.Builder(this, CHANNEL)
            .setSmallIcon(R.drawable.ic_launcher)
            .setContentTitle(item?.title?.toString()?.takeIf { it.isNotBlank() } ?: "Sparrow-Fy")
            .setContentText(item?.artist?.toString()?.takeIf { it.isNotBlank() } ?: "Reproduciendo")
            .setContentIntent(openAppIntent())
            .setDeleteIntent(stopIntent)
            .setOnlyAlertOnce(true)
            .setOngoing(false)
            .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .addAction(
                android.R.drawable.ic_media_previous,
                getString(R.string.previous),
                serviceIntent(ACTION_PREVIOUS, 1)
            )
            .addAction(
                if (player.isPlaying) android.R.drawable.ic_media_pause else android.R.drawable.ic_media_play,
                getString(if (player.isPlaying) R.string.pause else R.string.play),
                serviceIntent(ACTION_PAUSE_RESUME, 2)
            )
            .addAction(
                android.R.drawable.ic_media_next,
                getString(R.string.next),
                serviceIntent(ACTION_NEXT, 3)
            )
            .addAction(
                android.R.drawable.ic_menu_close_clear_cancel,
                getString(R.string.close),
                serviceIntent(ACTION_STOP, 4)
            )

        session?.let { mediaSession ->
            builder.setStyle(
                MediaStyleNotificationHelper.MediaStyle(mediaSession)
                    .setShowActionsInCompactView(0, 1, 2)
            )
        }
        return builder.build()
    }

    private fun stopPlaybackAndNotification() {
        suppressRecent = true
        player.stop()
        player.clearMediaItems()
        queue.clear()
        publishQueue()
        PlayerState.reset()
        stopForeground(android.app.Service.STOP_FOREGROUND_REMOVE)
        NotificationManagerCompat.from(this).cancel(NOTIFICATION_ID)
        stopSelf()
    }

    override fun onGetSession(controllerInfo: MediaSession.ControllerInfo): MediaSession? = session

    override fun onDestroy() {
        suppressRecent = true
        progressJob?.cancel()
        scope.cancel()
        session?.release()
        player.release()
        stopForeground(android.app.Service.STOP_FOREGROUND_REMOVE)
        NotificationManagerCompat.from(this).cancel(NOTIFICATION_ID)
        super.onDestroy()
    }

    /** Al retirar la tarea desde «recientes», la reproducción debe terminar también. */
    override fun onTaskRemoved(rootIntent: Intent?) {
        stopPlaybackAndNotification()
        super.onTaskRemoved(rootIntent)
    }
}

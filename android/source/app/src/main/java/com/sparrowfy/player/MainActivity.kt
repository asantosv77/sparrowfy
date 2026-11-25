package com.sparrowfy.player

import android.app.Activity
import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.Image
import androidx.compose.foundation.MarqueeAnimationMode
import androidx.compose.foundation.background
import androidx.compose.foundation.basicMarquee
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectDragGesturesAfterLongPress
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.WindowInsetsSides
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.navigationBars
import androidx.compose.foundation.layout.only
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Slider
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TextField
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.res.stringArrayResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.CustomAccessibilityAction
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.customActions
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.net.HttpURLConnection
import java.net.URL
import java.util.Locale

private enum class MainTab { HOME, LIBRARY, OFFLINE, COLLECTIONS, FAVORITES, RECENT, PLAYLISTS }

/** Acciones de descarga disponibles en los menús contextuales de listas y colecciones. */
private object DownloadActions {
    var downloadTrack: ((SparrowApi.Track) -> Unit)? = null
    var downloadAlbum: ((SparrowApi.Album) -> Unit)? = null
    var downloadPlaylist: ((SparrowApi.Playlist) -> Unit)? = null
}

private data class LyricLine(val timeMs: Long, val text: String)

private fun parseLrc(lrc: String): List<LyricLine> {
    val tag = Regex("""\[(\d{1,3}):(\d{2})(?:[.:](\d{1,3}))?\]""")
    return lrc.lineSequence().flatMap { line ->
        tag.findAll(line).map { match ->
            val minutes = match.groupValues[1].toLong()
            val seconds = match.groupValues[2].toLong()
            val fraction = match.groupValues[3]
            val millis = when {
                fraction.length >= 3 -> fraction.take(3).toLong()
                fraction.length == 2 -> fraction.toLong() * 10
                fraction.length == 1 -> fraction.toLong() * 100
                else -> 0
            }
            LyricLine(
                minutes * 60_000 + seconds * 1_000 + millis,
                line.substring(match.range.last + 1).trim()
            )
        }
    }.filter { it.text.isNotBlank() }.sortedBy { it.timeMs }.toList()
}

private fun sendAction(context: Context, action: String, configure: Intent.() -> Unit = {}) {
    val intent = Intent(context, PlaybackService::class.java).apply {
        this.action = action
        configure()
    }
    context.startForegroundService(intent)
}

private fun playTracks(context: Context, tracks: List<SparrowApi.Track>, start: Int = 0) {
    if (tracks.isEmpty()) return
    sendAction(context, PlaybackService.ACTION_PLAY_QUEUE) {
        putExtra(PlaybackService.EXTRA_IDS, tracks.map { it.id }.toTypedArray())
        putExtra(PlaybackService.EXTRA_TITLES, tracks.map { it.name }.toTypedArray())
        putExtra(PlaybackService.EXTRA_SUBTITLES, tracks.map { it.subtitle }.toTypedArray())
        putExtra(PlaybackService.EXTRA_COVERS, tracks.map { it.coverUrl }.toTypedArray())
        putExtra(PlaybackService.EXTRA_START, start)
    }
}

private fun addTracks(context: Context, tracks: List<SparrowApi.Track>) {
    if (tracks.isEmpty()) return
    sendAction(context, PlaybackService.ACTION_ADD) {
        putExtra(PlaybackService.EXTRA_IDS, tracks.map { it.id }.toTypedArray())
        putExtra(PlaybackService.EXTRA_TITLES, tracks.map { it.name }.toTypedArray())
        putExtra(PlaybackService.EXTRA_SUBTITLES, tracks.map { it.subtitle }.toTypedArray())
        putExtra(PlaybackService.EXTRA_COVERS, tracks.map { it.coverUrl }.toTypedArray())
    }
}

private fun formatTime(ms: Long): String {
    val total = (ms / 1000).coerceAtLeast(0)
    return "%02d:%02d".format(total / 60, total % 60)
}

private fun mib(bytes: Long): String =
    String.format(Locale.US, "%.1f MiB", bytes / (1024.0 * 1024.0))

/** Altura realmente usable: descuenta la barra táctil cuando el usuario no usa gestos. */
@Composable
private fun availableHeightDp(): Int {
    val density = LocalDensity.current
    val navBarDp = WindowInsets.navigationBars.getBottom(density) / density.density
    return (LocalConfiguration.current.screenHeightDp - navBarDp).toInt().coerceAtLeast(0)
}

@Composable
private fun shortScreen(): Boolean = availableHeightDp() < 640

/** Pantallas compactas: reduce iconos, portadas y textos en lugar de recortarlos. */
@Composable
private fun compactScreen(): Boolean = LocalConfiguration.current.screenWidthDp < 360 || shortScreen()

/** Respeta la barra táctil de Android en ventanas que no usan Scaffold. */
@Composable
private fun Modifier.systemBottomPadding(): Modifier = this.windowInsetsPadding(
    WindowInsets.navigationBars.only(WindowInsetsSides.Bottom)
)

class MainActivity : ComponentActivity() {

    override fun attachBaseContext(newBase: Context) {
        Settings.init(newBase)
        val locale = Locale(Settings.language)
        Locale.setDefault(locale)
        val config = newBase.resources.configuration
        config.setLocale(locale)
        super.attachBaseContext(newBase.createConfigurationContext(config))
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        Settings.init(applicationContext)
        TokenStore.init(applicationContext)
        setContent {
            var theme by remember { mutableStateOf(Settings.theme) }
            SparrowTheme(themeIndex = theme) {
                Surface(Modifier.fillMaxSize()) {
                    AppScreen(onThemeChanged = { theme = it })
                }
            }
        }
    }
}

@Composable
fun AppScreen(onThemeChanged: (Int) -> Unit) {
    val context = LocalContext.current
    val revoked by SessionState.revoked.collectAsState()
    var checked by remember { mutableStateOf(false) }
    var currentUser by remember { mutableStateOf<SparrowApi.User?>(null) }
    var sessionNotice by remember { mutableStateOf("") }

    LaunchedEffect(revoked) {
        if (!revoked) return@LaunchedEffect
        try {
            sendAction(context, PlaybackService.ACTION_STOP)
        } catch (_: Exception) {
            context.stopService(Intent(context, PlaybackService::class.java))
        }
        context.stopService(Intent(context, OfflineDownloadService::class.java))
        OfflineStore.clear()
        TokenStore.clear(context)
        OfflineDownloads.clearState()
        currentUser = null
        sessionNotice = context.getString(R.string.session_expired)
        checked = true
        SessionState.clear()
    }

    LaunchedEffect(Unit) {
        OfflineStore.init(context)
        OfflineStore.ensureActiveSession()
        val token = TokenStore.token
        if (token.isNullOrBlank()) {
            checked = true
            return@LaunchedEffect
        }
        val cached = TokenStore.cachedUser
        if (cached != null) {
            // Abre al instante con la última identidad validada, incluso sin red.
            currentUser = cached
            checked = true
        }
        try {
            val me = withContext(Dispatchers.IO) { SparrowApi.me() }
            TokenStore.cachedUser = me
            currentUser = me
        } catch (e: Exception) {
            if (e is SessionRevokedException) {
                SessionState.markRevoked()
            } else if (cached == null) {
                TokenStore.clear(context)
            }
        }
        checked = true
    }

    if (!checked) {
        Box(Modifier.fillMaxSize().systemBottomPadding(), contentAlignment = Alignment.Center) {
            Text(stringResource(R.string.restoring_session))
        }
        return
    }

    val user = currentUser
    if (user == null) {
        LoginScreen(initialStatus = sessionNotice, onLoggedIn = {
            sessionNotice = ""
            currentUser = it
        })
    } else {
        MainShell(
            user = user,
            onThemeChanged = onThemeChanged,
            onLoggedOut = { currentUser = null }
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun LoginScreen(
    initialStatus: String = "",
    onLoggedIn: (SparrowApi.User) -> Unit
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()

    var server by remember { mutableStateOf(Settings.server) }
    var language by remember { mutableStateOf(Settings.language) }
    var languageExpanded by remember { mutableStateOf(false) }
    var user by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var status by remember { mutableStateOf(initialStatus) }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .systemBottomPadding()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp)
    ) {
        Text("Sparrow-Fy", style = MaterialTheme.typography.headlineMedium)

        OutlinedTextField(
            value = server,
            onValueChange = { server = it },
            label = { Text(stringResource(R.string.server)) },
            supportingText = { Text(stringResource(R.string.server_hint)) },
            singleLine = true,
            modifier = Modifier.fillMaxWidth()
        )

        ExposedDropdownMenuBox(
            expanded = languageExpanded,
            onExpandedChange = { languageExpanded = !languageExpanded }
        ) {
            TextField(
                value = if (language == "en") stringResource(R.string.english) else stringResource(R.string.spanish),
                onValueChange = {},
                readOnly = true,
                label = { Text(stringResource(R.string.language)) },
                trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = languageExpanded) },
                colors = ExposedDropdownMenuDefaults.textFieldColors(),
                modifier = Modifier.menuAnchor().fillMaxWidth()
            )
            ExposedDropdownMenu(
                expanded = languageExpanded,
                onDismissRequest = { languageExpanded = false }
            ) {
                listOf("es" to R.string.spanish, "en" to R.string.english).forEach { (code, label) ->
                    DropdownMenuItem(
                        text = { Text(stringResource(label)) },
                        onClick = {
                            Settings.language = code
                            languageExpanded = false
                            (context as? Activity)?.recreate()
                        }
                    )
                }
            }
        }

        OutlinedTextField(
            value = user,
            onValueChange = { user = it },
            label = { Text(stringResource(R.string.user)) },
            singleLine = true,
            modifier = Modifier.fillMaxWidth()
        )
        OutlinedTextField(
            value = password,
            onValueChange = { password = it },
            label = { Text(stringResource(R.string.password)) },
            singleLine = true,
            visualTransformation = PasswordVisualTransformation(),
            modifier = Modifier.fillMaxWidth()
        )
        Button(onClick = {
            val normalized = Settings.normalize(server)
            if (!Settings.isValidServer(normalized)) {
                status = context.getString(R.string.invalid_server)
            } else if (!Settings.isHttps(normalized)) {
                status = context.getString(R.string.https_required)
            } else {
                scope.launch {
                    status = context.getString(R.string.logging_in)
                    try {
                        if (Settings.server != normalized) {
                            Settings.server = normalized
                            TokenStore.clear()
                        }
                        val token = withContext(Dispatchers.IO) { SparrowApi.login(user, password) }
                        TokenStore.init(context)
                        TokenStore.token = token
                        TokenStore.markActive()
                        val me = withContext(Dispatchers.IO) { SparrowApi.me() }
                        TokenStore.cachedUser = me
                        OfflineStore.init(context)
                        OfflineStore.clear()
                        SessionState.clear()
                        onLoggedIn(me)
                    } catch (e: Exception) {
                        if (e is SessionRevokedException) SessionState.markRevoked()
                        status = context.getString(R.string.error_generic, e.message ?: "Error")
                    }
                }
            }
        }) { Text(stringResource(R.string.login)) }

        Text(status, style = MaterialTheme.typography.bodyMedium)
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun MainShell(
    user: SparrowApi.User,
    onThemeChanged: (Int) -> Unit,
    onLoggedOut: () -> Unit
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()

    var tab by remember { mutableStateOf(MainTab.HOME) }
    var libraryMode by remember { mutableStateOf(0) } // 0 álbumes, 1 canciones, 2 sin asignar, 3 charts
    var query by remember { mutableStateOf("") }
    var results by remember { mutableStateOf<List<SparrowApi.Track>>(emptyList()) }
    var searched by remember { mutableStateOf(false) }
    var notice by remember { mutableStateOf("") }
    var playerExpanded by remember { mutableStateOf(false) }
    var showSettings by remember { mutableStateOf(false) }
    var selectedAlbum by remember { mutableStateOf<SparrowApi.Album?>(null) }
    var selectedPlaylist by remember { mutableStateOf<SparrowApi.Playlist?>(null) }
    var pendingPlaylistTrack by remember { mutableStateOf<SparrowApi.Track?>(null) }
    var favoriteIds by remember { mutableStateOf<Set<String>>(emptySet()) }
    var favoriteAlbumIds by remember { mutableStateOf<Set<String>>(emptySet()) }
    var favoriteCollectionsVersion by remember { mutableStateOf(0) }

    LaunchedEffect(Unit) {
        try {
            favoriteIds = withContext(Dispatchers.IO) { SparrowApi.favorites() }
                .map { it.id }
                .toSet()
        } catch (_: Exception) {
        }
        try {
            favoriteAlbumIds = withContext(Dispatchers.IO) { SparrowApi.favoriteAlbums() }
                .map { it.id }
                .toSet()
        } catch (_: Exception) {
        }
    }

    fun toggleFavorite(track: SparrowApi.Track) {
        val remove = track.id in favoriteIds
        scope.launch {
            try {
                withContext(Dispatchers.IO) {
                    if (remove) SparrowApi.removeFavorite(track.id) else SparrowApi.addFavorite(track.id)
                }
                favoriteIds = if (remove) favoriteIds - track.id else favoriteIds + track.id
                notice = context.getString(if (remove) R.string.favorite_removed else R.string.favorite_added)
            } catch (e: Exception) {
                notice = context.getString(R.string.error_generic, e.message ?: "Error")
            }
        }
    }

    fun toggleFavoriteAlbum(album: SparrowApi.Album) {
        val remove = album.id in favoriteAlbumIds
        scope.launch {
            try {
                withContext(Dispatchers.IO) {
                    if (remove) SparrowApi.removeFavoriteAlbum(album.id) else SparrowApi.addFavoriteAlbum(album.id)
                }
                favoriteAlbumIds = if (remove) favoriteAlbumIds - album.id else favoriteAlbumIds + album.id
                favoriteCollectionsVersion++
                notice = context.getString(
                    if (remove) R.string.collection_favorite_removed else R.string.collection_favorite_added
                )
            } catch (e: Exception) {
                notice = context.getString(R.string.error_generic, e.message ?: "Error")
            }
        }
    }

    fun handleError(e: Exception) {
        if (e is SessionRevokedException) SessionState.markRevoked()
        else notice = context.getString(R.string.error_generic, e.message ?: "Error")
    }

    fun play(list: List<SparrowApi.Track>) = playTracks(context, list)
    fun queue(list: List<SparrowApi.Track>) {
        addTracks(context, list)
        notice = context.getString(R.string.added_queue)
    }

    suspend fun loadAlbum(album: SparrowApi.Album): List<SparrowApi.Track> =
        withContext(Dispatchers.IO) { SparrowApi.albumTracks(album.id) }

    fun download(list: List<SparrowApi.Track>) {
        if (list.isEmpty()) {
            notice = context.getString(R.string.no_tracks)
            return
        }
        OfflineDownloads.enqueue(context, user.id, list)
        notice = context.getString(R.string.offline_downloading)
    }

    suspend fun loadPlaylistTracks(playlist: SparrowApi.Playlist): List<SparrowApi.Track> =
        withContext(Dispatchers.IO) { SparrowApi.playlistTracks(playlist.id) }

    LaunchedEffect(Unit) {
        while (true) {
            kotlinx.coroutines.delay(300_000)
            if (TokenStore.token.isNullOrBlank()) continue
            try {
                val me = withContext(Dispatchers.IO) { SparrowApi.me() }
                TokenStore.cachedUser = me
            } catch (e: Exception) {
                if (e is SessionRevokedException) SessionState.markRevoked()
            }
        }
    }

    DisposableEffect(user.id) {
        DownloadActions.downloadTrack = { download(listOf(it)) }
        DownloadActions.downloadAlbum = { album ->
            scope.launch {
                try {
                    download(loadAlbum(album))
                } catch (e: Exception) {
                    handleError(e)
                }
            }
        }
        DownloadActions.downloadPlaylist = { playlist ->
            scope.launch {
                try {
                    download(loadPlaylistTracks(playlist))
                } catch (e: Exception) {
                    handleError(e)
                }
            }
        }
        onDispose {
            DownloadActions.downloadTrack = null
            DownloadActions.downloadAlbum = null
            DownloadActions.downloadPlaylist = null
        }
    }

    fun playAlbum(album: SparrowApi.Album) {
        scope.launch {
            try {
                val tracks = loadAlbum(album)
                if (tracks.isEmpty()) {
                    notice = context.getString(R.string.no_tracks)
                } else {
                    play(tracks)
                    playerExpanded = true
                    notice = context.getString(R.string.playing_collection, album.title)
                }
            } catch (e: Exception) {
                notice = context.getString(R.string.error_generic, e.message ?: "Error")
            }
        }
    }

    fun queueAlbum(album: SparrowApi.Album) {
        scope.launch {
            try {
                val tracks = loadAlbum(album)
                if (tracks.isEmpty()) {
                    notice = context.getString(R.string.no_tracks)
                } else {
                    queue(tracks)
                    notice = context.getString(R.string.queued_collection, album.title)
                }
            } catch (e: Exception) {
                notice = context.getString(R.string.error_generic, e.message ?: "Error")
            }
        }
    }

    fun runSearch() {
        scope.launch {
            notice = context.getString(R.string.searching)
            try {
                results = withContext(Dispatchers.IO) { SparrowApi.search(query) }
                searched = true
                notice = context.getString(R.string.results, results.size)
            } catch (e: Exception) {
                notice = context.getString(R.string.error_generic, e.message ?: "Error")
            }
        }
    }

    fun selectTab(dest: MainTab) {
        tab = dest
        searched = false
        showSettings = false
        selectedAlbum = null
        selectedPlaylist = null
        notice = ""
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Sparrow-Fy") },
                actions = {
                    TextButton(onClick = { showSettings = !showSettings }) {
                        Text(stringResource(if (showSettings) R.string.back else R.string.settings))
                    }
                    TextButton(onClick = {
                        scope.launch {
                            try {
                                withContext(Dispatchers.IO) { SparrowApi.logout() }
                            } catch (e: Exception) {
                                if (e is SessionRevokedException) SessionState.markRevoked()
                            }
                            sendAction(context, PlaybackService.ACTION_STOP)
                            OfflineDownloads.clear(context)
                            OfflineStore.clear()
                            OfflineDownloads.clearState()
                            TokenStore.clear(context)
                            PlayerState.reset()
                            onLoggedOut()
                        }
                    }) {
                        Text(stringResource(R.string.logout))
                    }
                }
            )
        },
        bottomBar = { MiniPlayer(onExpand = { playerExpanded = true }) }
    ) { padding ->
        Column(Modifier.padding(padding).fillMaxSize()) {
            if (!showSettings) {
                // Menú "panorámica" de la web: tira superior desplazable, sin "Colecciones".
                val compactHeader = compactScreen()
                Row(
                    Modifier
                        .fillMaxWidth()
                        .horizontalScroll(rememberScrollState())
                        .background(MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.55f))
                        .padding(horizontal = 8.dp, vertical = 6.dp),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(4.dp)
                ) {
                    listOf(
                        MainTab.HOME to R.string.home to "⌂",
                        MainTab.LIBRARY to R.string.library to "▦",
                        MainTab.OFFLINE to R.string.offline_tab to "⇩",
                        MainTab.FAVORITES to R.string.favorites to "★",
                        MainTab.RECENT to R.string.recent to "◷",
                        MainTab.PLAYLISTS to R.string.playlists to "☷"
                    ).forEach { (entry, symbol) ->
                        val (dest, label) = entry
                        val active = tab == dest && !searched
                        Row(
                            Modifier
                                .clickable { selectTab(dest) }
                                .clip(MaterialTheme.shapes.small)
                                .background(
                                    if (active) MaterialTheme.colorScheme.primary.copy(alpha = 0.28f)
                                    else MaterialTheme.colorScheme.surface.copy(alpha = 0.65f)
                                )
                                .heightIn(min = if (compactHeader) 34.dp else 42.dp)
                                .padding(horizontal = 10.dp, vertical = 6.dp),
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.spacedBy(5.dp)
                        ) {
                            Text(
                                symbol,
                                color = if (active) MaterialTheme.colorScheme.primary
                                else MaterialTheme.colorScheme.onSurfaceVariant,
                                fontSize = if (compactHeader) 14.sp else 16.sp
                            )
                            Text(
                                stringResource(label),
                                color = if (active) MaterialTheme.colorScheme.onSurface
                                else MaterialTheme.colorScheme.onSurfaceVariant,
                                fontSize = if (compactHeader) 12.sp else 13.sp,
                                maxLines = 1
                            )
                        }
                    }
                }
                Row(
                    modifier = Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 4.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    OutlinedTextField(
                        value = query,
                        onValueChange = { query = it },
                        label = { Text(stringResource(R.string.search)) },
                        singleLine = true,
                        maxLines = 1,
                        modifier = Modifier.weight(1f)
                    )
                    IconButton(
                        onClick = { runSearch() },
                        modifier = Modifier.size(if (compactHeader) 42.dp else 48.dp)
                    ) {
                        Icon(
                            PlayerIcons.Search,
                            stringResource(R.string.search_button),
                            tint = MaterialTheme.colorScheme.primary
                        )
                    }
                }
            }
            if (notice.isNotBlank()) {
                Text(
                    notice,
                    style = MaterialTheme.typography.bodySmall,
                    modifier = Modifier.padding(horizontal = 12.dp)
                )
            }

            Box(Modifier.weight(1f)) {
                if (showSettings) {
                    SettingsScreen(
                        onThemeChanged = onThemeChanged,
                        onNotice = { notice = it }
                    )
                } else if (searched) {
                    TrackList(
                        tracks = results,
                        userId = user.id,
                        favoriteIds = favoriteIds,
                        onFavoriteToggle = { toggleFavorite(it) },
                        onPlay = { play(listOf(it)) },
                        onQueue = { queue(listOf(it)) },
                        onPlaylist = { pendingPlaylistTrack = it },
                        onNotice = { notice = it },
                        onPlayAll = { play(results) },
                        onQueueAll = { queue(results) }
                    )
                } else {
                    when (tab) {
                        MainTab.HOME -> HomeScreen(
                            userId = user.id,
                            favoriteIds = favoriteIds,
                            onFavoriteToggle = { toggleFavorite(it) },
                            onPlay = { play(it) },
                            onQueue = { queue(it) },
                            onPlaylist = { pendingPlaylistTrack = it },
                            onNotice = { notice = it },
                            onSeeAlbum = { album ->
                                selectedAlbum = album
                                libraryMode = 0
                                selectTab(MainTab.LIBRARY)
                                selectedAlbum = album
                            },
                            onSeeAll = { mode ->
                                libraryMode = mode
                                selectTab(MainTab.LIBRARY)
                            }
                        )
                        MainTab.LIBRARY -> LibraryScreen(
                            userId = user.id,
                            selectedAlbum = selectedAlbum,
                            onAlbumSelected = { selectedAlbum = it },
                            favoriteIds = favoriteIds,
                            onFavoriteToggle = { toggleFavorite(it) },
                            onPlay = { play(it) },
                            onQueue = { queue(it) },
                            onPlaylist = { pendingPlaylistTrack = it },
                            onNotice = { notice = it },
                            onPlayAlbum = { playAlbum(it) },
                            onQueueAlbum = { queueAlbum(it) },
                            favoriteAlbumIds = favoriteAlbumIds,
                            onToggleFavoriteAlbum = { toggleFavoriteAlbum(it) },
                            mode = libraryMode,
                            onModeChange = { libraryMode = it }
                        )
                        MainTab.OFFLINE -> OfflineScreen(
                            userId = user.id,
                            onNotice = { notice = it }
                        )
                        MainTab.COLLECTIONS -> CollectionsScreen(
                            onPlayAlbum = { playAlbum(it) },
                            onQueueAlbum = { queueAlbum(it) },
                            onOpenAlbum = { selectedAlbum = it },
                            favoriteAlbumIds = favoriteAlbumIds,
                            onToggleFavoriteAlbum = { toggleFavoriteAlbum(it) },
                            onNotice = { notice = it }
                        )
                        MainTab.FAVORITES -> FavoritesScreen(
                            userId = user.id,
                            favoriteCollectionsVersion = favoriteCollectionsVersion,
                            onToggleFavoriteAlbum = { toggleFavoriteAlbum(it) },
                            onPlay = { play(it) },
                            onQueue = { queue(it) },
                            onPlayAlbum = { playAlbum(it) },
                            onQueueAlbum = { queueAlbum(it) },
                            onOpenAlbum = { selectedAlbum = it },
                            onPlaylist = { pendingPlaylistTrack = it },
                            onNotice = { notice = it }
                        )
                        MainTab.RECENT -> RecentScreen(
                            userId = user.id,
                            favoriteIds = favoriteIds,
                            onFavoriteToggle = { toggleFavorite(it) },
                            onPlay = { play(it) },
                            onQueue = { queue(it) },
                            onPlaylist = { pendingPlaylistTrack = it },
                            onNotice = { notice = it }
                        )
                        MainTab.PLAYLISTS -> PlaylistsScreen(
                            userId = user.id,
                            selected = selectedPlaylist,
                            onSelected = { selectedPlaylist = it },
                            onPlay = { play(it) },
                            onQueue = { queue(it) },
                            onPlaylist = { pendingPlaylistTrack = it },
                            onNotice = { notice = it }
                        )
                    }
                }
            }

        }
    }

    pendingPlaylistTrack?.let { track ->
        AddToPlaylistDialog(
            userId = user.id,
            track = track,
            onDismiss = { pendingPlaylistTrack = null },
            onDone = { notice = it }
        )
    }

    if (playerExpanded) {
        PlayerDialog(onDismiss = { playerExpanded = false })
    }
}

@Composable
fun TrackList(
    tracks: List<SparrowApi.Track>,
    userId: String,
    favoriteIds: Set<String>,
    onFavoriteToggle: (SparrowApi.Track) -> Unit,
    onPlay: (SparrowApi.Track) -> Unit,
    onQueue: (SparrowApi.Track) -> Unit,
    onPlaylist: (SparrowApi.Track) -> Unit,
    onNotice: (String) -> Unit,
    onPlayAll: (() -> Unit)? = null,
    onQueueAll: (() -> Unit)? = null
) {
    if (tracks.isEmpty()) {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            Text(stringResource(R.string.no_tracks))
        }
        return
    }
    LazyColumn(modifier = Modifier.fillMaxSize()) {
        items(items = tracks, key = { it.id }) { track ->
            TrackRow(
                track = track,
                favorite = track.id in favoriteIds,
                onPlay = { onPlay(track) },
                onQueue = { onQueue(track) },
                onPlaylist = { onPlaylist(track) },
                onFavorite = { onFavoriteToggle(track) },
                onPlayAll = onPlayAll,
                onQueueAll = onQueueAll
            )
        }
    }
}

@Composable
fun TrackRow(
    track: SparrowApi.Track,
    modifier: Modifier = Modifier,
    favorite: Boolean = false,
    onPlay: () -> Unit,
    onQueue: () -> Unit,
    onPlaylist: () -> Unit,
    onFavorite: (() -> Unit)? = null,
    onPlayAll: (() -> Unit)? = null,
    onQueueAll: (() -> Unit)? = null
) {
    val compact = compactScreen()
    var menuExpanded by remember { mutableStateOf(false) }
    Row(
        modifier = modifier.fillMaxWidth().padding(horizontal = if (compact) 8.dp else 12.dp, vertical = 5.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        TrackArtwork(
            track = track,
            modifier = Modifier.size(if (compact) 36.dp else 44.dp).clip(MaterialTheme.shapes.small)
        )
        Column(Modifier.weight(1f).padding(start = if (compact) 6.dp else 10.dp)) {
            Text(
                track.name,
                style = if (compact) MaterialTheme.typography.bodyMedium else MaterialTheme.typography.bodyLarge,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis
            )
            if (track.subtitle.isNotBlank()) {
                Text(
                    track.subtitle,
                    style = MaterialTheme.typography.bodySmall,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis
                )
            }
        }
        IconButton(onClick = onPlay, modifier = Modifier.size(if (compact) 36.dp else 48.dp)) {
            Icon(PlayerIcons.Play, stringResource(R.string.play))
        }
        Box {
            IconButton(onClick = { menuExpanded = true }, modifier = Modifier.size(if (compact) 36.dp else 48.dp)) {
                Text("⋮")
            }
            DropdownMenu(expanded = menuExpanded, onDismissRequest = { menuExpanded = false }) {
                if (onPlayAll != null) {
                    DropdownMenuItem(
                        text = { Text(stringResource(R.string.play_all)) },
                        onClick = { menuExpanded = false; onPlayAll() }
                    )
                }
                if (onQueueAll != null) {
                    DropdownMenuItem(
                        text = { Text(stringResource(R.string.add_queue)) },
                        onClick = { menuExpanded = false; onQueueAll() }
                    )
                }
                DropdownMenuItem(
                    text = { Text(stringResource(R.string.play)) },
                    onClick = { menuExpanded = false; onPlay() }
                )
                DropdownMenuItem(
                    text = { Text(stringResource(R.string.queue_track)) },
                    onClick = { menuExpanded = false; onQueue() }
                )
                DropdownMenuItem(text = { Text(stringResource(R.string.add_playlist)) }, onClick = { menuExpanded = false; onPlaylist() })
                if (DownloadActions.downloadTrack != null) {
                    DropdownMenuItem(
                        text = { Text(stringResource(R.string.offline_download)) },
                        onClick = { menuExpanded = false; DownloadActions.downloadTrack?.invoke(track) }
                    )
                }
                if (onFavorite != null) {
                    DropdownMenuItem(
                        text = { Text(stringResource(if (favorite) R.string.remove_favorite else R.string.add_favorite)) },
                        onClick = { menuExpanded = false; onFavorite() }
                    )
                }
            }
        }
    }
}

@Composable
fun TrackArtwork(
    track: SparrowApi.Track,
    modifier: Modifier = Modifier
) {
    var url by remember(track.id) { mutableStateOf(track.coverUrl) }
    LaunchedEffect(track.id, url) {
        if (url.isBlank()) {
            url = withContext(Dispatchers.IO) { SparrowApi.trackCover(track.id) }
        }
    }
    CoverImage(url, track.name, modifier)
}

@Composable
fun CoverImage(
    url: String,
    contentDescription: String?,
    modifier: Modifier = Modifier
) {
    var bitmap by remember(url) { mutableStateOf<Bitmap?>(null) }
    LaunchedEffect(url) {
        bitmap = null
        if (url.isBlank()) return@LaunchedEffect
        bitmap = withContext(Dispatchers.IO) {
            try {
                val connection = URL(url).openConnection() as HttpURLConnection
                connection.connectTimeout = 8000
                connection.readTimeout = 8000
                if (url.startsWith(Settings.server, ignoreCase = true)) {
                    connection.setRequestProperty("Authorization", "Bearer ${TokenStore.token.orEmpty()}")
                }
                connection.inputStream.use { BitmapFactory.decodeStream(it) }
            } catch (_: Exception) {
                null
            }
        }
    }
    Box(modifier, contentAlignment = Alignment.Center) {
        val image = bitmap
        if (image != null) {
            Image(
                bitmap = image.asImageBitmap(),
                contentDescription = contentDescription,
                modifier = Modifier.fillMaxSize(),
                contentScale = ContentScale.Crop
            )
        } else {
            Text("♫", style = MaterialTheme.typography.headlineMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
fun CollectionCard(
    album: SparrowApi.Album,
    modifier: Modifier = Modifier,
    favorite: Boolean = false,
    onFavoriteToggle: (() -> Unit)? = null,
    onPlay: () -> Unit,
    onQueue: () -> Unit,
    onOpen: () -> Unit
) {
    var menuExpanded by remember { mutableStateOf(false) }
    val compact = compactScreen()
    Surface(
        shape = MaterialTheme.shapes.medium,
        tonalElevation = 2.dp,
        modifier = modifier.clickable { onOpen() }
    ) {
        Column(Modifier.padding(if (compact) 6.dp else 8.dp)) {
            Box(
                Modifier.fillMaxWidth().aspectRatio(1f).clip(MaterialTheme.shapes.medium)
            ) {
                CoverImage(album.coverUrl, album.title, Modifier.fillMaxSize())
                if (favorite) {
                    Surface(
                        color = MaterialTheme.colorScheme.primary,
                        shape = MaterialTheme.shapes.small,
                        modifier = Modifier.align(Alignment.TopStart).padding(6.dp).size(26.dp)
                    ) {
                        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                            Text(
                                "★",
                                color = MaterialTheme.colorScheme.onPrimary,
                                style = MaterialTheme.typography.bodySmall
                            )
                        }
                    }
                }
                Box(Modifier.align(Alignment.TopEnd)) {
                    TextButton(
                        onClick = { menuExpanded = true },
                        modifier = Modifier.size(36.dp),
                        contentPadding = PaddingValues(0.dp)
                    ) { Text("⋮") }
                    DropdownMenu(
                        expanded = menuExpanded,
                        onDismissRequest = { menuExpanded = false }
                    ) {
                        DropdownMenuItem(
                            text = { Text(stringResource(R.string.play_all)) },
                            onClick = { menuExpanded = false; onPlay() }
                        )
                        DropdownMenuItem(
                            text = { Text(stringResource(R.string.add_queue)) },
                            onClick = { menuExpanded = false; onQueue() }
                        )
                        if (DownloadActions.downloadAlbum != null) {
                            DropdownMenuItem(
                                text = { Text(stringResource(R.string.offline_download)) },
                                onClick = { menuExpanded = false; DownloadActions.downloadAlbum?.invoke(album) }
                            )
                        }
                        DropdownMenuItem(
                            text = {
                                Text(
                                    stringResource(
                                        if (favorite) R.string.favorite_collection_remove
                                        else R.string.favorite_collection_add
                                    )
                                )
                            },
                            onClick = {
                                menuExpanded = false
                                onFavoriteToggle?.invoke()
                            }
                        )
                        DropdownMenuItem(
                            text = { Text(stringResource(R.string.view_tracks)) },
                            onClick = { menuExpanded = false; onOpen() }
                        )
                    }
                }
                Surface(
                    color = MaterialTheme.colorScheme.primary,
                    shape = MaterialTheme.shapes.medium,
                    modifier = Modifier.align(Alignment.BottomEnd).size(44.dp)
                ) {
                    IconButton(onClick = onPlay) {
                        Icon(
                            PlayerIcons.Play,
                            stringResource(R.string.play),
                            tint = MaterialTheme.colorScheme.onPrimary
                        )
                    }
                }
            }
            Text(
                album.title,
                style = if (compact) MaterialTheme.typography.bodyMedium else MaterialTheme.typography.bodyLarge,
                maxLines = if (compact) 1 else 2,
                overflow = TextOverflow.Ellipsis,
                modifier = Modifier.padding(top = if (compact) 5.dp else 8.dp)
            )
            val meta = if (album.trackCount > 0) {
                "${album.trackCount} ${stringResource(R.string.songs)} · ${stringResource(R.string.collection)}"
            } else {
                stringResource(R.string.collection)
            }
            Text(meta, style = MaterialTheme.typography.bodySmall)
        }
    }
}

@Composable
fun CollectionsScreen(
    onPlayAlbum: (SparrowApi.Album) -> Unit,
    onQueueAlbum: (SparrowApi.Album) -> Unit,
    onOpenAlbum: (SparrowApi.Album) -> Unit,
    favoriteAlbumIds: Set<String>,
    onToggleFavoriteAlbum: (SparrowApi.Album) -> Unit,
    onNotice: (String) -> Unit
) {
    val context = LocalContext.current
    var albums by remember { mutableStateOf<List<SparrowApi.Album>>(emptyList()) }

    LaunchedEffect(Unit) {
        try {
            albums = withContext(Dispatchers.IO) { SparrowApi.libraryAlbums(limit = 100) }
        } catch (e: Exception) {
            onNotice(context.getString(R.string.error_generic, e.message ?: "Error"))
        }
    }

    if (albums.isEmpty()) {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            Text(stringResource(R.string.no_collections))
        }
        return
    }

    LazyColumn(Modifier.fillMaxSize()) {
        items(albums.chunked(2), key = { row -> row.first().id }) { row ->
            Row(
                Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 4.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp)
            ) {
                row.forEach { album ->
                    CollectionCard(
                        album = album,
                        modifier = Modifier.weight(1f),
                        favorite = album.id in favoriteAlbumIds,
                        onFavoriteToggle = { onToggleFavoriteAlbum(album) },
                        onPlay = { onPlayAlbum(album) },
                        onQueue = { onQueueAlbum(album) },
                        onOpen = { onOpenAlbum(album) }
                    )
                }
            }
        }
    }
}

@Composable
fun HomeScreen(
    userId: String,
    favoriteIds: Set<String>,
    onFavoriteToggle: (SparrowApi.Track) -> Unit,
    onPlay: (List<SparrowApi.Track>) -> Unit,
    onQueue: (List<SparrowApi.Track>) -> Unit,
    onPlaylist: (SparrowApi.Track) -> Unit,
    onNotice: (String) -> Unit,
    onSeeAlbum: (SparrowApi.Album) -> Unit,
    onSeeAll: (Int) -> Unit
) {
    val context = LocalContext.current
    var charts by remember { mutableStateOf<List<SparrowApi.Track>>(emptyList()) }
    var albums by remember { mutableStateOf<List<SparrowApi.Album>>(emptyList()) }
    var unassigned by remember { mutableStateOf<List<SparrowApi.Track>>(emptyList()) }
    var recent by remember { mutableStateOf<List<SparrowApi.Track>>(emptyList()) }

    LaunchedEffect(Unit) {
        try {
            val data = withContext(Dispatchers.IO) {
                data class HomeData(
                    val charts: List<SparrowApi.Track>,
                    val albums: List<SparrowApi.Album>,
                    val unassigned: List<SparrowApi.Track>,
                    val recent: List<SparrowApi.Track>
                )
                HomeData(
                    charts = runCatching { SparrowApi.charts(limit = 10) }.getOrDefault(emptyList()),
                    albums = runCatching { SparrowApi.libraryAlbums(limit = 4, recent = true) }.getOrDefault(emptyList()),
                    unassigned = runCatching { SparrowApi.unassignedTracks(limit = 10) }.getOrDefault(emptyList()),
                    recent = runCatching { SparrowApi.recent(limit = 10) }.getOrDefault(emptyList())
                )
            }
            charts = data.charts
            albums = data.albums
            unassigned = data.unassigned
            recent = data.recent
        } catch (e: Exception) {
            onNotice(context.getString(R.string.error_generic, e.message ?: "Error"))
        }
    }

    LazyColumn(Modifier.fillMaxSize()) {
        item {
            SectionHeader(stringResource(R.string.charts_section)) { onSeeAll(3) }
            if (charts.isEmpty()) Text(
                stringResource(R.string.no_tracks),
                style = MaterialTheme.typography.bodySmall,
                modifier = Modifier.padding(horizontal = 12.dp, vertical = 4.dp)
            )
        }
        items(charts, key = { "chart-${it.id}" }) { track ->
            TrackRow(
                track = track,
                favorite = track.id in favoriteIds,
                onPlay = { onPlay(listOf(track)) },
                onQueue = { onQueue(listOf(track)) },
                onPlaylist = { onPlaylist(track) },
                onFavorite = { onFavoriteToggle(track) },
                onPlayAll = { onPlay(charts) },
                onQueueAll = { onQueue(charts) }
            )
        }
        item {
            SectionHeader(stringResource(R.string.recent_section)) { onSeeAll(1) }
        }
        items(recent, key = { "home-recent-${it.id}" }) { track ->
            TrackRow(
                track = track,
                favorite = track.id in favoriteIds,
                onPlay = { onPlay(listOf(track)) },
                onQueue = { onQueue(listOf(track)) },
                onPlaylist = { onPlaylist(track) },
                onFavorite = { onFavoriteToggle(track) },
                onPlayAll = { onPlay(recent) },
                onQueueAll = { onQueue(recent) }
            )
        }
        item {
            SectionHeader(stringResource(R.string.albums_section)) { onSeeAll(0) }
        }
        items(albums.chunked(2), key = { row -> "home-album-${row.first().id}" }) { row ->
            Row(
                Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 4.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp)
            ) {
                row.forEach { album ->
                    CollectionCard(
                        album = album,
                        modifier = Modifier.weight(1f),
                        favorite = false,
                        onFavoriteToggle = {},
                        onPlay = { onSeeAlbum(album) },
                        onQueue = { onSeeAlbum(album) },
                        onOpen = { onSeeAlbum(album) }
                    )
                }
            }
        }
        item {
            SectionHeader(stringResource(R.string.unassigned_section)) { onSeeAll(2) }
            if (unassigned.isEmpty()) Text(
                stringResource(R.string.no_tracks),
                style = MaterialTheme.typography.bodySmall,
                modifier = Modifier.padding(horizontal = 12.dp, vertical = 4.dp)
            )
        }
        items(unassigned, key = { "home-loose-${it.id}" }) { track ->
            TrackRow(
                track = track,
                favorite = track.id in favoriteIds,
                onPlay = { onPlay(listOf(track)) },
                onQueue = { onQueue(listOf(track)) },
                onPlaylist = { onPlaylist(track) },
                onFavorite = { onFavoriteToggle(track) },
                onPlayAll = { onPlay(unassigned) },
                onQueueAll = { onQueue(unassigned) }
            )
        }
    }
}

@Composable
private fun SectionHeader(title: String, onSeeAll: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.SpaceBetween
    ) {
        Text(title, style = MaterialTheme.typography.titleMedium)
        TextButton(onClick = onSeeAll) { Text(stringResource(R.string.see_all)) }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun LibraryScreen(
    userId: String,
    selectedAlbum: SparrowApi.Album?,
    onAlbumSelected: (SparrowApi.Album?) -> Unit,
    favoriteIds: Set<String>,
    onFavoriteToggle: (SparrowApi.Track) -> Unit,
    onPlay: (List<SparrowApi.Track>) -> Unit,
    onQueue: (List<SparrowApi.Track>) -> Unit,
    onPlaylist: (SparrowApi.Track) -> Unit,
    onNotice: (String) -> Unit,
    onPlayAlbum: (SparrowApi.Album) -> Unit,
    onQueueAlbum: (SparrowApi.Album) -> Unit,
    favoriteAlbumIds: Set<String>,
    onToggleFavoriteAlbum: (SparrowApi.Album) -> Unit,
    mode: Int,
    onModeChange: (Int) -> Unit
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var pageSize by remember { mutableStateOf(25) }
    var albums by remember { mutableStateOf<List<SparrowApi.Album>>(emptyList()) }
    var songs by remember { mutableStateOf<List<SparrowApi.Track>>(emptyList()) }
    var albumSongs by remember { mutableStateOf<List<SparrowApi.Track>>(emptyList()) }
    var offset by remember { mutableStateOf(0) }
    var endReached by remember { mutableStateOf(false) }
    var loading by remember { mutableStateOf(false) }
    var pageSizeExpanded by remember { mutableStateOf(false) }

    suspend fun loadPage(reset: Boolean) {
        if (loading) return
        loading = true
        val from = if (reset) 0 else offset
        try {
            var loaded = 0
            if (mode == 0) {
                val next = withContext(Dispatchers.IO) {
                    SparrowApi.libraryAlbums(limit = pageSize, offset = from)
                }
                loaded = next.size
                albums = if (reset) next else albums + next
            } else {
                val next = withContext(Dispatchers.IO) {
                    when (mode) {
                        1 -> SparrowApi.libraryTracks(limit = pageSize, offset = from)
                        2 -> SparrowApi.unassignedTracks(limit = pageSize, offset = from)
                        else -> SparrowApi.charts(limit = pageSize, offset = from)
                    }
                }
                loaded = next.size
                songs = if (reset) next else songs + next
            }
            offset = from + loaded
            endReached = loaded < pageSize
        } catch (e: Exception) {
            onNotice(context.getString(R.string.error_generic, e.message ?: "Error"))
        } finally {
            loading = false
        }
    }

    LaunchedEffect(mode, pageSize) {
        albums = emptyList()
        songs = emptyList()
        offset = 0
        endReached = false
        loadPage(reset = true)
    }

    LaunchedEffect(selectedAlbum?.id) {
        val album = selectedAlbum
        if (album != null) {
            try {
                albumSongs = withContext(Dispatchers.IO) { SparrowApi.albumTracks(album.id) }
            } catch (e: Exception) {
                onNotice(context.getString(R.string.error_generic, e.message ?: "Error"))
            }
        }
    }

    if (selectedAlbum != null) {
        Column(Modifier.fillMaxSize()) {
            Button(onClick = { onAlbumSelected(null) }) { Text(stringResource(R.string.back)) }
            Text(selectedAlbum.title, style = MaterialTheme.typography.titleLarge, modifier = Modifier.padding(12.dp))
            LazyColumn {
                items(albumSongs, key = { it.id }) { track ->
                    TrackRow(
                        track = track,
                        favorite = track.id in favoriteIds,
                        onPlay = { onPlay(listOf(track)) },
                        onQueue = { onQueue(listOf(track)) },
                        onPlaylist = { onPlaylist(track) },
                        onFavorite = { onFavoriteToggle(track) },
                        onPlayAll = { onPlay(albumSongs) },
                        onQueueAll = { onQueue(albumSongs) }
                    )
                }
            }
        }
        return
    }

    LazyColumn(Modifier.fillMaxSize()) {
        item {
            Row(
                Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(12.dp),
                horizontalArrangement = Arrangement.spacedBy(6.dp)
            ) {
                Button(onClick = { onModeChange(0) }) { Text(stringResource(R.string.albums)) }
                Button(onClick = { onModeChange(1) }) { Text(stringResource(R.string.songs)) }
                Button(onClick = { onModeChange(2) }) { Text(stringResource(R.string.unassigned_section)) }
                Button(onClick = { onModeChange(3) }) { Text(stringResource(R.string.charts_section)) }
            }
        }
        item {
            ExposedDropdownMenuBox(
                expanded = pageSizeExpanded,
                onExpandedChange = { pageSizeExpanded = it },
                modifier = Modifier.padding(horizontal = 12.dp, vertical = 4.dp)
            ) {
                OutlinedTextField(
                    value = pageSize.toString(),
                    onValueChange = {},
                    readOnly = true,
                    label = { Text(stringResource(R.string.page_size)) },
                    trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = pageSizeExpanded) },
                    modifier = Modifier.menuAnchor().fillMaxWidth()
                )
                ExposedDropdownMenu(expanded = pageSizeExpanded, onDismissRequest = { pageSizeExpanded = false }) {
                    listOf(25, 50, 100).forEach { size ->
                        DropdownMenuItem(
                            text = { Text(size.toString()) },
                            onClick = {
                                pageSize = size
                                pageSizeExpanded = false
                            }
                        )
                    }
                }
            }
        }
        if (mode == 0) {
            items(albums.chunked(2), key = { row -> row.first().id }) { row ->
                Row(
                    Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 4.dp),
                    horizontalArrangement = Arrangement.spacedBy(8.dp)
                ) {
                    row.forEach { album ->
                        CollectionCard(
                            album = album,
                            modifier = Modifier.weight(1f),
                            favorite = album.id in favoriteAlbumIds,
                            onFavoriteToggle = { onToggleFavoriteAlbum(album) },
                            onPlay = { onPlayAlbum(album) },
                            onQueue = { onQueueAlbum(album) },
                            onOpen = { onAlbumSelected(album) }
                        )
                    }
                }
            }
        } else {
            items(songs, key = { it.id }) { track ->
                TrackRow(
                    track = track,
                    favorite = track.id in favoriteIds,
                    onPlay = { onPlay(listOf(track)) },
                    onQueue = { onQueue(listOf(track)) },
                    onPlaylist = { onPlaylist(track) },
                    onFavorite = { onFavoriteToggle(track) },
                    onPlayAll = { onPlay(songs) },
                    onQueueAll = { onQueue(songs) }
                )
            }
        }
        item {
            if (loading) {
                LinearProgressIndicator(Modifier.fillMaxWidth().padding(12.dp))
            } else if (endReached) {
                if (mode == 0 && albums.isEmpty() || mode != 0 && songs.isEmpty()) {
                    Text(stringResource(R.string.no_tracks), modifier = Modifier.padding(12.dp))
                } else {
                    Text(
                        stringResource(R.string.end_of_list),
                        modifier = Modifier.padding(12.dp),
                        style = MaterialTheme.typography.bodySmall
                    )
                }
            } else {
                Button(
                    onClick = { scope.launch { loadPage(reset = false) } },
                    modifier = Modifier.fillMaxWidth().padding(12.dp)
                ) {
                    Text(stringResource(R.string.load_more))
                }
            }
        }
    }
}

@Composable
fun FavoritesScreen(
    userId: String,
    favoriteCollectionsVersion: Int,
    onToggleFavoriteAlbum: (SparrowApi.Album) -> Unit,
    onPlay: (List<SparrowApi.Track>) -> Unit,
    onQueue: (List<SparrowApi.Track>) -> Unit,
    onPlayAlbum: (SparrowApi.Album) -> Unit,
    onQueueAlbum: (SparrowApi.Album) -> Unit,
    onOpenAlbum: (SparrowApi.Album) -> Unit,
    onPlaylist: (SparrowApi.Track) -> Unit,
    onNotice: (String) -> Unit
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var tracks by remember { mutableStateOf<List<SparrowApi.Track>>(emptyList()) }
    var albums by remember { mutableStateOf<List<SparrowApi.Album>>(emptyList()) }
    var version by remember { mutableStateOf(0) }

    LaunchedEffect(version, favoriteCollectionsVersion) {
        try {
            tracks = withContext(Dispatchers.IO) { SparrowApi.favorites() }
            albums = withContext(Dispatchers.IO) { SparrowApi.favoriteAlbums() }
        } catch (e: Exception) {
            onNotice(context.getString(R.string.error_generic, e.message ?: "Error"))
        }
    }

    Column(Modifier.fillMaxSize()) {
        if (tracks.isEmpty() && albums.isEmpty()) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                Text(stringResource(R.string.no_favorites))
            }
        } else {
            LazyColumn {
                if (albums.isNotEmpty()) {
                    item {
                        Text(
                            stringResource(R.string.favorite_collections),
                            style = MaterialTheme.typography.titleMedium,
                            modifier = Modifier.padding(horizontal = 12.dp, vertical = 8.dp)
                        )
                    }
                    items(albums.chunked(2), key = { row -> row.first().id }) { row ->
                        Row(
                            Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 4.dp),
                            horizontalArrangement = Arrangement.spacedBy(8.dp)
                        ) {
                            row.forEach { album ->
                                CollectionCard(
                                    album = album,
                                    modifier = Modifier.weight(1f),
                                    favorite = true,
                                    onFavoriteToggle = { onToggleFavoriteAlbum(album) },
                                    onPlay = { onPlayAlbum(album) },
                                    onQueue = { onQueueAlbum(album) },
                                    onOpen = { onOpenAlbum(album) }
                                )
                            }
                        }
                    }
                }
                if (tracks.isNotEmpty()) {
                    item {
                        Text(
                            stringResource(R.string.favorites),
                            style = MaterialTheme.typography.titleMedium,
                            modifier = Modifier.padding(horizontal = 12.dp, vertical = 8.dp)
                        )
                    }
                    items(tracks, key = { it.id }) { track ->
                        TrackRow(
                            track = track,
                            favorite = true,
                            onPlay = { onPlay(listOf(track)) },
                            onQueue = { onQueue(listOf(track)) },
                            onPlaylist = { onPlaylist(track) },
                            onFavorite = {
                                scope.launch {
                                    try {
                                        withContext(Dispatchers.IO) { SparrowApi.removeFavorite(track.id) }
                                        version++
                                        onNotice(context.getString(R.string.favorite_removed))
                                    } catch (e: Exception) {
                                        onNotice(context.getString(R.string.error_generic, e.message ?: "Error"))
                                    }
                                }
                            },
                            onPlayAll = { onPlay(tracks) },
                            onQueueAll = { onQueue(tracks) }
                        )
                    }
                }
            }
        }
    }
}

@Composable
fun RecentScreen(
    userId: String,
    favoriteIds: Set<String>,
    onFavoriteToggle: (SparrowApi.Track) -> Unit,
    onPlay: (List<SparrowApi.Track>) -> Unit,
    onQueue: (List<SparrowApi.Track>) -> Unit,
    onPlaylist: (SparrowApi.Track) -> Unit,
    onNotice: (String) -> Unit
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var tracks by remember { mutableStateOf<List<SparrowApi.Track>>(emptyList()) }
    var version by remember { mutableStateOf(0) }

    LaunchedEffect(version) {
        try {
            tracks = withContext(Dispatchers.IO) { SparrowApi.recent() }
        } catch (e: Exception) {
            onNotice(context.getString(R.string.error_generic, e.message ?: "Error"))
        }
    }

    Column(Modifier.fillMaxSize()) {
        TrackList(
            tracks = tracks,
            userId = userId,
            favoriteIds = favoriteIds,
            onFavoriteToggle = onFavoriteToggle,
            onPlay = { onPlay(listOf(it)) },
            onQueue = { onQueue(listOf(it)) },
            onPlaylist = onPlaylist,
            onNotice = onNotice,
            onPlayAll = { onPlay(tracks) },
            onQueueAll = { onQueue(tracks) }
        )
    }
}

@Composable
fun OfflineScreen(userId: String, onNotice: (String) -> Unit) {
    val context = LocalContext.current
    val progress by OfflineDownloads.state.collectAsState()
    var downloads by remember(userId) { mutableStateOf(OfflineStore.list(userId)) }
    var quotaVersion by remember { mutableStateOf(0) }
    var confirmClear by remember { mutableStateOf(false) }
    @Suppress("UNUSED_VARIABLE")
    val quotaReload = quotaVersion
    val quota = Settings.offlineQuotaBytes
    val used = downloads.sumOf { it.bytes }

    LaunchedEffect(progress) {
        if (progress.values.any { it.done }) downloads = OfflineStore.list(userId)
    }

    Column(
        Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(12.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp)
    ) {
        Text(stringResource(R.string.offline_section), style = MaterialTheme.typography.titleMedium)
        Text(
            stringResource(R.string.offline_used, mib(used), mib(quota)),
            style = MaterialTheme.typography.bodySmall
        )
        LinearProgressIndicator(
            { (used.toFloat() / quota.coerceAtLeast(1L)).coerceIn(0f, 1f) },
            modifier = Modifier.fillMaxWidth()
        )

        Text(stringResource(R.string.offline_quota), style = MaterialTheme.typography.bodyMedium)
        Row(
            Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()),
            horizontalArrangement = Arrangement.spacedBy(6.dp)
        ) {
            listOf(
                Settings.OFFLINE_QUOTA_MIN_MB,
                100,
                Settings.OFFLINE_QUOTA_DEFAULT_MB,
                500,
                Settings.OFFLINE_QUOTA_MAX_MB
            ).distinct().forEach { mb ->
                val selected = Settings.offlineQuotaBytes == mb.toLong() * 1024 * 1024
                TextButton(
                    onClick = {
                        if (used > mb.toLong() * 1024 * 1024) {
                            onNotice(context.getString(R.string.offline_quota_exceeded))
                        } else {
                            Settings.offlineQuotaBytes = mb.toLong() * 1024 * 1024
                            quotaVersion++
                        }
                    },
                    modifier = Modifier.background(
                        if (selected) MaterialTheme.colorScheme.primary.copy(alpha = 0.25f)
                        else MaterialTheme.colorScheme.surface,
                        MaterialTheme.shapes.small
                    )
                ) { Text("$mb MiB") }
            }
        }

        TextButton(onClick = { confirmClear = true }) {
            Text(stringResource(R.string.offline_clear_all))
        }

        if (downloads.isEmpty()) {
            Box(Modifier.fillMaxWidth().heightIn(min = 120.dp), contentAlignment = Alignment.Center) {
                Text(stringResource(R.string.offline_no_downloads))
            }
        } else {
            downloads.forEach { item ->
                val state = progress[item.id]
                Surface(
                    Modifier.fillMaxWidth(),
                    shape = MaterialTheme.shapes.small,
                    tonalElevation = 1.dp
                ) {
                    Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text(item.name, style = MaterialTheme.typography.bodyLarge, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        Text(
                            listOfNotNull(
                                item.artist.takeIf { it.isNotBlank() },
                                mib(item.bytes),
                                item.quality.takeIf { it.isNotBlank() }
                            ).joinToString(" · "),
                            style = MaterialTheme.typography.bodySmall
                        )
                        if (state != null && !state.done && state.error == null) {
                            LinearProgressIndicator(
                                { state.percent / 100f },
                                modifier = Modifier.fillMaxWidth()
                            )
                            Text(state.message, style = MaterialTheme.typography.bodySmall)
                        }
                        state?.error?.let { error ->
                            Text(error, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.error)
                        }
                        Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                            TextButton(onClick = {
                                playTracks(
                                    context,
                                    listOf(
                                        SparrowApi.Track(
                                            id = item.id,
                                            name = item.name,
                                            subtitle = item.artist,
                                            coverUrl = item.coverUrl
                                        )
                                    )
                                )
                            }) { Text(stringResource(R.string.offline_play)) }
                            TextButton(onClick = {
                                OfflineStore.remove(userId, item.id)
                                OfflineDownloads.remove(context, userId, item.id)
                                downloads = OfflineStore.list(userId)
                            }) { Text(stringResource(R.string.offline_remove_download)) }
                        }
                    }
                }
            }
        }
    }

    if (confirmClear) {
        AlertDialog(
            onDismissRequest = { confirmClear = false },
            title = { Text(stringResource(R.string.offline_clear_all)) },
            text = { Text(stringResource(R.string.offline_clear_confirm)) },
            confirmButton = {
                TextButton(onClick = {
                    OfflineStore.clear()
                    OfflineDownloads.clear(context)
                    OfflineDownloads.clearState()
                    downloads = OfflineStore.list(userId)
                    confirmClear = false
                }) { Text(stringResource(R.string.remove)) }
            },
            dismissButton = {
                TextButton(onClick = { confirmClear = false }) {
                    Text(stringResource(R.string.cancel))
                }
            }
        )
    }
}

@Composable
fun PlaylistsScreen(
    userId: String,
    selected: SparrowApi.Playlist?,
    onSelected: (SparrowApi.Playlist?) -> Unit,
    onPlay: (List<SparrowApi.Track>) -> Unit,
    onQueue: (List<SparrowApi.Track>) -> Unit,
    onPlaylist: (SparrowApi.Track) -> Unit,
    onNotice: (String) -> Unit
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var playlists by remember { mutableStateOf<List<SparrowApi.Playlist>>(emptyList()) }
    var version by remember { mutableStateOf(0) }
    var showCreate by remember { mutableStateOf(false) }
    var shareTarget by remember { mutableStateOf<SparrowApi.Playlist?>(null) }
    var menuPlaylist by remember { mutableStateOf<SparrowApi.Playlist?>(null) }
    var tracks by remember { mutableStateOf<List<SparrowApi.Track>>(emptyList()) }

    LaunchedEffect(version) {
        try {
            playlists = withContext(Dispatchers.IO) { SparrowApi.playlists(userId) }
        } catch (e: Exception) {
            onNotice(context.getString(R.string.error_generic, e.message ?: "Error"))
        }
    }

    LaunchedEffect(selected?.id) {
        val playlist = selected
        if (playlist != null) {
            try {
                tracks = withContext(Dispatchers.IO) { SparrowApi.playlistTracks(playlist.id) }
            } catch (e: Exception) {
                onNotice(context.getString(R.string.error_generic, e.message ?: "Error"))
            }
        }
    }

    if (selected != null) {
        Column(Modifier.fillMaxSize()) {
            Row(Modifier.fillMaxWidth().padding(12.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Button(onClick = { onSelected(null) }) { Text(stringResource(R.string.back)) }
                if (selected.isOwner) {
                    Button(onClick = { shareTarget = selected }) { Text(stringResource(R.string.share)) }
                }
                if (DownloadActions.downloadPlaylist != null) {
                    Button(onClick = { DownloadActions.downloadPlaylist?.invoke(selected) }) {
                        Text(stringResource(R.string.offline_download))
                    }
                }
            }
            Text(selected.name, style = MaterialTheme.typography.titleLarge, modifier = Modifier.padding(12.dp))
            LazyColumn {
                items(tracks, key = { it.id }) { track ->
                    TrackRow(
                        track = track,
                        onPlay = { onPlay(listOf(track)) },
                        onQueue = { onQueue(listOf(track)) },
                        onPlaylist = { onPlaylist(track) },
                        onFavorite = if (selected.isOwner) {
                            {
                                scope.launch {
                                    try {
                                        withContext(Dispatchers.IO) { SparrowApi.removeFromPlaylist(selected.id, track.id) }
                                        version++
                                    } catch (e: Exception) {
                                        onNotice(context.getString(R.string.error_generic, e.message ?: "Error"))
                                    }
                                }
                            }
                        } else null,
                        onPlayAll = { onPlay(tracks) },
                        onQueueAll = { onQueue(tracks) }
                    )
                }
            }
        }
    } else {
        Column(Modifier.fillMaxSize()) {
            Button(
                onClick = { showCreate = true },
                modifier = Modifier.padding(12.dp)
            ) { Text(stringResource(R.string.create_playlist)) }
            if (playlists.isEmpty()) {
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { Text(stringResource(R.string.no_playlists)) }
            } else {
                LazyColumn {
                    items(playlists, key = { it.id }) { playlist ->
                        Row(
                            Modifier.fillMaxWidth().clickable { onSelected(playlist) }.padding(12.dp),
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            Column(Modifier.weight(1f)) {
                                Text(playlist.name, style = MaterialTheme.typography.bodyLarge)
                                Text(
                                    "${playlist.trackCount} " + context.getString(R.string.songs) +
                                        if (playlist.isOwner) "" else " · " + context.getString(R.string.shared),
                                    style = MaterialTheme.typography.bodySmall
                                )
                            }
                            if (DownloadActions.downloadPlaylist != null) {
                                Box {
                                    IconButton(onClick = { menuPlaylist = playlist }) { Text("⋮") }
                                    DropdownMenu(
                                        expanded = menuPlaylist?.id == playlist.id,
                                        onDismissRequest = { menuPlaylist = null }
                                    ) {
                                        DropdownMenuItem(
                                            text = { Text(stringResource(R.string.offline_download)) },
                                            onClick = {
                                                menuPlaylist = null
                                                DownloadActions.downloadPlaylist?.invoke(playlist)
                                            }
                                        )
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    if (showCreate) {
        CreatePlaylistDialog(
            onDismiss = { showCreate = false },
            onCreated = {
                version++
                onNotice(context.getString(R.string.playlist_created))
            }
        )
    }

    shareTarget?.let { playlist ->
        SharePlaylistDialog(
            playlist = playlist,
            onDismiss = { shareTarget = null },
            onDone = { onNotice(it) }
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AddToPlaylistDialog(
    userId: String,
    track: SparrowApi.Track,
    onDismiss: () -> Unit,
    onDone: (String) -> Unit
) {
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    var playlists by remember { mutableStateOf<List<SparrowApi.Playlist>>(emptyList()) }
    var status by remember { mutableStateOf("") }

    LaunchedEffect(Unit) {
        try {
            playlists = withContext(Dispatchers.IO) { SparrowApi.playlists(userId) }
        } catch (e: Exception) {
            status = context.getString(R.string.error_generic, e.message ?: "Error")
        }
    }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.add_playlist)) },
        text = {
            Column {
                Text(track.name, style = MaterialTheme.typography.bodyMedium)
                if (status.isNotBlank()) Text(status, style = MaterialTheme.typography.bodySmall)
                LazyColumn(Modifier.heightIn(max = 260.dp)) {
                    items(playlists.filter { it.isOwner }, key = { it.id }) { playlist ->
                        TextButton(onClick = {
                            scope.launch {
                                try {
                                    withContext(Dispatchers.IO) { SparrowApi.addToPlaylist(playlist.id, track.id) }
                                    onDone(context.getString(R.string.added_playlist))
                                    onDismiss()
                                } catch (e: Exception) {
                                    status = context.getString(R.string.error_generic, e.message ?: "Error")
                                }
                            }
                        }) { Text(playlist.name) }
                    }
                }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.close)) } }
    )
}

@Composable
fun CreatePlaylistDialog(onDismiss: () -> Unit, onCreated: () -> Unit) {
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    var name by remember { mutableStateOf("") }
    var status by remember { mutableStateOf("") }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.create_playlist)) },
        text = {
            Column {
                OutlinedTextField(
                    value = name,
                    onValueChange = { name = it },
                    label = { Text(stringResource(R.string.playlist_name)) },
                    singleLine = true
                )
                if (status.isNotBlank()) Text(status, style = MaterialTheme.typography.bodySmall)
            }
        },
        confirmButton = {
            TextButton(onClick = {
                scope.launch {
                    try {
                        withContext(Dispatchers.IO) { SparrowApi.createPlaylist(name) }
                        onCreated()
                        onDismiss()
                    } catch (e: Exception) {
                        status = context.getString(R.string.error_generic, e.message ?: "Error")
                    }
                }
            }) { Text(stringResource(R.string.create)) }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.cancel)) } }
    )
}

@Composable
fun SharePlaylistDialog(
    playlist: SparrowApi.Playlist,
    onDismiss: () -> Unit,
    onDone: (String) -> Unit
) {
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    var username by remember { mutableStateOf("") }
    var status by remember { mutableStateOf("") }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.share)) },
        text = {
            Column {
                Text(playlist.name)
                OutlinedTextField(
                    value = username,
                    onValueChange = { username = it },
                    label = { Text(stringResource(R.string.user)) },
                    singleLine = true
                )
                if (status.isNotBlank()) Text(status, style = MaterialTheme.typography.bodySmall)
            }
        },
        confirmButton = {
            TextButton(onClick = {
                scope.launch {
                    try {
                        withContext(Dispatchers.IO) { SparrowApi.sharePlaylist(playlist.id, username) }
                        onDone(context.getString(R.string.shared))
                        onDismiss()
                    } catch (e: Exception) {
                        status = context.getString(R.string.error_generic, e.message ?: "Error")
                    }
                }
            }) { Text(stringResource(R.string.send)) }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.cancel)) } }
    )
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SettingsScreen(
    onThemeChanged: (Int) -> Unit,
    onNotice: (String) -> Unit
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var theme by remember { mutableStateOf(Settings.theme) }
    var themeExpanded by remember { mutableStateOf(false) }
    val themeNames = stringArrayResource(R.array.theme_names)
    var subject by remember { mutableStateOf("") }
    var message by remember { mutableStateOf("") }

    Column(
        Modifier.fillMaxSize().padding(12.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp)
    ) {
        Text(stringResource(R.string.settings), style = MaterialTheme.typography.titleLarge)
        ExposedDropdownMenuBox(
            expanded = themeExpanded,
            onExpandedChange = { themeExpanded = !themeExpanded }
        ) {
            TextField(
                value = themeNames.getOrElse(theme) { themeNames.first() },
                onValueChange = {},
                readOnly = true,
                label = { Text(stringResource(R.string.theme)) },
                trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = themeExpanded) },
                modifier = Modifier.menuAnchor().fillMaxWidth()
            )
            ExposedDropdownMenu(
                expanded = themeExpanded,
                onDismissRequest = { themeExpanded = false }
            ) {
                themeNames.forEachIndexed { index, name ->
                    DropdownMenuItem(
                        text = { Text(name) },
                        onClick = {
                            theme = index
                            Settings.theme = index
                            onThemeChanged(index)
                            themeExpanded = false
                        }
                    )
                }
            }
        }

        Text(stringResource(R.string.suggestions), style = MaterialTheme.typography.titleMedium)
        OutlinedTextField(
            value = subject,
            onValueChange = { subject = it },
            label = { Text(stringResource(R.string.subject)) },
            singleLine = true,
            modifier = Modifier.fillMaxWidth()
        )
        OutlinedTextField(
            value = message,
            onValueChange = { message = it },
            label = { Text(stringResource(R.string.message)) },
            minLines = 3,
            modifier = Modifier.fillMaxWidth()
        )
        Button(onClick = {
            scope.launch {
                try {
                    withContext(Dispatchers.IO) { SparrowApi.sendSuggestion(subject, message) }
                    subject = ""
                    message = ""
                    onNotice(context.getString(R.string.sent))
                } catch (e: Exception) {
                    onNotice(context.getString(R.string.error_generic, e.message ?: "Error"))
                }
            }
        }) { Text(stringResource(R.string.send)) }
    }
}

@Composable
fun MiniPlayer(onExpand: () -> Unit) {
    val context = LocalContext.current
    val queue by PlayerState.queue.collectAsState()
    val index by PlayerState.currentIndex.collectAsState()
    val playing by PlayerState.isPlaying.collectAsState()
    val shuffle by PlayerState.shuffle.collectAsState()
    val position by PlayerState.positionMs.collectAsState()
    val duration by PlayerState.durationMs.collectAsState()
    val current = queue.getOrNull(index)
    val fraction = if (duration > 0) (position.toFloat() / duration.toFloat()).coerceIn(0f, 1f) else 0f
    val compact = compactScreen()
    val buttonSize = if (compact) 30.dp else 36.dp
    val iconSize = if (compact) 16.dp else 20.dp

    Surface(tonalElevation = 3.dp, shadowElevation = 6.dp) {
        Column {
            LinearProgressIndicator({ fraction }, modifier = Modifier.fillMaxWidth().height(2.dp))
            Row(
                Modifier
                    .fillMaxWidth()
                    .clickable(onClick = onExpand)
                    .padding(horizontal = if (compact) 6.dp else 12.dp, vertical = if (compact) 6.dp else 8.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(if (compact) 0.dp else 2.dp)
            ) {
                if (current != null) {
                    TrackArtwork(
                        track = current,
                        modifier = Modifier.size(if (compact) 36.dp else 44.dp).clip(MaterialTheme.shapes.small)
                    )
                } else {
                    CoverImage("", null, Modifier.size(if (compact) 36.dp else 44.dp).clip(MaterialTheme.shapes.small))
                }
                Column(Modifier.weight(1f).padding(start = if (compact) 6.dp else 10.dp)) {
                    Text(
                        current?.name ?: stringResource(R.string.no_tracks),
                        style = if (compact) MaterialTheme.typography.bodyMedium else MaterialTheme.typography.bodyLarge,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis
                    )
                    Text(
                        current?.subtitle.orEmpty(),
                        style = MaterialTheme.typography.bodySmall,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis
                    )
                }
                IconButton(
                    onClick = { sendAction(context, PlaybackService.ACTION_TOGGLE_SHUFFLE) },
                    modifier = Modifier.size(buttonSize)
                ) {
                    Icon(
                        PlayerIcons.Shuffle,
                        stringResource(R.string.shuffle),
                        modifier = Modifier.size(iconSize),
                        tint = if (shuffle) MaterialTheme.colorScheme.primary
                        else MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
                IconButton(
                    onClick = {
                        sendAction(context, PlaybackService.ACTION_SEEK) {
                            putExtra(PlaybackService.EXTRA_MS, 0L)
                        }
                    },
                    modifier = Modifier.size(buttonSize)
                ) {
                    Icon(PlayerIcons.Restart, stringResource(R.string.restart), modifier = Modifier.size(iconSize))
                }
                IconButton(
                    onClick = { sendAction(context, PlaybackService.ACTION_PREVIOUS) },
                    modifier = Modifier.size(buttonSize)
                ) {
                    Icon(PlayerIcons.Previous, stringResource(R.string.previous), modifier = Modifier.size(iconSize))
                }
                IconButton(
                    onClick = { sendAction(context, PlaybackService.ACTION_PAUSE_RESUME) },
                    modifier = Modifier.size(buttonSize)
                ) {
                    Icon(
                        if (playing) PlayerIcons.Pause else PlayerIcons.Play,
                        stringResource(if (playing) R.string.pause else R.string.play),
                        modifier = Modifier.size(iconSize),
                        tint = MaterialTheme.colorScheme.primary
                    )
                }
                IconButton(
                    onClick = { sendAction(context, PlaybackService.ACTION_NEXT) },
                    modifier = Modifier.size(buttonSize)
                ) {
                    Icon(PlayerIcons.Next, stringResource(R.string.next), modifier = Modifier.size(iconSize))
                }
            }
        }
    }
}

@Composable
fun LyricsPanel(
    track: SparrowApi.Track,
    position: Long,
    modifier: Modifier = Modifier
) {
    var lyrics by remember(track.id) { mutableStateOf<SparrowApi.Lyrics?>(null) }
    var loading by remember(track.id) { mutableStateOf(true) }
    LaunchedEffect(track.id) {
        loading = true
        lyrics = withContext(Dispatchers.IO) {
            try {
                SparrowApi.trackLyrics(track.id)
            } catch (_: Exception) {
                null
            }
        }
        loading = false
    }

    val data = lyrics
    val lines = remember(data) { parseLrc(data?.synced.orEmpty()) }
    val active = if (lines.isEmpty()) -1 else lines.indexOfLast { it.timeMs <= position }.coerceAtLeast(0)
    val listState = rememberLazyListState()
    val scrollState = rememberScrollState()

    LaunchedEffect(active) {
        if (active >= 0) listState.animateScrollToItem(active)
    }

    Column(modifier.fillMaxWidth().heightIn(max = 180.dp)) {
        if (loading) {
            Text(stringResource(R.string.loading_lyrics), style = MaterialTheme.typography.bodySmall)
            return@Column
        }
        if (lines.isNotEmpty()) {
            LazyColumn(state = listState, modifier = Modifier.fillMaxWidth().heightIn(max = 160.dp)) {
                itemsIndexed(lines) { i, line ->
                    Text(
                        line.text,
                        style = MaterialTheme.typography.bodyMedium,
                        color = if (i == active) MaterialTheme.colorScheme.primary
                        else MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.fillMaxWidth().padding(vertical = 2.dp)
                    )
                }
            }
        } else if (!data?.plain.isNullOrBlank()) {
            Text(
                data!!.plain!!,
                style = MaterialTheme.typography.bodyMedium,
                modifier = Modifier.fillMaxWidth().verticalScroll(scrollState)
            )
        } else {
            Text(stringResource(R.string.no_lyrics), style = MaterialTheme.typography.bodySmall)
        }
        val source = data?.source
        if (!source.isNullOrBlank()) {
            Text(
                stringResource(R.string.lyrics_source, source),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )
        }
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
fun PlayerDialog(onDismiss: () -> Unit) {
    val context = LocalContext.current
    val queue by PlayerState.queue.collectAsState()
    val index by PlayerState.currentIndex.collectAsState()
    val playing by PlayerState.isPlaying.collectAsState()
    val shuffle by PlayerState.shuffle.collectAsState()
    val repeat by PlayerState.repeatMode.collectAsState()
    val position by PlayerState.positionMs.collectAsState()
    val duration by PlayerState.durationMs.collectAsState()
    val current = queue.getOrNull(index)
    val max = if (duration > 0) duration.toFloat() else 1f
    var showLyrics by remember { mutableStateOf(false) }
    val compact = compactScreen()
    val queueListState = rememberLazyListState()
    val haptics = LocalHapticFeedback.current
    var dragStartY by remember { mutableStateOf(0f) }
    var dragTarget by remember { mutableStateOf(-1) }
    var selectedQueueIndex by remember { mutableStateOf(-1) }

    Dialog(
        onDismissRequest = onDismiss,
        properties = DialogProperties(usePlatformDefaultWidth = false)
    ) {
        Surface(
            shape = MaterialTheme.shapes.large,
            tonalElevation = 6.dp,
            modifier = Modifier
                .fillMaxWidth()
                .heightIn(max = if (shortScreen()) 560.dp else 760.dp)
                .systemBottomPadding()
        ) {
            Column(Modifier.padding(if (compact) 10.dp else 16.dp)) {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text(stringResource(R.string.now_playing), style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
                    TextButton(onClick = { showLyrics = !showLyrics }) {
                        Text(stringResource(if (showLyrics) R.string.hide_lyrics else R.string.lyrics))
                    }
                    IconButton(onClick = onDismiss) {
                        Icon(PlayerIcons.Close, stringResource(R.string.close))
                    }
                }
                // Portada reducida ~70% (160 dp -> 48 dp) para pantallas compactas.
                val artSize = if (compact) 40.dp else 48.dp
                if (current != null) {
                    TrackArtwork(
                        track = current,
                        modifier = Modifier.align(Alignment.CenterHorizontally)
                            .size(artSize)
                            .clip(MaterialTheme.shapes.medium)
                    )
                } else {
                    CoverImage(
                        "",
                        null,
                        Modifier.align(Alignment.CenterHorizontally)
                            .size(artSize)
                            .clip(MaterialTheme.shapes.medium)
                    )
                }
                Text(
                    current?.name ?: stringResource(R.string.no_tracks),
                    style = if (compact) MaterialTheme.typography.bodyMedium else MaterialTheme.typography.titleSmall,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.fillMaxWidth().padding(top = if (compact) 6.dp else 10.dp)
                )
                Text(
                    current?.subtitle.orEmpty(),
                    style = MaterialTheme.typography.bodySmall,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.fillMaxWidth()
                )
                Text(
                    stringResource(if (playing) R.string.playing else R.string.paused),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.primary
                )
                if (showLyrics && current != null) {
                    LyricsPanel(track = current, position = position)
                }

                Slider(
                    value = position.toFloat().coerceIn(0f, max),
                    valueRange = 0f..max,
                    onValueChange = { value ->
                        sendAction(context, PlaybackService.ACTION_SEEK) {
                            putExtra(PlaybackService.EXTRA_MS, value.toLong())
                        }
                    }
                )
                Text("${formatTime(position)} / ${formatTime(duration)}", style = MaterialTheme.typography.bodySmall)

                Row(
                    Modifier.fillMaxWidth().padding(vertical = 4.dp),
                    horizontalArrangement = Arrangement.SpaceEvenly,
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    IconButton(onClick = { sendAction(context, PlaybackService.ACTION_TOGGLE_SHUFFLE) }) {
                        Icon(
                            PlayerIcons.Shuffle,
                            stringResource(R.string.shuffle),
                            tint = if (shuffle) MaterialTheme.colorScheme.primary
                            else MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    }
                    IconButton(onClick = {
                        sendAction(context, PlaybackService.ACTION_SEEK) {
                            putExtra(PlaybackService.EXTRA_MS, 0L)
                        }
                    }) {
                        Icon(PlayerIcons.Restart, stringResource(R.string.restart))
                    }
                    IconButton(onClick = { sendAction(context, PlaybackService.ACTION_PREVIOUS) }) {
                        Icon(PlayerIcons.Previous, stringResource(R.string.previous))
                    }
                    IconButton(
                        onClick = { sendAction(context, PlaybackService.ACTION_PAUSE_RESUME) },
                        modifier = Modifier.size(if (compact) 52.dp else 60.dp)
                    ) {
                        Icon(
                            if (playing) PlayerIcons.Pause else PlayerIcons.Play,
                            stringResource(if (playing) R.string.pause else R.string.play),
                            modifier = Modifier.size(if (compact) 30.dp else 34.dp),
                            tint = MaterialTheme.colorScheme.primary
                        )
                    }
                    IconButton(onClick = { sendAction(context, PlaybackService.ACTION_NEXT) }) {
                        Icon(PlayerIcons.Next, stringResource(R.string.next))
                    }
                }
                TextButton(onClick = { sendAction(context, PlaybackService.ACTION_CYCLE_REPEAT) }) {
                    val label = when (repeat) {
                        androidx.media3.common.Player.REPEAT_MODE_ONE -> R.string.repeat_one
                        androidx.media3.common.Player.REPEAT_MODE_OFF -> R.string.repeat_off
                        else -> R.string.repeat_all
                    }
                    Text(stringResource(label))
                }

                Text(stringResource(R.string.queue), style = MaterialTheme.typography.titleSmall)
                LazyColumn(state = queueListState, modifier = Modifier.heightIn(max = if (compact) 220.dp else 260.dp)) {
                    itemsIndexed(queue) { i, track ->
                        val upLabel = stringResource(R.string.up)
                        val downLabel = stringResource(R.string.down)
                        val nameHint = stringResource(R.string.queue_name_hint)
                        val selected = selectedQueueIndex == i
                        Row(
                            Modifier
                                .fillMaxWidth()
                                .padding(vertical = 2.dp)
                                .background(
                                    if (dragTarget == i || selected) MaterialTheme.colorScheme.primary.copy(alpha = 0.16f)
                                    else androidx.compose.ui.graphics.Color.Transparent
                                )
                                .clip(MaterialTheme.shapes.small),
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            // Handle único: long-press (~500 ms, patrón de Compose) y arrastre.
                            Box(
                                Modifier
                                    .size(if (compact) 30.dp else 36.dp)
                                    .pointerInput(i) {
                                        detectDragGesturesAfterLongPress(
                                            onDragStart = { offset ->
                                                haptics.performHapticFeedback(HapticFeedbackType.LongPress)
                                                dragStartY = offset.y
                                                dragTarget = i
                                            },
                                            onDrag = { change, _ ->
                                                change.consume()
                                                val visible = queueListState.layoutInfo.visibleItemsInfo
                                                val source = visible.firstOrNull { it.index == i } ?: return@detectDragGesturesAfterLongPress
                                                val pointerY = source.offset + source.size / 2f + (change.position.y - dragStartY)
                                                val target = visible.minByOrNull { info ->
                                                    kotlin.math.abs((info.offset + info.size / 2f) - pointerY)
                                                }?.index ?: i
                                                dragTarget = target
                                            },
                                            onDragEnd = {
                                                val target = dragTarget
                                                dragTarget = -1
                                                if (target >= 0 && target != i) {
                                                    sendAction(context, PlaybackService.ACTION_MOVE) {
                                                        putExtra(PlaybackService.EXTRA_FROM, i)
                                                        putExtra(PlaybackService.EXTRA_TO, target)
                                                    }
                                                }
                                            },
                                            onDragCancel = { dragTarget = -1 }
                                        )
                                    }
                                    .semantics {
                                        customActions = listOf(
                                            CustomAccessibilityAction(upLabel) {
                                                if (i > 0) {
                                                    sendAction(context, PlaybackService.ACTION_MOVE) {
                                                        putExtra(PlaybackService.EXTRA_FROM, i)
                                                        putExtra(PlaybackService.EXTRA_TO, i - 1)
                                                    }
                                                }
                                                true
                                            },
                                            CustomAccessibilityAction(downLabel) {
                                                if (i < queue.lastIndex) {
                                                    sendAction(context, PlaybackService.ACTION_MOVE) {
                                                        putExtra(PlaybackService.EXTRA_FROM, i)
                                                        putExtra(PlaybackService.EXTRA_TO, i + 1)
                                                    }
                                                }
                                                true
                                            }
                                        )
                                    },
                                contentAlignment = Alignment.Center
                            ) {
                                Icon(
                                    PlayerIcons.DragHandle,
                                    stringResource(R.string.drag_handle),
                                    tint = MaterialTheme.colorScheme.onSurfaceVariant,
                                    modifier = Modifier.size(if (compact) 16.dp else 20.dp)
                                )
                            }
                            TrackArtwork(
                                track = track,
                                modifier = Modifier.size(if (compact) 22.dp else 26.dp).clip(MaterialTheme.shapes.small)
                            )
                            Column(
                                Modifier
                                    .weight(1f)
                                    .padding(start = 6.dp)
                                    .clip(MaterialTheme.shapes.small)
                                    .clickable { selectedQueueIndex = if (selected) -1 else i }
                                    .semantics { contentDescription = nameHint }
                            ) {
                                Text(
                                    "${i + 1}. ${track.name}",
                                    style = MaterialTheme.typography.bodySmall,
                                    maxLines = 1,
                                    overflow = if (selected) TextOverflow.Clip else TextOverflow.Ellipsis,
                                    modifier = Modifier
                                        .fillMaxWidth()
                                        .then(
                                            if (selected) {
                                                Modifier.basicMarquee(
                                                    animationMode = MarqueeAnimationMode.Immediately,
                                                    iterations = Int.MAX_VALUE
                                                )
                                            } else Modifier
                                        )
                                )
                                if (track.subtitle.isNotBlank()) {
                                    Text(
                                        track.subtitle,
                                        style = MaterialTheme.typography.labelSmall,
                                        maxLines = 1,
                                        overflow = TextOverflow.Ellipsis
                                    )
                                }
                            }
                            IconButton(
                                onClick = {
                                    selectedQueueIndex = -1
                                    playTracks(context, queue, i)
                                },
                                modifier = Modifier.size(if (compact) 32.dp else 40.dp)
                            ) {
                                Icon(PlayerIcons.Play, stringResource(R.string.play))
                            }
                            TextButton(onClick = {
                                selectedQueueIndex = -1
                                sendAction(context, PlaybackService.ACTION_REMOVE) {
                                    putExtra(PlaybackService.EXTRA_INDEX, i)
                                }
                            }) { Text(stringResource(R.string.remove), style = MaterialTheme.typography.labelSmall) }
                        }
                    }
                }
            }
        }
    }
}

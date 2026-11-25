package com.sparrowfy.player

import androidx.media3.common.Player
import kotlinx.coroutines.flow.MutableStateFlow

/** Estado espejo entre PlaybackService y la UI (cola y controles). */
object PlayerState {
    val queue = MutableStateFlow<List<SparrowApi.Track>>(emptyList())
    val currentIndex = MutableStateFlow(-1)
    val isPlaying = MutableStateFlow(false)
    val shuffle = MutableStateFlow(false)
    val repeatMode = MutableStateFlow(Player.REPEAT_MODE_ALL)
    val durationMs = MutableStateFlow(0L)
    val positionMs = MutableStateFlow(0L)

    fun reset() {
        queue.value = emptyList()
        currentIndex.value = -1
        isPlaying.value = false
        positionMs.value = 0L
        durationMs.value = 0L
    }
}

package com.sparrowfy.player

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow

/** Un 401 en cualquier petición (API, HLS o descarga) termina la sesión en toda la app. */
object SessionState {
    private val _revoked = MutableStateFlow(false)
    val revoked: StateFlow<Boolean> = _revoked

    fun markRevoked() {
        _revoked.value = true
    }

    fun clear() {
        _revoked.value = false
    }
}

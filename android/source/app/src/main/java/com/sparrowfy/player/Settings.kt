package com.sparrowfy.player

import android.content.Context
import android.content.SharedPreferences
import android.net.Uri

object Settings {
    const val DEFAULT_SERVER = "https://sparrowfy.ddns.info"
    const val OFFLINE_QUOTA_MIN_MB = 50
    const val OFFLINE_QUOTA_MAX_MB = 1000
    const val OFFLINE_QUOTA_DEFAULT_MB = 200
    private const val PREFS = "sparrowfy_settings"
    private const val KEY_SERVER = "server"
    private const val KEY_LANG = "lang"
    private const val KEY_THEME = "theme"
    private const val KEY_OFFLINE_QUOTA = "offline_quota"

    private lateinit var prefs: SharedPreferences

    fun init(context: Context) {
        if (::prefs.isInitialized) return
        prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
    }

    var server: String
        get() = prefs.getString(KEY_SERVER, DEFAULT_SERVER) ?: DEFAULT_SERVER
        set(value) = prefs.edit().putString(KEY_SERVER, normalize(value)).apply()

    var language: String
        get() = prefs.getString(KEY_LANG, "es") ?: "es"
        set(value) = prefs.edit().putString(KEY_LANG, if (value == "en") "en" else "es").apply()

    var theme: Int
        get() = prefs.getInt(KEY_THEME, 0)
        set(value) = prefs.edit().putInt(KEY_THEME, value.coerceIn(0, 8)).apply()

    /** Límite de descargas por dispositivo. 200 MiB por defecto, como en la webapp. */
    var offlineQuotaBytes: Long
        get() = prefs.getLong(
            KEY_OFFLINE_QUOTA,
            OFFLINE_QUOTA_DEFAULT_MB.toLong() * 1024 * 1024
        ).coerceIn(
            OFFLINE_QUOTA_MIN_MB.toLong() * 1024 * 1024,
            OFFLINE_QUOTA_MAX_MB.toLong() * 1024 * 1024
        )
        set(value) = prefs.edit().putLong(
            KEY_OFFLINE_QUOTA,
            value.coerceIn(
                OFFLINE_QUOTA_MIN_MB.toLong() * 1024 * 1024,
                OFFLINE_QUOTA_MAX_MB.toLong() * 1024 * 1024
            )
        ).apply()

    fun streamUrl(trackId: String): String =
        server.trimEnd('/') + "/api/v1/stream.php?track_id=$trackId"

    /** Acepta "host:8443", "https://host:8443" o la URL completa. */
    fun normalize(input: String): String {
        var value = input.trim()
        if (value.isBlank()) return DEFAULT_SERVER
        if (!value.contains("://")) value = "https://$value"
        return value.trimEnd('/')
    }

    fun isValidServer(input: String): Boolean {
        val value = normalize(input)
        return try {
            val uri = Uri.parse(value)
            val port = uri.port
            (uri.scheme == "https" || uri.scheme == "http") &&
                !uri.host.isNullOrBlank() && port in -1..65535
        } catch (_: Exception) {
            false
        }
    }

    fun isHttps(input: String): Boolean = normalize(input).startsWith("https://", ignoreCase = true)
}

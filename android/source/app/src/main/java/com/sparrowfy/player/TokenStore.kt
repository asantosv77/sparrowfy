package com.sparrowfy.player

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import org.json.JSONObject
import java.io.File

object TokenStore {
    private lateinit var prefs: SharedPreferences
    private lateinit var appContext: Context

    fun init(context: Context) {
        if (::prefs.isInitialized) return
        appContext = context.applicationContext
        val master = MasterKey.Builder(appContext)
            .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
            .build()
        prefs = EncryptedSharedPreferences.create(
            appContext,
            "sparrowfy_secure",
            master,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
        )
    }

    var token: String?
        get() = prefs.getString("token", null)
        set(value) = prefs.edit().putString("token", value).apply()

    /** Identidad de la última validación online: permite abrir la app sin red. */
    var cachedUser: SparrowApi.User?
        get() {
            val raw = prefs.getString("user", null) ?: return null
            return try {
                val o = JSONObject(raw)
                SparrowApi.User(
                    id = o.optString("id"),
                    username = o.optString("username"),
                    role = o.optString("role")
                )
            } catch (_: Exception) {
                null
            }
        }
        set(value) {
            val raw = value?.let { user ->
                JSONObject()
                    .put("id", user.id)
                    .put("username", user.username)
                    .put("role", user.role)
                    .toString()
            }
            prefs.edit().putString("user", raw).apply()
        }

    /**
     * La sesión solo sobrevive mientras exista esta marca en cacheDir. Borrar la caché
     * de la app la elimina y fuerza un nuevo inicio de sesión.
     */
    fun markActive() {
        markerFile().writeText("1")
    }

    fun sessionActive(): Boolean = markerFile().exists()

    private fun markerFile(): File = File(appContext.cacheDir, "sparrowfy_session")

    fun clear(context: Context? = null) {
        val target = context?.applicationContext ?: if (::appContext.isInitialized) appContext else null
        if (::prefs.isInitialized) {
            prefs.edit().remove("token").remove("user").apply()
        }
        target?.let { File(it.cacheDir, "sparrowfy_session").delete() }
    }
}

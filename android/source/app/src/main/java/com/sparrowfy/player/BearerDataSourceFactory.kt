package com.sparrowfy.player

import androidx.media3.datasource.DataSource
import androidx.media3.datasource.DefaultHttpDataSource

/** Bearer se lee en cada petición: máster HLS, variantes, segmentos e INIT. */
object BearerDataSourceFactory : DataSource.Factory {
    override fun createDataSource(): DataSource = DefaultHttpDataSource.Factory()
        .setDefaultRequestProperties(mapOf("Authorization" to "Bearer ${TokenStore.token.orEmpty()}"))
        .setAllowCrossProtocolRedirects(true)
        .createDataSource()
}

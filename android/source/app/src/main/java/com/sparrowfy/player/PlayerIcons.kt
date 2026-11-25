package com.sparrowfy.player

import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.StrokeJoin
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.graphics.vector.PathParser
import androidx.compose.ui.unit.dp

/** Copia de los SVG de audio/assets/player-icons.js y player.php (mismo viewBox 24x24). */
object PlayerIcons {
    private fun vector(
        name: String,
        paths: List<Pair<String, Float>>,
        filled: Boolean = false
    ): ImageVector = ImageVector.Builder(
        name = name,
        defaultWidth = 24.dp,
        defaultHeight = 24.dp,
        viewportWidth = 24f,
        viewportHeight = 24f
    ).apply {
        paths.forEach { (data, width) ->
            addPath(
                pathData = PathParser().parsePathString(data).toNodes(),
                fill = if (filled) SolidColor(Color.White) else null,
                stroke = if (filled) null else SolidColor(Color.White),
                strokeLineWidth = width,
                strokeLineCap = StrokeCap.Round,
                strokeLineJoin = StrokeJoin.Round
            )
        }
    }.build()

    val Play = vector("play", listOf("M7 4.5v15l12-7.5z" to 0f), filled = true)
    val Pause = vector("pause", listOf("M6 4h4v16H6zm8 0h4v16h-4z" to 0f), filled = true)
    val Previous = vector(
        "previous",
        listOf("M5 4h3v16H5zm14 0L8 12l11 8z" to 0f),
        filled = true
    )
    val Next = vector(
        "next",
        listOf("M16 4h3v16h-3zM5 4v16l11-8z" to 0f),
        filled = true
    )
    val Restart = vector("restart", listOf("M4 10a8 8 0 1 1 1 8M4 4v6h6" to 2f))
    val Shuffle = vector(
        "shuffle",
        listOf(
            "M3 6h3c5 0 7 12 12 12h3m-4-4 4 4-4 4M3 18h3c2 0 3-2 4-4m4-4c1-2 2-4 4-4h3m-4-4 4 4-4 4" to 2f
        )
    )
    val Minimize = vector(
        "minimize",
        listOf("M8 6l4 4 4-4" to 2.2f, "M5 18h14" to 2.2f)
    )
    val Maximize = vector(
        "maximize",
        listOf(
            "M8 3H3v5m13-5h5v5M3 16v5h5m13-5v5h-5" to 2.4f,
            "M9 9.5h6M9 14.5h6" to 1.8f
        )
    )
    val Close = vector("close", listOf("m6.5 6.5 11 11m0-11-11 11" to 2.6f))
    val Search = vector(
        "search",
        listOf(
            "M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19z" to 0f
        ),
        filled = true
    )
    val DragHandle = vector(
        "drag_handle",
        listOf("M4 7h16M4 12h16M4 17h16" to 2.2f)
    )
    val Compact = vector(
        "compact",
        listOf("M5 8h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2z" to 0f, "M3 5h18M3 19h18" to 2f)
    )
    val Bar = vector(
        "bar",
        listOf("M3 7h18a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2z" to 0f, "M7 10v4m3-4h7" to 2f)
    )
}

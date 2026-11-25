package com.sparrowfy.player

import androidx.compose.material3.ColorScheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

/** Paletas de assets/lab.css y lib/user_preferences.php (9 temas). */
private val palettes: List<ColorScheme> = listOf(
    darkColorScheme( // dark-teal (por defecto en la web)
        primary = Color(0xFF10B99A), onPrimary = Color(0xFF061013),
        primaryContainer = Color(0xFF17484A), onPrimaryContainer = Color(0xFFE8EFEA),
        secondary = Color(0xFFD5B46B), onSecondary = Color(0xFF061013),
        background = Color(0xFF061013), onBackground = Color(0xFFE8EFEA),
        surface = Color(0xFF0E1B20), onSurface = Color(0xFFE8EFEA),
        surfaceVariant = Color(0xFF14272B), onSurfaceVariant = Color(0xFFA9BDB7),
        error = Color(0xFFF87171), onError = Color(0xFF2A0E0E)
    ),
    darkColorScheme( // dark-blue
        primary = Color(0xFF4AA8FF), onPrimary = Color(0xFF07111F),
        primaryContainer = Color(0xFF123A63), onPrimaryContainer = Color(0xFFE6F1FF),
        secondary = Color(0xFF9ECBFF), onSecondary = Color(0xFF07111F),
        background = Color(0xFF07111F), onBackground = Color(0xFFE6F1FF),
        surface = Color(0xFF0F1C2E), onSurface = Color(0xFFE6F1FF),
        surfaceVariant = Color(0xFF16253B), onSurfaceVariant = Color(0xFFAFC4DF)
    ),
    darkColorScheme( // dark-purple
        primary = Color(0xFFB07CFF), onPrimary = Color(0xFF120B1C),
        primaryContainer = Color(0xFF3B2459), onPrimaryContainer = Color(0xFFF0E7FF),
        secondary = Color(0xFFD8B8FF), onSecondary = Color(0xFF120B1C),
        background = Color(0xFF120B1C), onBackground = Color(0xFFF0E7FF),
        surface = Color(0xFF1B1229), onSurface = Color(0xFFF0E7FF),
        surfaceVariant = Color(0xFF261A37), onSurfaceVariant = Color(0xFFC6B5D8)
    ),
    lightColorScheme( // light
        primary = Color(0xFF0F8F79), onPrimary = Color(0xFFFFFFFF),
        primaryContainer = Color(0xFFBFE8DF), onPrimaryContainer = Color(0xFF052B24),
        secondary = Color(0xFF7A5C1E), onSecondary = Color(0xFFFFFFFF),
        background = Color(0xFFF7F7F5), onBackground = Color(0xFF101918),
        surface = Color(0xFFFFFFFF), onSurface = Color(0xFF101918),
        surfaceVariant = Color(0xFFE8ECE9), onSurfaceVariant = Color(0xFF4B5B57)
    ),
    lightColorScheme( // light-sand
        primary = Color(0xFF8A6D2F), onPrimary = Color(0xFFFFFFFF),
        primaryContainer = Color(0xFFEAD9AE), onPrimaryContainer = Color(0xFF2E2208),
        secondary = Color(0xFFB08D4A), onSecondary = Color(0xFF1B1405),
        background = Color(0xFFF5EFE3), onBackground = Color(0xFF241D10),
        surface = Color(0xFFFFFAF0), onSurface = Color(0xFF241D10),
        surfaceVariant = Color(0xFFEDE2CB), onSurfaceVariant = Color(0xFF5C4E33)
    ),
    darkColorScheme( // solarized
        primary = Color(0xFF2AA198), onPrimary = Color(0xFF002B36),
        primaryContainer = Color(0xFF0A4B54), onPrimaryContainer = Color(0xFFD8F3F0),
        secondary = Color(0xFFB58900), onSecondary = Color(0xFF002B36),
        background = Color(0xFF002B36), onBackground = Color(0xFFEEE8D5),
        surface = Color(0xFF073642), onSurface = Color(0xFFEEE8D5),
        surfaceVariant = Color(0xFF0C4A57), onSurfaceVariant = Color(0xFFA8B9B5)
    ),
    darkColorScheme( // monokai
        primary = Color(0xFFA6E22E), onPrimary = Color(0xFF272822),
        primaryContainer = Color(0xFF4B5A1A), onPrimaryContainer = Color(0xFFF4F7E8),
        secondary = Color(0xFFF92672), onSecondary = Color(0xFF272822),
        background = Color(0xFF272822), onBackground = Color(0xFFF8F8F2),
        surface = Color(0xFF34352E), onSurface = Color(0xFFF8F8F2),
        surfaceVariant = Color(0xFF414236), onSurfaceVariant = Color(0xFFCBCBBE)
    ),
    darkColorScheme( // deep-black
        primary = Color(0xFF00E0B8), onPrimary = Color(0xFF000000),
        primaryContainer = Color(0xFF00453A), onPrimaryContainer = Color(0xFFDFFFF7),
        secondary = Color(0xFFD5B46B), onSecondary = Color(0xFF000000),
        background = Color(0xFF000000), onBackground = Color(0xFFF2F2F2),
        surface = Color(0xFF0A0A0A), onSurface = Color(0xFFF2F2F2),
        surfaceVariant = Color(0xFF161616), onSurfaceVariant = Color(0xFFAAAAAA)
    ),
    darkColorScheme( // ruby-blue
        primary = Color(0xFF4F7CFF), onPrimary = Color(0xFF0B1020),
        primaryContainer = Color(0xFF1E3670), onPrimaryContainer = Color(0xFFE7EDFF),
        secondary = Color(0xFFFF5C8A), onSecondary = Color(0xFF0B1020),
        background = Color(0xFF0B1020), onBackground = Color(0xFFE9EEF9),
        surface = Color(0xFF121A33), onSurface = Color(0xFFE9EEF9),
        surfaceVariant = Color(0xFF1B2647), onSurfaceVariant = Color(0xFFAEBBD6)
    )
)

@Composable
fun SparrowTheme(themeIndex: Int = Settings.theme, content: @Composable () -> Unit) {
    MaterialTheme(colorScheme = palettes[themeIndex.coerceIn(0, palettes.lastIndex)], content = content)
}

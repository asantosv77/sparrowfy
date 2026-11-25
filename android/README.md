# Sparrow-Fy Android (nativo)

Proyecto Android nativo de Sparrow-Fy, subido desde el workspace local.

## Contenido

- source/: proyecto Gradle/Kotlin completo (sin uild/, .gradle/, .idea/ ni local.properties).
- pk/app-debug.apk: binario debug listo para instalar y probar.

## APK

- SHA-256: `df0563376643f909fd8459c0192298bc1ab16ad866dd8096b7f4dbb6cf899820`
- Compilado con ssembleDebug.

## CompilaciÃ³n

En Windows, con JDK 17 y Android SDK instalados:

`powershell
$env:JAVA_HOME = 'C:\Program Files\ojdkbuild\java-17-openjdk-17.0.3.0.6-1'
$env:ANDROID_HOME = Join-Path $env:LOCALAPPDATA 'Android\Sdk'
.\gradlew.bat assembleDebug --no-daemon
`

El APK se genera en source/app/build/outputs/apk/debug/app-debug.apk.
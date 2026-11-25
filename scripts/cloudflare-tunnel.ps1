param([ValidateSet('start','url','logs','stop')][string]$Action = 'start')

Set-Location (Split-Path -Parent $PSScriptRoot)
$compose = @('-f','docker-compose.yml','-f','docker-compose.tunnel.yml')
if ($env:OS -eq 'Windows_NT') { $compose += @('-f','docker-compose.windows.yml') }

function Get-TunnelId {
    $id = docker compose @compose ps -q tunnel 2>$null
    if ($LASTEXITCODE -ne 0) { return '' }
    return ($id | Select-Object -First 1)
}
function Get-TunnelUrl {
    $id = Get-TunnelId
    if (-not $id) { return '' }
    $matches = docker logs $id 2>&1 |
        Select-String -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' -AllMatches |
        ForEach-Object { $_.Matches } |
        Select-Object -Last 1 -ExpandProperty Value
    return [string]$matches
}

switch ($Action) {
    'start' {
        Write-Host 'Levantando www y el túnel de Cloudflare...'
        docker compose @compose --profile tunel up -d tunnel
        Write-Host 'Esperando la URL pública (puede tardar hasta 2 minutos)...'
        for ($i = 0; $i -lt 60; $i++) {
            $url = Get-TunnelUrl
            if ($url) {
                Write-Host ''
                Write-Host "URL pública temporal: $url"
                Write-Host 'La URL cambia cada vez que se reinicia el túnel.'
                return
            }
            Start-Sleep -Seconds 2
        }
        Write-Error 'No apareció la URL. Revisa: docker logs (docker compose ... ps -q tunnel)'
        exit 1
    }
    'url' {
        $url = Get-TunnelUrl
        if ($url) { Write-Output $url } else { Write-Error 'Túnel no activo o sin URL todavía.'; exit 1 }
    }
    'logs' {
        $id = Get-TunnelId
        if (-not $id) { Write-Error 'Túnel no activo.'; exit 1 }
        docker logs -f $id
    }
    'stop' {
        Write-Host 'Deteniendo el túnel...'
        docker compose @compose --profile tunel stop tunnel
    }
}

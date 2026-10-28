# Watches a Fire TV while it plays (docs/TV-ORIENTATION-SPEC.md §6): every two seconds the HWC layer table
# of `dumpsys SurfaceFlinger` (which layer is on a hardware overlay, and where), a screencap every three,
# the logcat for the whole window and a screen recording, all into one folder it names at the end.
#
#   .\scripts\tv\capture-fire-tv.ps1 -Ip 192.168.1.71 -Seconds 60
#
# adb: on the PATH, in Android Studio's SDK, or Google's platform-tools beside this file
# (scripts\tv\platform-tools, where TheDisplaySolution-AutoStart.bat downloads it).
param(
    [Parameter(Mandatory = $true)] [string] $Ip,
    [int] $Seconds = 60,
    [int] $Port = 5555
)

$ErrorActionPreference = 'Continue'

$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$adb = (Get-Command adb -ErrorAction SilentlyContinue).Source
if (-not $adb -and (Test-Path "$env:LOCALAPPDATA\Android\Sdk\platform-tools\adb.exe")) { $adb = "$env:LOCALAPPDATA\Android\Sdk\platform-tools\adb.exe" }
if (-not $adb -and (Test-Path "$here\platform-tools\adb.exe")) { $adb = "$here\platform-tools\adb.exe" }
if (-not $adb) { Write-Error 'adb is not on this computer: run TheDisplaySolution-AutoStart.bat once, which downloads it beside this file.'; exit 1 }

& $adb connect "${Ip}:${Port}" | Out-Null
$state = (& $adb -s "${Ip}:${Port}" get-state 2>&1 | Out-String).Trim()
if ($state -ne 'device') { Write-Error "The TV at $Ip is not authorized ($state): turn ADB debugging on and accept the prompt on the TV."; exit 1 }
$target = @('-s', "${Ip}:${Port}")

$out = Join-Path $env:TEMP ("fire-tv-capture\run-" + (Get-Date -Format 'yyyyMMdd-HHmmss'))
New-Item -ItemType Directory -Force $out | Out-Null

& $adb @target logcat -c 2>$null
$logcat = Start-Process -FilePath $adb -ArgumentList ($target + @('logcat', '-v', 'threadtime')) -RedirectStandardOutput (Join-Path $out 'logcat.txt') -NoNewWindow -PassThru
$record = Start-Process -FilePath $adb -ArgumentList ($target + @('shell', 'screenrecord', '--time-limit', "$Seconds", '--bit-rate', '4000000', '/sdcard/tds-rec.mp4')) -NoNewWindow -PassThru

$start = Get-Date
$n = 0
while (((Get-Date) - $start).TotalSeconds -lt $Seconds) {
    $n++
    $t = ((Get-Date) - $start).TotalSeconds.ToString('00.0')
    & $adb @target shell dumpsys SurfaceFlinger 2>$null | Set-Content -Encoding utf8 (Join-Path $out ("sf-{0:D2}-{1}s.txt" -f $n, $t))
    if ($n % 2 -eq 1) {
        & $adb @target shell screencap -p /sdcard/tds-shot.png 2>$null
        & $adb @target pull /sdcard/tds-shot.png (Join-Path $out ("shot-{0:D2}-{1}s.png" -f $n, $t)) 2>$null | Out-Null
    }
    Start-Sleep -Seconds 2
}

Stop-Process -Id $logcat.Id -Force -ErrorAction SilentlyContinue
if (-not $record.HasExited) { $record.WaitForExit(15000) | Out-Null }
Start-Sleep -Seconds 2
& $adb @target pull /sdcard/tds-rec.mp4 (Join-Path $out 'screenrecord.mp4') 2>$null | Out-Null
& $adb @target shell rm /sdcard/tds-rec.mp4 /sdcard/tds-shot.png 2>$null

Write-Output "captured into: $out"
Write-Output ''
Write-Output 'HWC layers per dump (name | composition | display frame | source crop):'
foreach ($file in (Get-ChildItem $out -Filter 'sf-*.txt' | Sort-Object Name)) {
    $lines = Get-Content $file.FullName
    $from = [array]::IndexOf($lines, ($lines | Where-Object { $_ -match 'HWC layers' } | Select-Object -First 1))
    $to = [array]::IndexOf($lines, ($lines | Where-Object { $_ -match 'h/w composer state' } | Select-Object -First 1))
    $rows = if ($from -ge 0 -and $to -gt $from) { ($lines[($from + 5)..($to - 2)] | Where-Object { $_ -match '\S' -and $_ -notmatch '^- - ' }) -join ' || ' } else { '(no table)' }
    Write-Output ("{0}: {1}" -f $file.Name, $rows)
}

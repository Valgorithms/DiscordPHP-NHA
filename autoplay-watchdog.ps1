<#
.SYNOPSIS
    Keeps the headless NHA autoplay runner alive.

.DESCRIPTION
    Starts the compiled runner (bin\build\autoplay\windows\windows-x64.exe) when it
    is not running, and restarts it when its log has been silent for -StaleMinutes.
    The runner writes a line every turn (15 s by default), even when a turn fails or
    is skipped, so a silent log means it is hung.

    Live, on 2026-09-28 at 14:53, Windows closed the runner as "stopped interacting
    with Windows" (Application Hang, event 1002), and nothing started it again for
    six hours.

    Before each start the old var\autoplay.log is appended to var\autoplay.prev.log:
    the runner's output is the only record of why it did what it did, and the start
    truncates it. What the watchdog does goes to var\watchdog.log.

    While var\watchdog.pause exists the watchdog does nothing. Create it before
    stopping the runner to rebuild it (composer phpacker), and delete it after.

.PARAMETER StaleMinutes
    How long the log may stay silent before the runner counts as hung.

.PARAMETER EveryMinutes
    With -Install, how often Task Scheduler runs the watchdog.

.PARAMETER Install
    Registers the watchdog in Task Scheduler for the current user: at logon, and
    every -EveryMinutes minutes. It runs under a headless console, so no window
    flashes over whatever is in the foreground.

.PARAMETER Uninstall
    Removes that task.

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File autoplay-watchdog.ps1 -Install

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File autoplay-watchdog.ps1
    One check now: start the runner if it is down, restart it if it is hung.

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File autoplay-watchdog.ps1 -StaleMinutes 0
    Restart the runner now, keeping its log.

.NOTES
    To stop the runner for good, create var\watchdog.pause (or run -Uninstall)
    before stopping it. To rebuild it: create the pause file, stop the runner,
    wait ~5 s, composer phpacker, delete the pause file, run this script once.
    README.md, "Operating the runner while the watchdog is installed".
#>
[CmdletBinding()]
param(
    [int] $StaleMinutes = 10,
    [int] $EveryMinutes = 5,
    [switch] $Install,
    [switch] $Uninstall
)

$ErrorActionPreference = 'Stop'
$TaskName = 'NHA autoplay watchdog'
$Root = $PSScriptRoot
$Exe = Join-Path $Root 'bin\build\autoplay\windows\windows-x64.exe'
$Var = Join-Path $Root 'var'
$Log = Join-Path $Var 'autoplay.log'
$ErrLog = Join-Path $Var 'autoplay.err.log'
$PrevLog = Join-Path $Var 'autoplay.prev.log'
$Pause = Join-Path $Var 'watchdog.pause'
$WatchLog = Join-Path $Var 'watchdog.log'

function Write-Watch([string] $Message) {
    Add-Content -LiteralPath $WatchLog -Value ('[{0:yyyy-MM-dd HH:mm:ss}] {1}' -f (Get-Date), $Message) -Encoding UTF8
}

if ($Install) {
    # conhost --headless gives PowerShell a console that is never shown, so a
    # run every few minutes cannot steal focus from a full-screen window.
    $argument = "--headless powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File `"$PSCommandPath`" -StaleMinutes $StaleMinutes"
    $action = New-ScheduledTaskAction -Execute 'conhost.exe' -Argument $argument -WorkingDirectory $Root
    $every = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes $EveryMinutes)
    $logon = New-ScheduledTaskTrigger -AtLogOn -User "$env:USERDOMAIN\$env:USERNAME"
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
        -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 2)
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $every, $logon -Settings $settings `
        -Description 'Keeps the DiscordPHP-NHA autoplay runner alive (autoplay-watchdog.ps1).' -Force | Out-Null
    "Registered '$TaskName': at logon and every $EveryMinutes minutes."
    return
}
if ($Uninstall) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
    "Removed '$TaskName'."
    return
}

if (Test-Path -LiteralPath $Pause) {
    return
}
if (-not (Test-Path -LiteralPath $Exe)) {
    Write-Watch "no runner at $Exe - build it with composer phpacker"
    return
}

# By its full path: sibling projects run their own windows-x64.exe.
$running = @(Get-CimInstance Win32_Process -Filter "Name = 'windows-x64.exe'" | Where-Object { $_.ExecutablePath -eq $Exe })
if ($running.Count -gt 0) {
    # A start truncates the log, so a runner that has just started is not stale.
    $quiet = if (Test-Path -LiteralPath $Log) { (Get-Date) - (Get-Item -LiteralPath $Log).LastWriteTime } else { [TimeSpan]::Zero }
    if ($quiet.TotalMinutes -lt $StaleMinutes) {
        return
    }
    Write-Watch ('runner {0} silent for {1:N0} min - restarting it' -f (($running | ForEach-Object { $_.ProcessId }) -join ', '), $quiet.TotalMinutes)
    $running | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    # The exe's file lock outlives the process for a few seconds.
    Start-Sleep -Seconds 6
} else {
    Write-Watch 'runner not running - starting it'
}

if (Test-Path -LiteralPath $Log) {
    # Byte for byte, like `cat autoplay.log >> autoplay.prev.log`.
    $bytes = [System.IO.File]::ReadAllBytes($Log)
    $prev = [System.IO.File]::Open($PrevLog, [System.IO.FileMode]::Append, [System.IO.FileAccess]::Write, [System.IO.FileShare]::Read)
    try {
        $prev.Write($bytes, 0, $bytes.Length)
    } finally {
        $prev.Dispose()
    }
}

$process = Start-Process -FilePath $Exe -WorkingDirectory $Root -WindowStyle Hidden `
    -RedirectStandardOutput $Log -RedirectStandardError $ErrLog -PassThru
Write-Watch "started runner $($process.Id)"

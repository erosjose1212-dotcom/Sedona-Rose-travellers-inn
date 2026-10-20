[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][ValidateSet('Install', 'Start', 'Stop', 'Status')][string]$Action,
    [string]$ElevatedLog
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'Service-Control.ps1')
$roseProject = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$roseLogDirectory = Join-Path $roseProject 'storage\logs'

try {
    if ($ElevatedLog) {
        $roseLogFullPath = [IO.Path]::GetFullPath($ElevatedLog)
        if ((Split-Path -Parent $roseLogFullPath) -ine $roseLogDirectory -or
            (Split-Path -Leaf $roseLogFullPath) -notmatch '^rose-control-[a-f0-9]{32}\.log$') {
            throw 'The service-control log path is invalid.'
        }
        if (-not (Test-SedonaRoseAdministrator)) {
            throw 'Administrator permission was not granted.'
        }
    }

    if ($Action -ne 'Status' -and -not (Test-SedonaRoseAdministrator)) {
        New-Item -ItemType Directory -Path $roseLogDirectory -Force | Out-Null
        $roseLogPath = Join-Path $roseLogDirectory ('rose-control-' + [Guid]::NewGuid().ToString('N') + '.log')
        Write-Output 'Please approve the Windows administrator permission prompt.'
        $roseLaunchArguments = '-NoProfile -ExecutionPolicy Bypass -File "' + $PSCommandPath + '" -Action ' + $Action + ' -ElevatedLog "' + $roseLogPath + '"'
        try {
            $rosePowerShell = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
            $roseProcess = Start-Process -FilePath $rosePowerShell -Verb RunAs -WindowStyle Hidden `
                -ArgumentList $roseLaunchArguments -Wait -PassThru -ErrorAction Stop
        } catch {
            throw 'Windows did not grant administrator permission. Approve the prompt, or right-click the BAT/CMD file and choose Run as administrator.'
        }
        if (Test-Path -LiteralPath $roseLogPath) {
            Get-Content -LiteralPath $roseLogPath
        } else {
            Write-Output 'No operation log was created. If the administrator prompt was cancelled, run the file again and approve it.'
        }
        exit $roseProcess.ExitCode
    }

    $roseOperation = {
        if ($Action -eq 'Install') {
            & (Join-Path $PSScriptRoot 'Install-SedonaRose.ps1') -LanIp '192.168.0.106' -InstallService -AllowLanFirewall
        } else {
            Invoke-SedonaRoseControl -Action $Action
        }
    }
    if ($ElevatedLog) {
        & $roseOperation *>&1 | Tee-Object -FilePath $ElevatedLog
    } else {
        & $roseOperation
    }
    exit 0
} catch {
    $roseErrorMessage = 'ERROR: ' + $_.Exception.Message
    if ($ElevatedLog -and $roseLogFullPath -and (Split-Path -Parent $roseLogFullPath) -ieq $roseLogDirectory -and
        (Split-Path -Leaf $roseLogFullPath) -match '^rose-control-[a-f0-9]{32}\.log$') {
        $roseErrorMessage | Out-File -LiteralPath $roseLogFullPath -Append -Encoding utf8
    }
    Write-Output $roseErrorMessage
    exit 1
}

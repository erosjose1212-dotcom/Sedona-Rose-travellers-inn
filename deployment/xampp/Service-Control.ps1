function Test-SedonaRoseAdministrator {
    $roseIdentity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $rosePrincipal = New-Object Security.Principal.WindowsPrincipal($roseIdentity)
    return $rosePrincipal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Invoke-SedonaRoseNativeCommand {
    param(
        [string]$Executable,
        [string[]]$Arguments,
        [string]$FailureMessage
    )

    if (-not (Test-Path -LiteralPath $Executable -PathType Leaf)) {
        throw ($FailureMessage + '. Executable missing: ' + $Executable)
    }
    $rosePreviousErrorPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $roseNativeOutput = & $Executable @Arguments 2>&1
        $roseNativeExit = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $rosePreviousErrorPreference
    }
    foreach ($roseNativeLine in $roseNativeOutput) {
        Write-Output $roseNativeLine.ToString()
    }
    if ($roseNativeExit -ne 0) {
        throw ($FailureMessage + ' (exit code ' + $roseNativeExit + ').')
    }
}

function Get-SedonaRosePort80Conflict {
    param([string]$LanIp)

    $roseListeners = @(Get-NetTCPConnection -State Listen -LocalPort 80 -ErrorAction SilentlyContinue |
        Where-Object { $_.LocalAddress -in @($LanIp, '0.0.0.0', '::') })
    if ($roseListeners.Count -eq 0) {
        return
    }
    $roseOwners = foreach ($roseOwnerId in ($roseListeners.OwningProcess | Sort-Object -Unique)) {
        $roseProcess = Get-CimInstance -ClassName Win32_Process -Filter "ProcessId=$roseOwnerId" -ErrorAction SilentlyContinue
        $roseOwner = 'PID ' + $roseOwnerId
        if ($roseProcess) {
            $roseOwner = $roseProcess.Name + ' (' + $roseOwner + ')'
        }
        $roseServices = @(Get-CimInstance -ClassName Win32_Service -Filter "ProcessId=$roseOwnerId" -ErrorAction SilentlyContinue)
        if ($roseServices.Count -gt 0) {
            $roseOwner += '; service: ' + (($roseServices | Select-Object -ExpandProperty Name) -join ', ')
        }
        $roseOwner
    }
    return ('Port 80 is already in use by: ' + ($roseOwners -join '; ') + '. ' +
        'If this is the unused XAMPP Apache on the Rose server, click Stop beside Apache in XAMPP Control Panel, then retry. ' +
        'Otherwise identify the listed program before stopping it. No other application was stopped.')
}

function Invoke-SedonaRoseControl {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][ValidateSet('Start', 'Stop', 'Status')][string]$Action)

    $roseProject = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
    $roseExpectedHttpd = Join-Path $PSScriptRoot 'portable\apache\bin\httpd.exe'
    $roseServiceName = 'SedonaRoseApache'
    $roseLoginUrl = 'http://192.168.0.106/login'
    $roseDetails = Get-CimInstance -ClassName Win32_Service -Filter "Name='$roseServiceName'" -ErrorAction Stop
    if (-not $roseDetails) {
        throw 'Sedona Rose is not installed as a service yet. Run deployment\xampp\INSTALL-ON-192.168.0.106.cmd once on the Rose server.'
    }
    if ($roseDetails.PathName -notmatch '^(?:"(?<exe>[^"]+)"|(?<exe>\S+))' -or
        [IO.Path]::GetFullPath($Matches.exe) -ine [IO.Path]::GetFullPath($roseExpectedHttpd)) {
        throw 'SedonaRoseApache points to another installation. No service was changed. Use the Start/Stop files from its installed Rose folder.'
    }

    $roseService = Get-Service -Name $roseServiceName -ErrorAction Stop
    if ($Action -eq 'Status') {
        Write-Output ("Sedona Rose: {0}. Login: {1}" -f $roseService.Status, $roseLoginUrl)
        return
    }
    if (-not (Test-SedonaRoseAdministrator)) {
        throw 'Administrator permission is required to start or stop Sedona Rose.'
    }

    $roseTimeout = [TimeSpan]::FromSeconds(30)
    if ($Action -eq 'Stop') {
        if ($roseService.Status -eq 'Stopped') {
            Write-Output 'Sedona Rose is already stopped.'
            return
        }
        if ($roseService.Status -eq 'StartPending') {
            $roseService.WaitForStatus('Running', $roseTimeout)
            $roseService.Refresh()
        }
        if ($roseService.Status -ne 'StopPending') {
            Stop-Service -Name $roseServiceName -ErrorAction Stop
        }
        $roseService.WaitForStatus('Stopped', $roseTimeout)
        Write-Output 'Sedona Rose stopped. Guest records, stock, and payments are unchanged.'
        return
    }

    if (-not (Get-NetIPAddress -AddressFamily IPv4 -IPAddress '192.168.0.106' -ErrorAction SilentlyContinue)) {
        throw 'This computer does not have 192.168.0.106 assigned. Start the system on the Rose server; network settings were not changed.'
    }
    if ($roseService.Status -eq 'StopPending') {
        $roseService.WaitForStatus('Stopped', $roseTimeout)
        $roseService.Refresh()
    }
    if ($roseService.Status -eq 'Paused') {
        Resume-Service -Name $roseServiceName -ErrorAction Stop
    } elseif ($roseService.Status -notin @('Running', 'StartPending')) {
        Start-Service -Name $roseServiceName -ErrorAction Stop
    }
    $roseService.WaitForStatus('Running', $roseTimeout)

    $roseHealthy = $false
    for ($roseAttempt = 1; $roseAttempt -le 3; $roseAttempt++) {
        try {
            $roseResponse = Invoke-WebRequest -UseBasicParsing -Uri $roseLoginUrl -TimeoutSec 5 -ErrorAction Stop
            if ($roseResponse.StatusCode -eq 200 -and $roseResponse.Content -match 'SEDONA ROSE') {
                $roseHealthy = $true
                break
            }
        } catch {
            $roseHealthy = $false
        }
        if ($roseAttempt -lt 3) { Start-Sleep -Seconds 1 }
    }
    if (-not $roseHealthy) {
        throw "The Rose service is running, but its login page could not be verified. Check $roseProject\deployment\xampp\portable\apache\logs\sedona-rose-error.log."
    }
    Write-Output "Sedona Rose is running. Open $roseLoginUrl"
}

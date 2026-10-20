. (Join-Path $PSScriptRoot '..\deployment\xampp\Service-Control.ps1')

Describe 'Sedona Rose native command logging' {
    It 'fails clearly if an executable is missing' {
        { Invoke-SedonaRoseNativeCommand -Executable (Join-Path $TestDrive 'missing.exe') -Arguments @() `
            -FailureMessage 'Runtime check failed' } | Should Throw 'Executable missing'
    }

    It 'accepts diagnostic stderr when the command succeeds under captured output' {
        $rosePowerShell = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
        $rosePreviousPreference = $ErrorActionPreference
        try {
            $ErrorActionPreference = 'Stop'
            $roseOutput = & {
                Invoke-SedonaRoseNativeCommand -Executable $rosePowerShell -Arguments @('-NoProfile', '-Command',
                    "[Console]::Out.WriteLine('Runtime OK'); [Console]::Error.WriteLine('Syntax OK'); exit 0") -FailureMessage 'Unexpected command failure'
            } *>&1
        } finally {
            $ErrorActionPreference = $rosePreviousPreference
        }

        ($roseOutput -join "`n") | Should Match 'Runtime OK'
        ($roseOutput -join "`n") | Should Match 'Syntax OK'
    }

    It 'fails when the native command returns a nonzero exit code' {
        $rosePowerShell = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'

        { Invoke-SedonaRoseNativeCommand -Executable $rosePowerShell -Arguments @('-NoProfile', '-Command', 'exit 7') `
            -FailureMessage 'Apache validation failed' } | Should Throw 'Apache validation failed (exit code 7)'
    }
}

function New-RoseServiceDouble {
    param([string]$Status)

    $roseDouble = [pscustomobject]@{ Status = $Status }
    $roseDouble | Add-Member -MemberType ScriptMethod -Name Refresh -Value {}
    $roseDouble | Add-Member -MemberType ScriptMethod -Name WaitForStatus -Value {
        param($Target, $Timeout)
        $this.Status = $Target
    }
    return $roseDouble
}

Describe 'Sedona Rose port conflict diagnostics' {
    BeforeEach {
        Mock Get-NetTCPConnection { @() }
        Mock Get-CimInstance {
            if ($ClassName -eq 'Win32_Process') {
                return [pscustomobject]@{ Name = 'httpd.exe' }
            }
            return [pscustomobject]@{ Name = 'Apache2.4' }
        }
        Mock Stop-Service {}
        Mock Stop-Process {}
    }

    It 'allows installation when port 80 is free' {
        Get-SedonaRosePort80Conflict -LanIp '192.168.0.106' | Should BeNullOrEmpty

        Assert-MockCalled -Scope It Get-CimInstance -Times 0 -Exactly
    }

    It 'names the process and service without stopping either' {
        Mock Get-NetTCPConnection { [pscustomobject]@{ LocalAddress = '0.0.0.0'; OwningProcess = 4321 } }

        $roseConflict = Get-SedonaRosePort80Conflict -LanIp '192.168.0.106'

        $roseConflict | Should Match 'httpd.exe \(PID 4321\); service: Apache2.4'
        Assert-MockCalled -Scope It Stop-Service -Times 0 -Exactly
        Assert-MockCalled -Scope It Stop-Process -Times 0 -Exactly
    }

    It 'detects a listener bound directly to the Rose IP' {
        Mock Get-NetTCPConnection { [pscustomobject]@{ LocalAddress = '192.168.0.106'; OwningProcess = 4321 } }

        Get-SedonaRosePort80Conflict -LanIp '192.168.0.106' | Should Match 'PID 4321'
    }

    It 'detects the IPv6 wildcard listener' {
        Mock Get-NetTCPConnection { [pscustomobject]@{ LocalAddress = '::'; OwningProcess = 4321 } }

        Get-SedonaRosePort80Conflict -LanIp '192.168.0.106' | Should Match 'PID 4321'
    }

    It 'ignores port 80 bound only to another IP' {
        Mock Get-NetTCPConnection { [pscustomobject]@{ LocalAddress = '192.168.0.103'; OwningProcess = 4321 } }

        Get-SedonaRosePort80Conflict -LanIp '192.168.0.106' | Should BeNullOrEmpty

        Assert-MockCalled -Scope It Get-CimInstance -Times 0 -Exactly
    }

    It 'still reports the PID when process details are unavailable' {
        Mock Get-NetTCPConnection { [pscustomobject]@{ LocalAddress = '0.0.0.0'; OwningProcess = 4321 } }
        Mock Get-CimInstance { $null }

        Get-SedonaRosePort80Conflict -LanIp '192.168.0.106' | Should Match 'in use by: PID 4321'
    }
}

Describe 'Sedona Rose service controls' {
    BeforeEach {
        $script:roseHttpd = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\deployment\xampp\portable\apache\bin\httpd.exe'))
        Mock Get-CimInstance { [pscustomobject]@{ PathName = '"' + $script:roseHttpd + '" -k runservice -n SedonaRoseApache' } }
        Mock Get-Service { $script:roseServiceDouble }
        Mock Test-SedonaRoseAdministrator { $true }
        Mock Get-NetIPAddress { [pscustomobject]@{ IPAddress = '192.168.0.106' } }
        Mock Invoke-WebRequest { [pscustomobject]@{ StatusCode = 200; Content = '<title>SEDONA ROSE TRAVELLERS INN</title>' } }
        Mock Start-Service { $script:roseServiceDouble.Status = 'Running' }
        Mock Stop-Service { $script:roseServiceDouble.Status = 'Stopped' }
        Mock Resume-Service { $script:roseServiceDouble.Status = 'Running' }
        Mock Start-Sleep {}
    }

    It 'requires installation before starting' {
        Mock Get-CimInstance { $null }

        { Invoke-SedonaRoseControl -Action Start } | Should Throw 'not installed as a service yet'

        Assert-MockCalled -Scope It Start-Service -Times 0 -Exactly
        Assert-MockCalled -Scope It Stop-Service -Times 0 -Exactly
    }

    It 'refuses to control a service belonging to another folder' {
        Mock Get-CimInstance { [pscustomobject]@{ PathName = '"C:\xampp\apache\bin\httpd.exe" -k runservice' } }

        { Invoke-SedonaRoseControl -Action Stop } | Should Throw 'points to another installation'

        Assert-MockCalled -Scope It Start-Service -Times 0 -Exactly
        Assert-MockCalled -Scope It Stop-Service -Times 0 -Exactly
    }

    It 'refuses service changes without administrator permission' {
        $script:roseServiceDouble = New-RoseServiceDouble -Status 'Stopped'
        Mock Test-SedonaRoseAdministrator { $false }

        { Invoke-SedonaRoseControl -Action Start } | Should Throw 'Administrator permission is required'

        Assert-MockCalled -Scope It Start-Service -Times 0 -Exactly
        Assert-MockCalled -Scope It Stop-Service -Times 0 -Exactly
    }

    It 'starts only the Rose service and verifies the Rose login page' {
        $script:roseServiceDouble = New-RoseServiceDouble -Status 'Stopped'

        Invoke-SedonaRoseControl -Action Start | Should Match 'Sedona Rose is running'

        $script:roseServiceDouble.Status | Should Be 'Running'
        Assert-MockCalled -Scope It Start-Service -Times 1 -Exactly -ParameterFilter { $Name -eq 'SedonaRoseApache' }
        Assert-MockCalled -Scope It Invoke-WebRequest -Times 1 -Exactly -ParameterFilter { $Uri -eq 'http://192.168.0.106/login' }
        Assert-MockCalled -Scope It Stop-Service -Times 0 -Exactly
    }

    It 'does not start a second instance when already running' {
        $script:roseServiceDouble = New-RoseServiceDouble -Status 'Running'

        Invoke-SedonaRoseControl -Action Start | Should Match 'Sedona Rose is running'

        Assert-MockCalled -Scope It Start-Service -Times 0 -Exactly
        Assert-MockCalled -Scope It Invoke-WebRequest -Times 1 -Exactly
    }

    It 'resumes a paused Rose service' {
        $script:roseServiceDouble = New-RoseServiceDouble -Status 'Paused'

        Invoke-SedonaRoseControl -Action Start | Should Match 'Sedona Rose is running'

        Assert-MockCalled -Scope It Resume-Service -Times 1 -Exactly -ParameterFilter { $Name -eq 'SedonaRoseApache' }
        Assert-MockCalled -Scope It Start-Service -Times 0 -Exactly
    }

    It 'waits for an in-progress start without issuing another start' {
        $script:roseServiceDouble = New-RoseServiceDouble -Status 'StartPending'

        Invoke-SedonaRoseControl -Action Start | Should Match 'Sedona Rose is running'

        $script:roseServiceDouble.Status | Should Be 'Running'
        Assert-MockCalled -Scope It Start-Service -Times 0 -Exactly
    }

    It 'stops only Rose without requesting a web page' {
        $script:roseServiceDouble = New-RoseServiceDouble -Status 'Running'

        Invoke-SedonaRoseControl -Action Stop | Should Match 'Sedona Rose stopped'

        $script:roseServiceDouble.Status | Should Be 'Stopped'
        Assert-MockCalled -Scope It Stop-Service -Times 1 -Exactly -ParameterFilter { $Name -eq 'SedonaRoseApache' }
        Assert-MockCalled -Scope It Start-Service -Times 0 -Exactly
        Assert-MockCalled -Scope It Invoke-WebRequest -Times 0 -Exactly
    }

    It 'safely repeats Stop when already stopped' {
        $script:roseServiceDouble = New-RoseServiceDouble -Status 'Stopped'

        Invoke-SedonaRoseControl -Action Stop | Should Match 'already stopped'

        Assert-MockCalled -Scope It Stop-Service -Times 0 -Exactly
    }

    It 'waits for an in-progress stop without issuing another stop' {
        $script:roseServiceDouble = New-RoseServiceDouble -Status 'StopPending'

        Invoke-SedonaRoseControl -Action Stop | Should Match 'Sedona Rose stopped'

        $script:roseServiceDouble.Status | Should Be 'Stopped'
        Assert-MockCalled -Scope It Stop-Service -Times 0 -Exactly
    }

    It 'shows status without administrator permission or service changes' {
        $script:roseServiceDouble = New-RoseServiceDouble -Status 'Running'
        Mock Test-SedonaRoseAdministrator { $false }

        Invoke-SedonaRoseControl -Action Status | Should Match 'Sedona Rose: Running'

        Assert-MockCalled -Scope It Start-Service -Times 0 -Exactly
        Assert-MockCalled -Scope It Stop-Service -Times 0 -Exactly
        Assert-MockCalled -Scope It Invoke-WebRequest -Times 0 -Exactly
    }

    It 'refuses to start on a computer without the Rose LAN address' {
        $script:roseServiceDouble = New-RoseServiceDouble -Status 'Stopped'
        Mock Get-NetIPAddress { $null }

        { Invoke-SedonaRoseControl -Action Start } | Should Throw 'does not have 192.168.0.106 assigned'

        Assert-MockCalled -Scope It Start-Service -Times 0 -Exactly
    }

    It 'reports an unhealthy login without stopping another service' {
        $script:roseServiceDouble = New-RoseServiceDouble -Status 'Running'
        Mock Invoke-WebRequest { throw 'Connection failed' }

        { Invoke-SedonaRoseControl -Action Start } | Should Throw 'login page could not be verified'

        Assert-MockCalled -Scope It Invoke-WebRequest -Times 3 -Exactly
        Assert-MockCalled -Scope It Start-Sleep -Times 2 -Exactly
        Assert-MockCalled -Scope It Start-Service -Times 0 -Exactly
        Assert-MockCalled -Scope It Stop-Service -Times 0 -Exactly
    }

    It 'rejects a successful response from a different property' {
        $script:roseServiceDouble = New-RoseServiceDouble -Status 'Running'
        Mock Invoke-WebRequest { [pscustomobject]@{ StatusCode = 200; Content = '<title>Another hotel</title>' } }

        { Invoke-SedonaRoseControl -Action Start } | Should Throw 'login page could not be verified'

        Assert-MockCalled -Scope It Invoke-WebRequest -Times 3 -Exactly
    }
}

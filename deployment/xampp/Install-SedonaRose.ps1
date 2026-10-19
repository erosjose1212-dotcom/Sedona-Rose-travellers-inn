[CmdletBinding()]
param(
    [string]$LanIp = '192.168.0.106',
    [switch]$ValidateOnly,
    [switch]$InstallService,
    [switch]$AllowLanFirewall
)

$ErrorActionPreference = 'Stop'
$roseProject = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$roseApacheRoot = Join-Path $PSScriptRoot 'portable\apache'
$rosePhpRoot = Join-Path $PSScriptRoot 'portable\php'
$roseHttpd = Join-Path $roseApacheRoot 'bin\httpd.exe'
$rosePhp = Join-Path $rosePhpRoot 'php.exe'
$roseApacheConfig = Join-Path $roseApacheRoot 'conf\httpd.conf'
$rosePhpConfig = Join-Path $rosePhpRoot 'php.ini'
$roseDatabase = Join-Path $roseProject 'database\database.sqlite'
$roseServiceName = 'SedonaRoseApache'
$roseEncoding = New-Object Text.UTF8Encoding($false)

if ($ValidateOnly -and ($InstallService -or $AllowLanFirewall)) {
    throw 'ValidateOnly cannot install services or firewall rules.'
}
if ($LanIp -notmatch '^192\.168\.0\.(?:[1-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-4])$') {
    throw 'Use the assigned server address on the 192.168.0.x local subnet.'
}
foreach ($roseRequired in @($roseHttpd, $rosePhp, $roseDatabase, (Join-Path $roseProject 'artisan'),
    (Join-Path $roseProject 'vendor\autoload.php'), (Join-Path $roseProject 'public\build\manifest.json'))) {
    if (-not (Test-Path -LiteralPath $roseRequired -PathType Leaf)) {
        throw "Required file missing: $roseRequired. Copy the complete Rose folder, including hidden files and portable runtimes."
    }
}
$rosePropertyConfig = [IO.File]::ReadAllText((Join-Path $roseProject 'config\sedona.php'))
if (-not $rosePropertyConfig.Contains("'property_code' => env('SEDONA_PROPERTY', 'rose')")) {
    throw 'This installer requires the Sedona Rose copy. It must not configure a Court project.'
}

if (-not $ValidateOnly) {
    if (-not (Get-NetIPAddress -AddressFamily IPv4 -IPAddress $LanIp -ErrorAction SilentlyContinue)) {
        throw "This computer does not own $LanIp. Run installation on the assigned Rose server. No network address will be changed automatically."
    }
    if ($InstallService -or $AllowLanFirewall) {
        $roseIdentity = [Security.Principal.WindowsIdentity]::GetCurrent()
        $rosePrincipal = New-Object Security.Principal.WindowsPrincipal($roseIdentity)
        if (-not $rosePrincipal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
            throw 'Service/firewall installation requires an elevated PowerShell terminal.'
        }
    }
}

foreach ($roseDirectory in @((Join-Path $roseApacheRoot 'logs'), (Join-Path $roseApacheRoot 'conf'),
    (Join-Path $roseProject 'storage\logs'), (Join-Path $roseProject 'storage\framework\sessions'),
    (Join-Path $roseProject 'storage\framework\cache\data'), (Join-Path $roseProject 'storage\framework\views'),
    (Join-Path $roseProject 'storage\app\private\tmp'))) {
    New-Item -ItemType Directory -Path $roseDirectory -Force | Out-Null
}
$roseReplacements = @{
    '@PROJECT_ROOT@' = $roseProject.Replace('\', '/')
    '@APACHE_ROOT@' = $roseApacheRoot.Replace('\', '/')
    '@PHP_ROOT@' = $rosePhpRoot.Replace('\', '/')
    '@LAN_IP@' = $LanIp
}
foreach ($roseTemplatePair in @(
    @('httpd-rose.conf.template', $roseApacheConfig),
    @('php-rose.ini.template', $rosePhpConfig)
)) {
    $roseContent = [IO.File]::ReadAllText((Join-Path $PSScriptRoot $roseTemplatePair[0]))
    foreach ($roseToken in $roseReplacements.Keys) {
        $roseContent = $roseContent.Replace($roseToken, $roseReplacements[$roseToken])
    }
    [IO.File]::WriteAllText($roseTemplatePair[1], $roseContent, $roseEncoding)
}

& $rosePhp -c $rosePhpConfig -r 'if (PHP_VERSION_ID < 80300 || !extension_loaded("pdo_sqlite") || !extension_loaded("sqlite3")) { fwrite(STDERR, "PHP 8.3+ with SQLite is required.\n"); exit(1); } echo "PHP ", PHP_VERSION, " with SQLite: OK\n";'
if ($LASTEXITCODE -ne 0) { throw 'The bundled PHP runtime failed its compatibility check.' }
& $roseHttpd -f $roseApacheConfig -t
if ($LASTEXITCODE -ne 0) { throw 'Apache configuration validation failed.' }
if ($ValidateOnly) {
    Write-Output "Validation passed for http://$LanIp. Environment, database, services, firewall and network settings were not changed."
    return
}

$roseExistingService = Get-Service -Name $roseServiceName -ErrorAction SilentlyContinue
if ($roseExistingService) {
    $roseServiceDetails = Get-CimInstance Win32_Service -Filter "Name='$roseServiceName'"
    if ($roseServiceDetails.PathName -notlike ('*' + $roseHttpd + '*')) {
        throw 'An existing Rose service points elsewhere. Review its installation path before proceeding.'
    }
    if ($roseExistingService.Status -eq 'Running') {
        if (-not $InstallService) { throw 'Stop the Rose service before setup, or rerun with InstallService to restart it safely.' }
        Stop-Service -Name $roseServiceName
    }
}
if ($InstallService -and (Get-NetTCPConnection -State Listen -LocalPort 80 -ErrorAction SilentlyContinue |
    Where-Object { $_.LocalAddress -in @($LanIp, '0.0.0.0', '::') })) {
    throw 'Port 80 is already in use. Identify the server using it and resolve the conflict; this script does not stop other applications.'
}

$roseEnvPath = Join-Path $roseProject '.env'
if (-not (Test-Path -LiteralPath $roseEnvPath)) {
    Copy-Item -LiteralPath (Join-Path $roseProject '.env.example') -Destination $roseEnvPath
}
$roseStamp = Get-Date -Format 'yyyyMMdd-HHmmss-fff'
Copy-Item -LiteralPath $roseEnvPath -Destination (Join-Path $roseProject ('.env.before-lan-' + $roseStamp))
$roseEnvContent = [IO.File]::ReadAllText($roseEnvPath)
$roseEnvSettings = [ordered]@{
    APP_NAME = '"Sedona Rose Travellers Inn"'
    APP_ENV = 'production'
    APP_DEBUG = 'false'
    APP_URL = "http://$LanIp"
    APP_TIMEZONE = 'Asia/Manila'
    DB_CONNECTION = 'sqlite'
    DB_DATABASE = '"' + $roseDatabase.Replace('\', '/') + '"'
    SEDONA_HOTEL_NAME = '"SEDONA ROSE TRAVELLERS INN"'
    SEDONA_HOTEL_ADDRESS = '"B.S. Aquino Avenue, St, San Roque Hinukay, Baliwag, Bulacan"'
    SEDONA_PROPERTY = 'rose'
    SEDONA_CONTINUOUS_SHIFT_ENABLED = 'true'
    SESSION_COOKIE = 'sedona_rose_session'
    SESSION_SECURE_COOKIE = 'false'
    CACHE_PREFIX = 'sedona_rose_'
}
foreach ($roseEnvKey in $roseEnvSettings.Keys) {
    $rosePattern = '(?m)^' + [regex]::Escape($roseEnvKey) + '=.*$'
    $roseLine = $roseEnvKey + '=' + $roseEnvSettings[$roseEnvKey]
    if ([regex]::IsMatch($roseEnvContent, $rosePattern)) {
        $roseEnvContent = [regex]::Replace($roseEnvContent, $rosePattern, [Text.RegularExpressions.MatchEvaluator]{ param($match) $roseLine })
    } else {
        $roseEnvContent += "`n$roseLine`n"
    }
}
[IO.File]::WriteAllText($roseEnvPath, $roseEnvContent, $roseEncoding)

Push-Location -LiteralPath $roseProject
try {
    if ($roseEnvContent -notmatch '(?m)^APP_KEY=\S.+') {
        & $rosePhp -c $rosePhpConfig artisan key:generate --force --no-interaction
        if ($LASTEXITCODE -ne 0) { throw 'Application key creation failed.' }
    }
    $roseBackup = Join-Path $roseProject ('database\before-lan-install-' + $roseStamp + '.sqlite')
    & $rosePhp -c $rosePhpConfig -r '$src = new SQLite3($argv[1], SQLITE3_OPEN_READONLY); $dst = new SQLite3($argv[2]); if (!$src->backup($dst)) { exit(1); }' -- $roseDatabase $roseBackup
    if ($LASTEXITCODE -ne 0) { throw 'Consistent SQLite backup failed.' }
    foreach ($roseArtisanArgs in @(
        @('optimize:clear', '--no-interaction'),
        @('migrate', '--force', '--no-interaction'),
        @('config:cache', '--no-interaction'),
        @('view:cache', '--no-interaction')
    )) {
        & $rosePhp -c $rosePhpConfig artisan @roseArtisanArgs
        if ($LASTEXITCODE -ne 0) { throw ('Artisan failed: ' + ($roseArtisanArgs -join ' ')) }
    }
} finally {
    Pop-Location
}

if ($AllowLanFirewall) {
    $roseRule = Get-NetFirewallRule -Name 'SedonaRoseHttp' -ErrorAction SilentlyContinue
    if (-not $roseRule) {
        New-NetFirewallRule -Name 'SedonaRoseHttp' -DisplayName 'Sedona Rose HTTP (LAN only)' -Direction Inbound -Action Allow `
            -Program $roseHttpd -Protocol TCP -LocalPort 80 -LocalAddress $LanIp -RemoteAddress LocalSubnet -Profile Private,Domain | Out-Null
    }
}
if ($InstallService) {
    if ($roseExistingService) {
        & $roseHttpd -k config -n $roseServiceName -f $roseApacheConfig
    } else {
        & $roseHttpd -k install -n $roseServiceName -f $roseApacheConfig
    }
    if ($LASTEXITCODE -ne 0) { throw 'Apache service registration failed.' }
    Set-Service -Name $roseServiceName -StartupType Automatic
    Start-Service -Name $roseServiceName
    $roseResponse = Invoke-WebRequest -UseBasicParsing -Uri "http://$LanIp/login" -TimeoutSec 30
    if ($roseResponse.StatusCode -ne 200 -or $roseResponse.Content -notmatch 'SEDONA ROSE') {
        throw 'The Rose login page did not pass its HTTP check.'
    }
    Write-Output "Sedona Rose is installed and running at http://$LanIp/login (service: $roseServiceName)."
} else {
    Write-Output "Rose is configured for http://$LanIp. Start portable Apache with its generated config, or rerun elevated with InstallService."
}

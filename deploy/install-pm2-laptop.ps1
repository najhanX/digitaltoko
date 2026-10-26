# Installs a per-user shim; the npm-managed PM2 files remain untouched.
$ErrorActionPreference = 'Stop'
$projectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$nodeBinary = (Get-Command node -CommandType Application -ErrorAction Stop | Select-Object -First 1).Source
$phpBinary = (Get-Command php -CommandType Application -ErrorAction Stop | Select-Object -First 1).Source
$npmCommand = (Get-Command npm.cmd -CommandType Application -ErrorAction Stop | Select-Object -First 1).Source
$npmRoot = (& $npmCommand root -g | Out-String).Trim()
if ($LASTEXITCODE -ne 0) { throw 'Tidak dapat menemukan folder npm global.' }
$pm2Cli = Join-Path $npmRoot 'pm2/bin/pm2'
if (-not (Test-Path -LiteralPath $pm2Cli -PathType Leaf)) { throw 'PM2 belum terpasang. Jalankan npm install -g pm2 dahulu.' }
$launcherDirectory = Join-Path $env:USERPROFILE '.digitaltoko/bin'
$settingsPath = Join-Path $launcherDirectory 'launcher.json'
$launcherPath = Join-Path $projectRoot 'deploy/pm2-global.cjs'
$cmdPath = Join-Path $launcherDirectory 'pm2.cmd'
$psPath = Join-Path $launcherDirectory 'pm2.ps1'
foreach ($shimPath in @($cmdPath, $psPath)) {
    if ((Test-Path -LiteralPath $shimPath) -and -not ([System.IO.File]::ReadAllText($shimPath).Contains('DigitalToko PM2 launcher'))) {
        throw "File existing bukan launcher DigitalToko: $shimPath. Tidak ditimpa."
    }
}
[System.IO.Directory]::CreateDirectory($launcherDirectory) | Out-Null
$compiler = Join-Path $env:WINDIR 'Microsoft.NET/Framework64/v4.0.30319/csc.exe'
if (-not (Test-Path -LiteralPath $compiler)) { $compiler = Join-Path $env:WINDIR 'Microsoft.NET/Framework/v4.0.30319/csc.exe' }
if (-not (Test-Path -LiteralPath $compiler)) { throw 'Compiler .NET Framework Windows tidak tersedia untuk host background.' }
$runnerPath = Join-Path $launcherDirectory 'hidden-process.exe'
& $compiler /nologo /target:winexe /optimize+ "/out:$runnerPath" (Join-Path $PSScriptRoot 'HiddenProcess.cs')
if ($LASTEXITCODE -ne 0) { throw 'Build host background Windows gagal.' }
$settings = @{ root=$projectRoot; pm2Cli=$pm2Cli; phpBinary=$phpBinary }
$utf8 = [System.Text.UTF8Encoding]::new($false)
[System.IO.File]::WriteAllText($settingsPath, ($settings | ConvertTo-Json), $utf8)
$cmdText = @"
@rem DigitalToko PM2 launcher
@echo off
"$nodeBinary" "$launcherPath" --settings "$settingsPath" %*
exit /b %errorlevel%
"@
# Single-quoted PowerShell paths preserve the literal backtick in this workspace path.
$quotedNode = $nodeBinary.Replace("'", "''")
$quotedLauncher = $launcherPath.Replace("'", "''")
$quotedSettings = $settingsPath.Replace("'", "''")
$psText = @"
# DigitalToko PM2 launcher
& '$quotedNode' '$quotedLauncher' '--settings' '$quotedSettings' @args
exit `$LASTEXITCODE
"@
[System.IO.File]::WriteAllText($cmdPath, $cmdText, $utf8)
[System.IO.File]::WriteAllText($psPath, $psText, $utf8)
$userPath = [Environment]::GetEnvironmentVariable('Path', 'User')
$pathEntries = @($userPath -split ';' | Where-Object { $_ -and $_.TrimEnd('\') -ine $launcherDirectory.TrimEnd('\') })
[Environment]::SetEnvironmentVariable('Path', (@($launcherDirectory) + $pathEntries -join ';'), 'User')
$env:Path = $launcherDirectory + ';' + $env:Path
Write-Output "Launcher terpasang: $launcherDirectory"
Write-Output 'Buka terminal baru. pm2 start tanpa argumen akan menjalankan tujuh layanan DigitalToko laptop.'
Write-Output 'pm2 status/logs serta perintah dengan target eksplisit tetap memakai PM2 asli.'

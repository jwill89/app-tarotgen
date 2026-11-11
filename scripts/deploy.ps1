#!/usr/bin/env pwsh
#Requires -Version 7.4

<#
.SYNOPSIS
    Build and deploy TarotGen.io to the droplet: the Vue SPA, the PHP API, both, or the Dalamud plugin.

.DESCRIPTION
    Built on the SharedDeploy module (shared/deploy/pwsh): one archive, one held SSH
    connection, one remote run.

    The live site is one flat web root (WebRoot): the SPA in dist, the API's files beside
    it, the plugin repository in plugin. Its private data sit outside it, in TAROT_DATA_DIR
    (/var/lib/tarotgen): the .env and the database, db/tarotdb.db, which belongs to
    www-data. A deploy never ships the .env, the database, assets or the retired one-off
    scripts in db.

    What ships is the newest release: the newest tag CI made on main for
    frontend/package.json's version (the tags are fetched first), checked out into a folder
    of its own, with npm ci from its lockfile, and built and packed from there; the plugin,
    which keeps its own version, is built from it too. Commits after it, such as a shared
    update or work not yet released, are not part of it, and neither is anything
    uncommitted. -Preview ships the working tree instead. The host records the release the
    backend and the frontend each run; when every part a run deploys already runs this
    release, nothing is done (-Force deploys it again).

    Locally, from the release (or the working tree, with -Preview):
      frontend  builds frontend (vue-tsc + vite) into frontend/dist;
      backend   packs the runtime PHP: api, includes, scripts, db/migrations, og.php,
                openapi.json, phinx.php, composer.json, composer.lock and the .htaccess;
      vhost     with the backend or the frontend, renders deploy/apache-vhost.conf from the
                settings (ServerName, ServerAlias, ServerAdmin, CertName);
      plugin    refuses a csproj Version that is not higher than the published repo.json's,
                then builds the plugin from clean and lays out its repository: latest.zip,
                icon.png and repo.json, written from the built manifest with ?v=<version>
                on its download links.
    Everything goes into ONE archive. A broken build changes nothing.

    Then, on one SSH connection held for the whole run:
      1. Upload the archive to the SSH user's home.
      2. One remote run checks its SHA-256 and file count, then, API first:
           the vhost goes in with the first of the backend and frontend steps, before that
                     step changes anything: SharedDeploy installs it as
                     /etc/apache2/sites-available/<ServerName>.conf where it differs (the
                     previous kept as <ServerName>.conf.old), enables it, and disables every
                     other enabled site serving its names, such as certbot's -le-ssl file
                     (they stay in sites-available). It tries Apache with the change on a copy
                     of its config first and refuses, changing nothing, if Apache would. Apache
                     reloads once the rest of the run has succeeded; a rollback puts the file
                     and those sites back;
           backend   builds the new code whole in WebRoot/.deploy/next: the upload, a copy
                     of the live vendor with composer install --no-dev run on it, and the
                     retired db scripts carried over. Snapshots the database (below). Swaps
                     every entry in by renames, keeping the previous ones in
                     WebRoot/.deploy/backend-prev. Then, from the web root:
                       runuser -u www-data -- env TAROT_DATA_DIR=/var/lib/tarotgen php vendor/bin/phinx migrate
                     If that fails the deploy stops and the previous code goes back, with
                     Phinx's message, the snapshot and the command that restores it
                     printed. The database itself is never put back automatically;
           frontend  swaps dist in by renames (the previous kept as dist.old);
           plugin    refuses a version not higher than the published one, then swaps the
                     plugin folder in (the previous kept as plugin.old).
      3. Fetch the site from here, through Cloudflare: /api/decks must list a deck (the API
         and the database answer), the page must answer (with the frontend: name this
         build's entry script, which must load), and repo.json must carry this release's
         ?v=. If any check fails, what this run changed goes back, plugin and frontend
         first, then the code; the database stays as the migrations left it.
      4. With the backend, once that passes:
           - the daily backup, SharedDeploy's Install-BackupTimer: tarotgen-backup.timer runs
             as www-data at 10:00 UTC (up to 15 minutes' spread) and keeps a checked, gzip'd
             copy, /var/lib/tarotgen/db/backups/tarotgen-YYYY-MM-DD.db.gz, for 28 days;
           - the old backup retired: /etc/cron.d/tarotgen becomes scripts/server/tarotgen.cron
             where it differs (retention alone, at 10:30 UTC, only once that day's backup
             exists) and /usr/local/sbin/tarotgen-backup is removed. The old copies in
             /var/backups/tarotgen/nightly stay until they are removed by hand;
           - a copy of the live database pulled into backend/db/tarotdb.db, the one the app
             opens locally, keeping the three previous local copies (skip with -NoDbPull).

    The snapshot before the migrations is SharedDeploy's Backup-RemoteDatabase:
    /var/lib/tarotgen/db/tarotdb.db.pre-<yyyyMMdd-HHmmss, UTC>.gz, written as www-data, as
    everything in the database's folder must be: sqlite3 .backup to a partial file, PRAGMA
    integrity_check, gzip, gzip -t, then the rename; the newest three are kept. It is taken
    before the swap, so a snapshot that fails changes nothing. The daily backup's pruning
    touches only its own dated names in backups, so it can never match one. The pull's copy
    is made as www-data too.

    Settings resolve from the parameter, then $env:DEPLOY_*, then the git-ignored
    scripts/deploy.config.ps1 (copy scripts/deploy.config.example.ps1). Nothing about the
    host's address, user or key is in this file.

    Needs PowerShell 7.4 and PuTTY's plink 0.85 or later, with a
    key that has a passphrase loaded into Pageant first: pageant.exe "<KeyPath>". The SSH
    user must be root. The host needs composer, php, sqlite3, gzip and runuser. The whole
    run is one SSH connection (plus one only if it drops and reconnects), which stays
    under the droplet's `ufw limit ssh`.

.PARAMETER Target
    What to deploy: both (the default: backend then frontend, in one upload and one
    remote run), frontend, backend, or plugin (the Dalamud custom repo in WebRoot/plugin).
    task deploy runs both, then plugin with -IfNewer; task deploy:frontend, deploy:backend
    and deploy:plugin run one each.

.PARAMETER IfNewer
    With -Target plugin: a plugin whose version is already the published one is left alone
    and the run ends without error, where without it the run refuses. A lower version still
    refuses. task deploy passes it, so a deploy that didn't raise the plugin's version still
    succeeds.

.PARAMETER VpsHost
    Droplet IP or hostname. If omitted, resolved from $env:DEPLOY_VPS_HOST, then
    scripts/deploy.config.ps1 ($DeployVpsHost).

.PARAMETER VpsUser
    SSH user, root. If omitted, resolved from $env:DEPLOY_VPS_USER, then $DeployVpsUser.
    It has no default: it must be set in one of the three places.

.PARAMETER KeyPath
    Path to the PuTTY .ppk private key. If omitted, resolved from $env:DEPLOY_KEY_PATH,
    then $DeployKeyPath.

.PARAMETER WebRoot
    Apache DocumentRoot on the host (holds dist, api, includes, plugin and the rest). If
    omitted, resolved from $env:DEPLOY_WEB_ROOT, then $DeployWebRoot.

.PARAMETER SiteUrl
    The site's public address, fetched after the deploy as visitors reach it. If omitted,
    resolved from $env:DEPLOY_SITE_URL, then $DeploySiteUrl, then https://tarotgen.io (the
    address the SPA's canonical link and the plugin's IconUrl already publish). The
    plugin's download links are built from it too.

.PARAMETER ServerName
    The vhost's ServerName, which also names its file. If omitted, resolved from
    $env:DEPLOY_SERVER_NAME, then $DeployServerName, then the host of SiteUrl.

.PARAMETER ServerAlias
    The vhost's other names, space-separated. If omitted, resolved from
    $env:DEPLOY_SERVER_ALIAS, then $DeployServerAlias; none by default. The certificate must
    cover them.

.PARAMETER ServerAdmin
    The address the vhost gives as ServerAdmin. If omitted, resolved from
    $env:DEPLOY_SERVER_ADMIN, then $DeployServerAdmin. It has no default.

.PARAMETER CertName
    The vhost's certificate: its folder's name under /etc/letsencrypt/live, one whose
    certificate covers every name. If omitted, resolved from $env:DEPLOY_CERT_NAME, then
    $DeployCertName. It has no default.

.PARAMETER ConfigPath
    The untracked settings file; by default scripts/deploy.config.ps1. It need not exist.

.PARAMETER SkipBuild
    With -Preview, frontend only: deploy the existing frontend/dist without rebuilding. A
    release is always built from its tag.

.PARAMETER Force
    Deploy the backend and the frontend even when the host already runs this release.

.PARAMETER NoBackup
    Remove the rollback copies (dist.old, WebRoot/.deploy/backend-prev, plugin.old) once
    the run succeeds on the host. A step that fails on the host still puts its previous
    copy back, but the smoke check then has nothing to go back to, so a failure only stops
    the run. The database snapshot is still taken.

.PARAMETER NoDbPull
    Don't refresh the local database (backend/db/tarotdb.db) from the live one after a
    deploy that includes the backend.

.PARAMETER Preview
    Deploy the working tree as it is, uncommitted changes included, stamped
    preview-<date-time>. Without it every part ships from the newest release.

.EXAMPLE
    .\scripts\deploy.ps1                 # backend then frontend (default)

.EXAMPLE
    .\scripts\deploy.ps1 -Target backend

.EXAMPLE
    .\scripts\deploy.ps1 -Target both -NoDbPull

.EXAMPLE
    .\scripts\deploy.ps1 -Target plugin   # build + publish the Dalamud plugin repo
#>
[CmdletBinding()]
param(
    [ValidateSet('frontend', 'backend', 'both', 'plugin')]
    [string]$Target = 'both',

    # Server-specific settings are NOT in this tracked script. Each resolves from its
    # -param, then $env:DEPLOY_*, then the untracked scripts/deploy.config.ps1.
    [string]$VpsHost,
    [string]$VpsUser,
    [string]$KeyPath,
    [string]$WebRoot,
    [string]$SiteUrl,
    [string]$ServerName,
    [string]$ServerAlias,
    [string]$ServerAdmin,
    [string]$CertName,
    [string]$ConfigPath = (Join-Path -Path $PSScriptRoot -ChildPath 'deploy.config.ps1'),

    [switch]$SkipBuild,
    [switch]$NoBackup,
    [switch]$NoDbPull,
    [switch]$IfNewer,
    [switch]$Preview,
    [switch]$Force
)

$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $false
Import-Module (Join-Path $PSScriptRoot '..\shared\deploy\pwsh\SharedDeploy.psd1') -Force
trap { Close-DeployConnection; Write-Host "`nERROR: $($_.Exception.Message)" -ForegroundColor Red; exit 1 }

$settings = Resolve-DeploySetting -ConfigPath $ConfigPath -Value @{
    VpsHost     = $VpsHost
    VpsUser     = $VpsUser
    KeyPath     = $KeyPath
    WebRoot     = $WebRoot
    SiteUrl     = $SiteUrl
    ServerName  = $ServerName
    ServerAlias = $ServerAlias
    ServerAdmin = $ServerAdmin
    CertName    = $CertName
} -Required (@('VpsHost', 'VpsUser', 'KeyPath', 'WebRoot') + @(if ($Target -ne 'plugin') { 'ServerAdmin', 'CertName' })) -Default @{
    # Public already (frontend/index.html's canonical link, the plugin's IconUrl), so not a host secret.
    SiteUrl = 'https://tarotgen.io'
}

$web = $settings.WebRoot.TrimEnd('/')
$site = $settings.SiteUrl.TrimEnd('/')
if ($site -notmatch '^https?://[^/\s]+$') {
    throw "SiteUrl must be the site's address alone, such as https://tarotgen.io, not '$($settings.SiteUrl)'."
}

# The vhost's names: the site's own host unless set, lower case. The first is also the vhost file's name.
# An unset optional setting comes back as no value at all, which "$()" turns into ''.
$serverName = "$($settings.ServerName)".Trim()
if (-not $serverName) { $serverName = ([uri]$site).Host }
$serverName = $serverName.ToLowerInvariant()
$serverAlias = "$($settings.ServerAlias)".Trim().ToLowerInvariant() -replace '\s+', ' '
foreach ($name in @($serverName) + @($serverAlias -split ' ' | Where-Object { $_ })) {
    if ($name -notmatch '^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$') { throw "ServerName and ServerAlias must be plain host names, not '$name'." }
}
if ($Target -ne 'plugin') {
    if ($settings.CertName -notmatch '^[A-Za-z0-9][A-Za-z0-9._-]*$') { throw 'CertName must be a folder name of letters, digits and . _ - only, not a path.' }
    if ($settings.ServerAdmin -notmatch '^[^\s@]+@[^\s@]+$') { throw 'ServerAdmin must be an email address.' }
    if ($web -notmatch '^/[A-Za-z0-9._/-]+$') { throw "WebRoot must be an absolute path of plain names, not '$web'." }
}

$withBackend = $Target -in 'backend', 'both'
$withFrontend = $Target -in 'frontend', 'both'
$withPlugin = $Target -eq 'plugin'
$targets = @(
    if ($withBackend) { 'backend' }
    if ($withFrontend) { 'frontend' }
    if ($withPlugin) { 'plugin' }
)

$repoRoot = Split-Path -Path $PSScriptRoot -Parent
# The database the app opens when run locally (TAROT_DATA_DIR unset, Paths::database());
# gitignored, so this checkout's, never the release's.
$devDb = Join-Path $repoRoot 'backend\db\tarotdb.db'
# What the host records as running for each part, for the next deploy to compare with.
$records = @(
    if ($withBackend) { 'tarotgen-backend' }
    if ($withFrontend) { 'tarotgen-frontend' }
)

# The host's data, fixed by the site rather than by this machine's settings: TAROT_DATA_DIR is
# set in the vhost, /etc/environment and scripts/server/tarotgen.cron.
$dataDir = '/var/lib/tarotgen'
$liveDb = "$dataDir/db/tarotdb.db"
$dbOwner = 'www-data'
# The snapshot before the migrations, SharedDeploy's Backup-RemoteDatabase: <Database>.pre-<UTC
# time>.gz beside the database, written as www-data under umask 077, the newest three kept. The
# block sets $snapshot and $snapshot_restore for the host's messages; its .Snapshot and .Restore
# are the same, for this script's own.
$snapshotBlock = Backup-RemoteDatabase -Database $liveDb -Owner $dbOwner -Umask '077'
$snapshot = $snapshotBlock.Snapshot
$restoreDb = $snapshotBlock.Restore
# The migration command, run from the web root.
$migrate = "runuser -u $dbOwner -- env TAROT_DATA_DIR=$dataDir php vendor/bin/phinx migrate"

# The backend's code, by its top-level entries in the web root: what the archive carries (under
# backend/ in it), and vendor, which the host builds beside it. The .htaccess goes first, so the
# new rules refuse db, phinx.php and the rest before the new files land. Its staging and backup
# folders sit inside .deploy, which the .htaccess refuses too.
$backendShipped = '.htaccess', 'api', 'includes', 'scripts', 'db/migrations', 'og.php', 'openapi.json', 'phinx.php', 'composer.json', 'composer.lock'
$backendCode = @($backendShipped | ForEach-Object { ($_ -split '/')[0] }) + 'vendor'
$codeStaging = '.deploy/next'
$codeBackup = '.deploy/backend-prev'

# Every check after the deploy asks with this, so no edge-cached answer can stand in for the origin's.
$token = [guid]::NewGuid().ToString('N').Substring(0, 12)

# Only a release ships: the newest tag CI made for frontend/package.json's version, the plugin
# with it. -Preview ships the tree as it is, stamped preview-<date-time>.
$release = $null
if ($Preview) {
    $version = 'preview-' + (Get-Date -Format 'yyyyMMdd-HHmm')
    Write-Warning "Preview: deploying the working tree untagged, as $version."
} else {
    if ($SkipBuild) { throw 'A release is always built from its tag; -SkipBuild goes with -Preview only.' }
    $release = Get-DeployRelease -Path $repoRoot
    $version = $release.Tag
}
$label = " $version"

Write-Host "Deploy target: $Target ($($targets -join ', '))$label -> $($settings.VpsUser)@$($settings.VpsHost):$web" -ForegroundColor Yellow

function ConvertTo-PluginVersion {
    # Four parts, so the csproj's 0.8.1 compares with a manifest's 0.8.0.0 as Dalamud compares them.
    [CmdletBinding()]
    [OutputType([version])]
    param([Parameter(Mandatory)] [string]$Value)
    $version = [version]$Value
    [version]::new($version.Major, $version.Minor, [math]::Max($version.Build, 0), [math]::Max($version.Revision, 0))
}

# -- The host's steps -----------------------------------------------------------
# Switch-RemoteItemSet runs these from the web root, with $stage (.deploy/next), $backup
# (.deploy/backend-prev) and $bundle set, under set -e and its own EXIT trap, which puts the
# previous code back if anything after the swap fails. So they set no trap of their own.
$prepare = Format-RemoteScript -Value @{
    DB       = $liveDb
    DATA_DIR = $dataDir
    SNAPSHOT = $snapshotBlock
} -Template @'
db='__DB__'
if [ ! -f "$db" ]; then echo "There is no database at $db (is TAROT_DATA_DIR still __DATA_DIR__?), so nothing was changed." >&2; exit 3; fi
for t in composer php sqlite3 gzip runuser; do
  if ! command -v "$t" >/dev/null 2>&1; then echo "$t is not installed (or not on PATH) on the host, so nothing was changed." >&2; exit 3; fi
done
# Only db/migrations ships. The retired one-off scripts beside it on the host stay where they
# are, so they move into the new db with it.
mkdir -p "$stage/db"
if [ -d db ]; then
  find db -mindepth 1 -maxdepth 1 ! -name migrations -exec cp -a {} "$stage/db/" \;
fi
# On every deploy, in the staging folder: the site never runs new code on the old vendor, and
# the optimized autoloader always matches the new code. It starts from a copy of the live
# vendor, so it only fetches what composer.lock changed.
echo '==> composer install --no-dev, in the staging folder'
export COMPOSER_ALLOW_SUPERUSER=1
if [ -d vendor ]; then cp -a vendor "$stage/vendor"; fi
composer install --working-dir="$stage" --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader
if [ ! -f "$stage/vendor/bin/phinx" ]; then echo 'composer install left no vendor/bin/phinx, so the migrations could not run; nothing was changed.' >&2; exit 3; fi
# The database before the migrations (Backup-RemoteDatabase): sqlite3 .backup to a partial file,
# PRAGMA integrity_check, gzip to another partial file, gzip -t, and only then the rename, with
# no uncompressed copy left; the newest three kept. Whatever it writes in the database's folder
# runs as www-data, under umask 077. Taken before the swap, so a snapshot that fails stops the
# step with nothing changed.
echo '==> Snapshotting the database before the migrations'
__SNAPSHOT__
'@

$afterSwap = Format-RemoteScript -Value @{ MIGRATE = $migrate } -Template @'
# >>> migrate (tested on its own; keep these markers)
echo '==> __MIGRATE__'
if out=$(__MIGRATE__ 2>&1); then
  printf '%s\n' "$out" | sed 's/^/    /'
else
  rc=$?
  {
    echo ''
    echo "MIGRATION FAILED (phinx exit $rc): the deploy stops here and the previous code goes back. Phinx said:"
    printf '%s\n' "$out" | sed 's/^/  | /'
    case "$out" in
      *'The baseline changed nothing'*)
        echo 'That is the baseline refusing the live database: its schema is not the one the old db scripts built (Missing and Different above).'
        echo 'Nothing in the database was changed or recorded. Bring it to that schema, then deploy again (AGENTS.md section 7).'
        ;;
      *)
        echo 'Each migration runs in one transaction, so the one that failed left nothing behind; any that ran before it in this deploy stay applied.'
        ;;
    esac
    echo "The database as it was just before the migrations: $snapshot"
    echo "To put it back, with the site's writes stopped:"
    echo "  $snapshot_restore"
  } >&2
  exit 6
fi
# <<< migrate
# Leftover rollback archive and staging folder from an earlier deploy script; the rollback
# copy of the code is .deploy/backend-prev.
rm -f .deploy/backend-prev.tgz
rm -rf .deploy.new
'@

# After the daily backup is in place: the cron file runs retention alone, and the old nightly
# backup goes. Its copies stay until they are removed by hand. __CRON__ is the build's
# scripts/server/tarotgen.cron, filled in once it is read.
$retireTemplate = @'
set -e
cron=/etc/cron.d/tarotgen
next=/etc/cron.d/.tarotgen.next
old_job=/usr/local/sbin/tarotgen-backup
nightly=/var/backups/tarotgen/nightly
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
printf '%s' '__CRON__' | base64 -d > "$work/cron"
if cmp -s "$work/cron" "$cron"; then
  echo "$cron is already scripts/server/tarotgen.cron."
else
  # Under a name with dots, which cron ignores, until the rename puts it in place whole.
  install -m 644 -o root -g root "$work/cron" "$next"
  mv -f "$next" "$cron"
  echo "Installed $cron from scripts/server/tarotgen.cron: retention alone, once the day's backup exists."
fi
if [ -e "$old_job" ]; then
  rm -f "$old_job"
  echo "Removed $old_job, the old nightly backup."
fi
if [ -d "$nightly" ]; then
  echo "The old nightly copies stay in $nightly ($(find "$nightly" -maxdepth 1 -type f -name 'tarotdb-*.db.gz' | wc -l) of them) until they are removed by hand."
fi
'@

# The vhost goes in through the module with the first step that swaps the site, before that step
# changes anything: the backend's, or the frontend's when the run has no backend. A rollback
# puts it back through the same step's Restore- call.
$vhostArchivePath = 'host/apache-vhost.conf'
$vhostArgs = @{ Vhost = $vhostArchivePath; ServerName = $serverName }
$frontendVhost = if ($withBackend) { @{} } else { $vhostArgs }
$frontendRestore = if ($withBackend) { @{} } else { @{ ServerName = $serverName } }

# One archive, laid out as the host's steps want it: the code under backend/, the SPA as dist,
# the plugin repository as plugin, and the rendered vhost under host/.
$content = [ordered]@{}
$vhostFile = $null
$entryScript = $null
$repo = $null
$archive = $null
$connection = $null
$tree = $null
$retire = $null

try {
    # -- The release: what the host runs, then its own checkout -----------------------
    $build = $repoRoot
    if (-not $Preview) {
        # The backend and the frontend are recorded on the host; a plugin-only run needs no
        # connection before it knows the plugin is new (below).
        if ($records) {
            $connection = $settings | Open-DeployConnection
            $live = @($records | ForEach-Object { Get-DeployedRelease -Name $_ })
            if (-not $Force -and -not @($live | Where-Object { $_ -ne $version })) {
                Write-Ok "$version is already running on the host ($($targets -join ', ')); nothing to deploy. (A version pushed since goes out once CI tags it; -Force deploys this one again.)"
                return
            }
            Write-Step "Releasing $version (the host runs $(($live | ForEach-Object { if ($_) { $_ } else { 'a release not recorded' } }) -join ' and '))"
        }
        if ($release.Since) { Write-Step "The $($release.Since) commit(s) on main after $version are not part of it." }
        $tree = New-ReleaseTree -Path $repoRoot -Tag $version -Npm @(if ($withFrontend) { 'frontend' })
        $build = $tree
    }
    $backendDir = Join-Path $build 'backend'
    $frontendDir = Join-Path $build 'frontend'
    $distDir = Join-Path $frontendDir 'dist'
    $pluginDir = Join-Path $build 'plugin\TarotGen.Plugin'
    $pluginProject = Join-Path $pluginDir 'TarotGen.Plugin.csproj'
    $cronFile = Join-Path $build 'scripts\server\tarotgen.cron'

    # The plugin's version must be higher than the published one, since Dalamud updates to
    # nothing else. The host refuses a lower one too, but the published index is read here
    # first, before the plugin is built. If it can't be read, the host's check still stands.
    if ($withPlugin) {
        $planned = @(([xml](Get-Content -LiteralPath $pluginProject -Raw)).Project.PropertyGroup.Version | Where-Object { $_ })[0]
        $published = $null
        try {
            $published = @(Invoke-RestMethod -Uri "$site/plugin/repo.json?deploy=$token" -TimeoutSec 15)[0].AssemblyVersion
            $higher = (ConvertTo-PluginVersion -Value $planned) -gt (ConvertTo-PluginVersion -Value $published)
        } catch {
            Write-Warning "The published plugin version could not be read or compared ($($_.Exception.Message)); the host will check it."
            $higher = $true
        }
        if (-not $higher) {
            if ($IfNewer -and $published -and (ConvertTo-PluginVersion -Value $planned) -eq (ConvertTo-PluginVersion -Value $published)) {
                Write-Ok "Plugin $planned is already published; nothing to publish."
                return
            }
            throw "The plugin's version $planned is not higher than the published $published, and Dalamud updates to nothing else. Raise <Version> in plugin/TarotGen.Plugin/TarotGen.Plugin.csproj; nothing was built or changed."
        }
    }

    # -- Build and pack ------------------------------------------------------------------
    if ($withBackend) {
        foreach ($item in $backendShipped) {
            $path = Join-Path $backendDir $item
            if (-not (Test-Path -LiteralPath $path)) { throw "Backend payload item not found: $path. Nothing was changed." }
            $content["backend/$item"] = $path
        }
        # The cron file names the web root and the daily backup's folder itself; a mismatch would
        # stop retention without a word, so it stops the deploy instead, before anything changes.
        $cron = (Get-Content -LiteralPath $cronFile -Raw) -replace "`r", ''
        foreach ($needed in "$web/scripts/retention.php", "$dataDir/db/backups/tarotgen-") {
            if (-not $cron.Contains($needed)) { throw "scripts/server/tarotgen.cron does not name $needed, which this deploy installs; fix one or the other. Nothing was changed." }
        }
        if (-not $cron.EndsWith("`n")) { $cron += "`n" }
        $retire = Format-RemoteScript -Template $retireTemplate -Value @{ CRON = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($cron)) }
    }

    if ($withBackend -or $withFrontend) {
        # The vhost, filled in for this host; the host installs it where it differs from the live one.
        $vhostTemplate = Join-Path $build 'deploy\apache-vhost.conf'
        if (-not (Test-Path -LiteralPath $vhostTemplate)) { throw "deploy/apache-vhost.conf is not in $version. Nothing was changed." }
        $vhost = (Get-Content -LiteralPath $vhostTemplate -Raw) -replace "`r", ''
        # A site with one name has no ServerAlias line: Apache refuses one with nothing on it.
        if (-not $serverAlias) { $vhost = $vhost -replace '(?m)^[ \t]*ServerAlias[ \t]+__SERVER_ALIAS__[ \t]*\n', '' }
        $vhost = Format-RemoteScript -Template $vhost -Value @{
            SERVER_NAME  = $serverName
            SERVER_ALIAS = $serverAlias
            SERVER_ADMIN = $settings.ServerAdmin
            WEB_ROOT     = $web
            DATA_DIR     = $dataDir
            CERT_NAME    = $settings.CertName
        }
        if ($vhost -match '__[A-Z_]+__') { throw "deploy/apache-vhost.conf has a placeholder the settings do not fill: $($Matches[0]). Nothing was changed." }
        $vhostFile = Join-Path ([IO.Path]::GetTempPath()) "tarotgen-vhost-$([guid]::NewGuid().ToString('N').Substring(0, 8)).conf"
        [IO.File]::WriteAllText($vhostFile, $vhost, [Text.UTF8Encoding]::new($false))
        $content[$vhostArchivePath] = $vhostFile
    }

    if ($withFrontend) {
        $shell = Join-Path $distDir 'index.html'
        if ($SkipBuild) {
            Write-Step 'Skipping the frontend build (-SkipBuild); using the existing frontend/dist'
            if (-not (Test-Path -LiteralPath $shell)) { throw 'frontend/dist/index.html is missing - nothing to deploy.' }
        } else {
            Invoke-NpmBuild -Path $frontendDir -Output $shell
        }
        $content['dist'] = $distDir
        # The build's entry script (Vite's assetsDir is _app): the page must name it, and serve it.
        $entryScript = [regex]::Match((Get-Content -LiteralPath $shell -Raw), 'src="(/_app/[^"]+\.js)"').Groups[1].Value
    }

    if ($withPlugin) {
        Invoke-PluginBuild -Project $pluginProject
        $built = Join-Path $pluginDir 'bin\Release\TarotGen.Plugin'
        # repo.json is written from the built manifest, with ?v=<version> on the download links so
        # Cloudflare never serves an old latest.zip under a new index.
        $repo = Export-PluginRepository -Manifest (Join-Path $built 'TarotGen.Plugin.json') -IndexPath 'repo.json' `
            -BaseUrl "$site/plugin" -IconPath 'icon.png' -File ([ordered]@{
                'latest.zip' = Join-Path $built 'latest.zip'
                'icon.png'   = Join-Path $pluginDir 'images\icon.png'
            })
        $content['plugin'] = $repo.Path
    }

    Write-Step 'Packing the deploy...'
    $archive = Compress-DeployArchive -Content $content -Exclude '__pycache__', '*.pyc', '.env', '.env.*', 'Desktop.ini', 'Thumbs.db', '.DS_Store'
    Write-Ok ('{0} files, {1:N1} MB, one archive' -f $archive.FileCount, ($archive.Size / 1MB))

    if (-not $connection) { $connection = $settings | Open-DeployConnection }

    Write-Step 'Uploading...'
    $upload = Invoke-Upload -Path $archive.Path -Destination 'tarotgen-deploy.tgz' -Activity "Uploading TarotGen.io ($($targets -join ', '))" -FileCount $archive.FileCount
    if (-not $upload.Ok) { throw "Upload failed; nothing on the host changed.`n$($upload.Err)" }
    Write-Ok ('Uploaded in {0:mm\:ss}.' -f $upload.Elapsed)

    # API first: a new backend is additive to the old SPA, while a new SPA may call endpoints
    # an old backend does not have yet. Nothing changes until the archive's SHA-256 and file
    # count check.
    $steps = @(
        if ($withBackend) {
            Switch-RemoteItemSet -AsStep -Root $web -Item $backendCode -Source 'backend' -Staging $codeStaging -Backup $codeBackup `
                -Prepare $prepare -AfterSwap $afterSwap @vhostArgs -NoBackup:$NoBackup
        }
        if ($withFrontend) {
            Switch-RemoteDirectory -AsStep -Live "$web/dist" @frontendVhost -NoBackup:$NoBackup
        }
        if ($withPlugin) {
            Publish-PluginRepository -AsStep -Live "$web/plugin" -Version $repo.Version -IndexPath 'repo.json' -NoBackup:$NoBackup
        }
    )
    Write-Step "Deploying on the host ($($targets -join ', '))..."
    try {
        Invoke-DeployBundle -Archive 'tarotgen-deploy.tgz' -Sha256 $archive.Sha256 -ExpectedFileCount $archive.FileCount -Step $steps
    } catch {
        if ($withBackend -and $_.Exception.Message -match '\(exit 6\)$') {
            throw "The migrations failed (Phinx's message is above), so the deploy stopped and the previous code is back; nothing after the backend ran. The database is as the migrations left it. The snapshot from just before them is $snapshot; to put it back, with the site's writes stopped, run on the host:`n  $restoreDb"
        }
        if ($targets.Count -gt 1) {
            throw "$($_.Exception.Message). With several targets, the ones before the failing step are live (the lines above say which) and were not smoke-checked."
        }
        throw
    }

    # -- Smoke check, through Cloudflare ------------------------------------------
    $checks = @(
        if ($withBackend) {
            # A deck from the database through the API: both answer.
            @{ Url = "$site/api/decks?deploy=$token"; Contains = '"deck_id"' }
            # og.php, backend code, serves every page.
            if (-not $withFrontend) { @{ Url = "$site/?deploy=$token"; Contains = 'id="app"' } }
        }
        if ($withFrontend) {
            if ($entryScript) {
                @{ Url = "$site/?deploy=$token"; Contains = $entryScript }
                @{ Url = "$site$entryScript" }
            } else {
                @{ Url = "$site/?deploy=$token"; Contains = 'id="app"' }
            }
        }
        if ($withPlugin) { @{ Url = "$site/plugin/repo.json?deploy=$token"; Contains = "latest.zip?v=$($repo.Version)" } }
    )
    Write-Step "Checking $site..."
    try {
        if ($NoBackup) {
            # Nothing was kept to go back to.
            Invoke-SmokeCheck -Check $checks -Attempts 3 -DelaySeconds 2 -NoRollback
        } else {
            # The reverse of the deploy. The code goes back without the database: the migrations
            # are additive, so the previous code runs on the new schema.
            $rollback = @(
                if ($withPlugin) { Restore-RemoteDirectory -Live "$web/plugin" -AsScript }
                if ($withFrontend) { Restore-RemoteDirectory -Live "$web/dist" @frontendRestore -AsScript }
                if ($withBackend) { Restore-RemoteItemSet -Root $web -Item $backendCode -Backup $codeBackup -ServerName $serverName -AsScript }
            )
            Invoke-SmokeCheck -Check $checks -Attempts 3 -DelaySeconds 2 -Rollback {
                Invoke-RemoteStep -Script $rollback -FailureMessage 'Rolling back failed on the host'
            }
        }
    } catch {
        if ($withBackend) {
            throw "$($_.Exception.Message)`nThe database stays as the migrations left it. The snapshot from just before them is $snapshot; to put it back, with the site's writes stopped, run on the host:`n  $restoreDb"
        }
        throw
    }
    # Only a deploy that stayed is recorded; a preview's stamp makes the next release go out.
    foreach ($record in $records) { Set-DeployedRelease -Name $record -Release $version }

    # -- The data, once the release stays (backend) -----------------------------------
    if ($withBackend) {
        # The daily copies go in backups beside the database, named tarotgen-YYYY-MM-DD.db.gz:
        # apart from the snapshots (tarotdb.db.pre-<time>.gz, beside the database), so neither
        # pruning can match the other's names.
        Write-Step 'Checking the daily database backup...'
        try {
            Install-BackupTimer -Name 'tarotgen' -Database $liveDb -Owner $dbOwner -Description 'TarotGen.io daily database backup'
        } catch {
            throw "The site is deployed and live, but its backup is not: $($_.Exception.Message)"
        }

        Write-Step 'Retiring the old nightly backup...'
        if (-not (Invoke-RemoteWithRetry -Script $retire)) {
            throw 'The site is deployed and live, and its daily backup is in place, but the old nightly backup was not retired (the lines above say why). Until a deploy replaces /etc/cron.d/tarotgen, it runs the old backup and retention after it, as before.'
        }

        if (-not $NoDbPull) {
            # sqlite3 runs as www-data, under umask 077, so its copy (tarotdb.db.pull, removed
            # after the download) and anything it makes in the database's folder are www-data's.
            $null = Copy-LiveDatabase -Database $liveDb -Destination $devDb -Owner $dbOwner
        }
    }
} finally {
    Close-DeployConnection
    if ($archive) { Remove-Item -LiteralPath $archive.Path -ErrorAction SilentlyContinue }
    if ($vhostFile) { Remove-Item -LiteralPath $vhostFile -ErrorAction SilentlyContinue }
    if ($repo) { Remove-Item -LiteralPath $repo.Path -Recurse -ErrorAction SilentlyContinue }
    if ($tree) { Remove-ReleaseTree -Path $tree -Repository $repoRoot }
}

Write-Host "`n[OK] Deployed $($targets -join ', ') to $($connection.Target):$web" -ForegroundColor Green
if ($withBackend) {
    if (-not $NoBackup) {
        Write-Host "     Previous code kept in $web/$codeBackup." -ForegroundColor DarkGray
    }
    Write-Host "     Database snapshot from before the migrations: $snapshot" -ForegroundColor DarkGray
    Write-Host '     The .env, assets and the retired db scripts were not touched.' -ForegroundColor DarkGray
}
if ($withFrontend) {
    if (-not $NoBackup) {
        Write-Host "     Previous build kept at $web/dist.old." -ForegroundColor DarkGray
    }
    Write-Host '     Assets are content-hashed and the PWA auto-updates, so no manual cache bust is needed.' -ForegroundColor DarkGray
}
if ($withPlugin) {
    if (-not $NoBackup) {
        Write-Host "     Previous plugin repo kept at $web/plugin.old." -ForegroundColor DarkGray
    }
    Write-Host "     Custom repo URL to share with testers: $site/plugin/repo.json (v$($repo.Version))." -ForegroundColor DarkGray
}

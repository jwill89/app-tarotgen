# Deploy configuration TEMPLATE. Copy this file to `deploy.config.ps1` (which is
# untracked - see .gitignore) and fill in your values. scripts/deploy.ps1 reads
# deploy.config.ps1 (through SharedDeploy's Resolve-DeploySetting) for every
# environment-specific setting that isn't passed as a -param or set via the matching
# $env:DEPLOY_* variable.
#
# Resolution order per setting (first non-empty wins):
#   1. the script -parameter (e.g. -VpsHost, -WebRoot)
#   2. the environment variable (e.g. $env:DEPLOY_VPS_HOST, $env:DEPLOY_WEB_ROOT)
#   3. the $Deploy* value below (this file's copy)
#
# Keeping these here (not in the tracked deploy.ps1) means the repo never reveals the
# host, the SSH user or the key path.
[Diagnostics.CodeAnalysis.SuppressMessageAttribute('PSUseDeclaredVarsMoreThanAssignments', '', Justification = 'deploy.ps1 reads the $Deploy* values through Resolve-DeploySetting')]
param()

$DeployVpsHost = '<your-droplet-ip-or-hostname>'
$DeployVpsUser = '<ssh-user>'                     # required (no default): root, since the backend runs runuser and installs cron and systemd files
$DeployKeyPath = '<path-to-your-deploy-key.ppk>'  # load it into Pageant first if it has a passphrase
$DeployWebRoot = '<apache-documentroot-on-host>'  # e.g. /var/www/example.com
# Optional: the site's public address, fetched after every deploy (the smoke check) as
# visitors reach it, through Cloudflare; the plugin's download links are built from it
# too. Unset, it is https://tarotgen.io; set it only to check another.
# $DeploySiteUrl = '<https-address-of-the-site>'  # e.g. https://example.com

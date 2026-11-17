# Operations

The runbook for the live site: the host's one-time setup, its backups and how to restore them, what to check after a deploy, the steps a release needs beyond `task deploy`, rolling back, and where the logs are. The deploy itself is documented in `Get-Help .\scripts\deploy.ps1 -Full` and in AGENTS.md, section 6.

The paths below are written as the deploy config's settings name them: `<site folder>` is `WebRoot` (the site's folder, which holds the code and `public/`, Apache's document root), `<data folder>` is `DataDir` (`TAROT_DATA_DIR`: the `.env` and the database), and `<cert>` is `CertName`. The site runs no service of its own: Apache serves `<site folder>/public` through the PHP-FPM pool the droplet's PHP sites share, so it has no port (`AppPort`), no service user and no `MemoryMax=` to record.

## Host setup

A PHP site on the shared droplet. Run these once as root, with the two folders filled in; each is safe to run again.

```sh
site='<site folder>'
data='<data folder>'

# PHP 8.5 and the extensions the app and its packages need (ctype, filter, iconv, openssl,
# pdo and session come with PHP itself), the tools the deploy runs, and Apache's modules.
apt-get install -y php8.5-fpm php8.5-curl php8.5-gd php8.5-gmp php8.5-mbstring php8.5-sqlite3 php8.5-xml composer sqlite3
a2enmod rewrite headers ssl remoteip setenvif http2 proxy_fcgi

# The site's folder (the deploy fills it) and its images, which www-data writes (the WebP copies).
# A site still on the flat layout (og.php in the site's folder itself) gets public/ from its first
# 6.0.0 deploy, which carries the images over: an empty public/assets made first would be carried
# instead.
install -d -o root -g root -m 755 "$site"
if [ ! -e "$site/og.php" ]; then
  install -d -o root -g root -m 755 "$site/public"
  install -d -o www-data -g www-data -m 755 "$site/public/assets" "$site/public/assets/decks"
fi

# The private data: www-data reads the .env and owns the database's folder; only root changes the .env.
install -d -o root -g www-data -m 750 "$data"
install -d -o www-data -g www-data -m 750 "$data/db"
[ -f "$data/.env" ] || echo "Copy backend/.env.example from the repo to $data/.env (root:www-data, 640) and fill it in."
[ -f "$data/db/tarotdb.db" ] || runuser -u www-data -- touch "$data/db/tarotdb.db"   # the first deploy migrates it

# TAROT_DATA_DIR for SSH sessions; the vhost sets it for the site, the cron file for its jobs.
grep -q '^TAROT_DATA_DIR=' /etc/environment || echo "TAROT_DATA_DIR=$data" >> /etc/environment

# Cloudflare's ranges, which the vhost trusts for the visitor's address, and the shared
# certificate, which must cover tarotgen.io.
[ -s /etc/apache2/cloudflare-ips.txt ] || echo 'Write /etc/apache2/cloudflare-ips.txt from https://www.cloudflare.com/ips/ first.'
openssl x509 -in '/etc/letsencrypt/live/<cert>/fullchain.pem' -noout -ext subjectAltName | grep -q 'DNS:tarotgen.io' \
  || echo 'The certificate does not cover tarotgen.io.'
```

DNS: `tarotgen.io` is a proxied (orange-cloud) A and AAAA record in Cloudflare, pointing at the droplet. The vhost, the cron file and the backup timer are installed by the deploy, never by hand; `deploy/apache-vhost.conf` and `deploy/tarotgen.cron` are their templates.

## Backups

Kept on the host, for the database:

- **Daily:** `tarotgen-backup.timer` (SharedDeploy's `Install-BackupTimer`, installed and kept by every backend deploy) runs `tarotgen-backup.service` as www-data at 10:00 UTC, with up to 15 minutes' spread (a missed run happens at the next boot). It copies the live database with `VACUUM INTO`, checks it with `PRAGMA integrity_check`, gzips it and checks that with `gzip -t`, and keeps it as `<data folder>/db/backups/tarotgen-YYYY-MM-DD.db.gz` (UTC date) for 28 days. Take one by hand (before changing production by hand, say) with `sudo systemctl start tarotgen-backup`, which replaces that day's copy.
- **Before each backend deploy's migrations:** `<data folder>/db/tarotdb.db.pre-<yyyyMMdd-HHmmss, UTC>.gz`, written the same way by `Backup-RemoteDatabase`; the newest three are kept.

Kept off the host, on the owner's PC, by each backend deploy unless it runs with `-NoDbPull`:

- **The database:** pulled into `backend/db/tarotdb.db`, the three previous local copies kept (`Copy-LiveDatabase`). It holds real accounts and hashes, so it stays on that PC and out of git.
- **The images**, `<site folder>/public/assets`: the card scans (`decks/<id>/Card_XXXX.png`, `Card_Back.png`) and the site's own pictures (favicon, share banner, lightbox arrows), which no repo holds. A folder up to **250 MB** would be archived daily on the host; this one is far over that (5.4 GB on 2026-11-15, 5.0 GB without the WebP copies; 29 decks, 2,228 scans), so it gets no archive on the droplet. Instead each deploy brings a copy of it up to date in `backend/public/assets` on the owner's PC, fetching only what is new or changed, each file checked against the host's SHA-256 (`Sync-LiveFolder`). The WebP copies (`Card_XXXX.webp`, `thumbs/`) are left out: `generate_card_images.php` derives them from the scans. That copy is the images' backup; nothing local is deleted when the host loses a file.

Only that database pull and the images' copy leave the droplet.

## Restore steps

The database, from a daily copy or a deploy's snapshot. First stop the site's writes by taking it offline, then put the copy over the database in place as www-data, which keeps its owner and mode:

```sh
a2dissite tarotgen.io && systemctl reload apache2
runuser -u www-data -- sh -c "rm -f '<data folder>/db/tarotdb.db-wal' '<data folder>/db/tarotdb.db-shm' && gunzip -c '<the copy>' > '<data folder>/db/tarotdb.db'"
runuser -u www-data -- sqlite3 '<data folder>/db/tarotdb.db' 'PRAGMA integrity_check; PRAGMA foreign_key_check;'
a2ensite tarotgen.io && systemctl reload apache2
```

The daily job's header on the host (`/usr/local/libexec/shared-deploy/tarotgen-backup`) and the deploy's own messages print the same line with the paths filled in.

The images, from the copy on the owner's PC. Pack it there with Windows' tar as pax, so names keep their UTF-8, and send it on a deploy connection:

```powershell
& (Resolve-TarTool) --format=pax --exclude=.shared-deploy-sync -czf assets-restore.tar.gz -C backend\public assets
$null = Invoke-Upload -Path assets-restore.tar.gz -Destination 'assets-restore.tar.gz'
```

Then on the host, as root, move the live folder aside, unpack beside it, give it back to www-data and rebuild the WebP copies:

```sh
mv '<site folder>/public/assets' '<site folder>/public/assets.before-restore'
tar -xzf ~/assets-restore.tar.gz -C '<site folder>/public'
chown -R www-data:www-data '<site folder>/public/assets'
cd '<site folder>' && runuser -u www-data -- nice -n 19 php scripts/generate_card_images.php
```

Purge `/assets/*` in Cloudflare afterwards if any scan changed (it is edge-cached for 30 days).

## Restore drill

Not run on the host, by the owner's decision of 2026-11-15 (AGENTS.md, Departures). When one is wanted:

1. On the host, as www-data, decompress the newest daily copy into a scratch file: `runuser -u www-data -- sh -c "gunzip -c '<data folder>/db/backups/tarotgen-<date>.db.gz' > /tmp/tarotgen-drill.db"`.
2. `sqlite3 /tmp/tarotgen-drill.db 'PRAGMA integrity_check; PRAGMA foreign_key_check;'` prints `ok` and nothing else.
3. Compare `SELECT COUNT(*)` of `users`, `readings`, `decks` and `spreads` with the live database's; the copy may be up to a day behind.
4. `rm /tmp/tarotgen-drill.db`, and write the date here.

Last run on the host: never.

## Production-only checks

After every deploy, in a fresh browser tab with the console open:

1. The home page loads with no Content-Security-Policy refusal in the console: Cloudflare's injected scripts (Bot Fight Mode, Web Analytics) carry the response's nonce only there.
2. Draw a reading as a guest, and open `/login` and `/contact`: the Turnstile check passes, the reading saves, and the console shows no `style-src` refusal (Turnstile runs only on production, so its styles are checked only here).
3. A shared reading's page (`/reading/<id>`) carries its own title and image in its share meta (view the source, or a link-preview tool).
4. Sign in with a password, a passkey and Google: each lands signed in, and the session cookie is `__Host-tarotgen`, Secure and HttpOnly.
5. A password-reset email arrives (it goes out after the response).
6. `/api/docs` renders the API reference (Swagger UI), with no refusal in the console.
7. `https://tarotgen.io/plugin/repo.json` lists the plugin's version with `?v=` on its links.
8. The response headers carry `Strict-Transport-Security`, `Referrer-Policy: same-origin` and `X-Content-Type-Options: nosniff`.

## When the policy blocks something

`CSP_MODE=report-only` in `<data folder>/.env` turns the Content-Security-Policy to report-only without a deploy: set it first when production blocks something, then fix the policy (`Tarot\Utility\ContentSecurityPolicy`) and remove the setting.

## Upgrading to 6.0.0

The site moves onto a `public/` document root, the database's timestamps are rebuilt in RFC 3339 form, and the cron file goes in through `Install-HostConfig`. This is also the first deploy to install the site's vhost: 5.6.0's step for it never reached the host, where certbot's `tarotgen.io-le-ssl.conf` still served port 443 on 2026-11-16, so Upgrading to 5.6.0's steps 2 to 4 run with this deploy.

Done on 2026-11-16:

- `$DeployDataDir` is in `scripts/deploy.config.ps1`, taken from the host's `TAROT_DATA_DIR`. The config already held the vhost's settings: `CertName`, `ServerAdmin`, `ServerName` and `ServerAlias` (`www.tarotgen.io`, which the live sites serve too). The certificate covers `tarotgen.io` but not `www.tarotgen.io`, as before; Cloudflare still reaches it.
- The host has what the release needs: Apache's modules (rewrite, headers, ssl, remoteip, setenvif, http2, proxy_fcgi), Cloudflare's ranges in `/etc/apache2/cloudflare-ips.txt`, PHP 8.5.12 with every extension the host setup lists, the deploy's tools, 17 GB free, ten daily backups, and no `public/` yet, which the deploy makes.

Left, in order:

1. Just before the deploy, as root: give the site's folder and its `.deploy` (www-data's until now) to root, as the host setup has it, and turn the policy to report-only. 5.6.0 runs on both.

   ```sh
   chown root:root '<site folder>' '<site folder>/.deploy' && chmod 755 '<site folder>' '<site folder>/.deploy'
   cp -p '<data folder>/.env' '<data folder>/.env.bak-6.0.0' && printf '\nCSP_MODE=report-only\n' >> '<data folder>/.env'
   ```

2. Run `task deploy` at a quiet hour; the backend goes first, which the move needs (a frontend- or plugin-only deploy refuses until it has). On the host, the deploy:
   1. builds the new code with `public/` holding the API's front controller, `og.php` and the `.htaccess`, and carries the site's `dist`, `plugin` and `assets` into it as hard links (nothing is copied, and the old folders stay as they are);
   2. snapshots the database, swaps the code in, and runs the migrations: the one that rebuilds every timestamp column takes a few seconds;
   3. installs `/etc/cron.d/tarotgen` from the template, and the vhost with its document root at `<site folder>/public`, HSTS and the two lines that take the visitor's address from Cloudflare, disabling certbot's `tarotgen.io-le-ssl.conf`;
   4. publishes the plugin, 0.9.0.

   Between the swap and Apache's reload at the end of the run, the API answers 404 for a few seconds, once.
3. If the deploy rolls back, put the database back with the restore command it prints: 5.6.0's code can't write to the rebuilt tables.
4. The first pull of the images afterwards fetches about 5 GB into `backend/public/assets` (later ones fetch only what changed). Run the deploy with `-NoDbPull` to put it off.
5. Everyone signs in again once: the session cookie has a new name. Linked plugins keep working, as their tokens don't use the cookie.
6. Check `sudo apachectl -S` on the host: `tarotgen.io.conf` alone serves ports 80 and 443 for `tarotgen.io` and `www.tarotgen.io`.
7. Run the production-only checks above and watch the console for `style-src` reports: the pages' policy now refuses inline styles that don't carry the response's nonce, and Turnstile and Cloudflare's injected scripts run only on production. Once there are none, enforce the policy again:

   ```sh
   sed -i '/^CSP_MODE=/d' '<data folder>/.env' && rm '<data folder>/.env.bak-6.0.0'
   ```

8. Last, once 6.0.0 is staying (5.6.0 can't be put back by hand without them), remove what the flat layout left in `<site folder>`: `.htaccess`, `og.php`, `dist`, `dist.old`, `plugin`, `plugin.old` and `assets` (its files are hard links that `public/assets` also holds, so removing it frees nothing and loses nothing). Check `<site folder>/public/assets/decks` lists the decks before removing `assets`.

   ```sh
   cd '<site folder>' && ls public/assets/decks | wc -l && rm -rf .htaccess og.php dist dist.old plugin plugin.old assets
   ```

## Upgrading to 5.6.0

The deploy now ships the site's Apache vhost (`deploy/apache-vhost.conf`, both ports) and retires the files certbot wrote for it. One step comes before the first deploy; the rest the deploy does itself.

1. In the git-ignored `scripts/deploy.config.ps1`, add the two settings the vhost needs, as `scripts/deploy.config.example.ps1` shows: `$DeployCertName`, the folder under `/etc/letsencrypt/live` whose certificate covers every name the site serves, and `$DeployServerAdmin`, the address Apache shows on its error pages. Add `$DeployServerName` and `$DeployServerAlias` only if the site serves more than the host of its address (`tarotgen.io`); every name needs the certificate to cover it.
2. Run `task deploy`. Before it changes anything else on the host, the deploy:
   1. fills the template and uploads it with the release;
   2. finds the other enabled sites serving the same names, which is certbot's `tarotgen.io-le-ssl.conf` (the port-443 site);
   3. tries Apache with the new file enabled and those sites gone, on a copy of its config, and stops, changing nothing, if Apache refuses it;
   4. installs `/etc/apache2/sites-available/tarotgen.io.conf` over certbot's port-80 file, keeping the previous one as `tarotgen.io.conf.old`, enables it, and disables `tarotgen.io-le-ssl.conf` (it stays in `sites-available`).
3. The backend and frontend steps then run as always. Apache reloads once they have succeeded, and the smoke check fetches the site through Cloudflare. If the check fails, the previous vhost files and the disabled site go back with the code.
4. Check `sudo apachectl -S` on the host: `tarotgen.io.conf` alone serves ports 80 and 443 for the site's names.

From then on, a change to the vhost goes into the template and ships with the next deploy. Nobody runs `certbot --apache` for the site: the certificate renews by DNS and only its files change.

## Rolling back

A deploy whose smoke check fails puts back what it changed itself, the plugin and the frontend first, then the code, the cron file and the vhost; the database stays as the migrations left it, and the deploy prints the snapshot's restore command (above). When that didn't happen, put the previous release back by hand with the same functions, on one connection from the repo's root:

```powershell
Import-Module .\shared\deploy\pwsh\SharedDeploy.psd1
$s = Resolve-DeploySetting -ConfigPath scripts\deploy.config.ps1 -Value @{} -Required VpsHost, VpsUser, KeyPath, WebRoot
$null = $s | Open-DeployConnection
try {
    Restore-RemoteDirectory -Live "$($s.WebRoot)/public/plugin"          # public/plugin.old
    Restore-RemoteDirectory -Live "$($s.WebRoot)/public/dist"            # public/dist.old
    Restore-RemoteItemSet -Root $s.WebRoot -Backup '.deploy/backend-prev' -ServerName 'tarotgen.io' `
        -Item 'public', 'api', 'includes', 'scripts', 'db', 'openapi.json', 'phinx.php', 'composer.json', 'composer.lock', 'vendor'
    Restore-HostConfig -Name 'tarotgen'                                  # /etc/cron.d/tarotgen's previous copy
} finally { Close-DeployConnection }
```

Run only the lines for the parts that went wrong. The previous vhost comes back with `-ServerName` (`tarotgen.io.conf.old`, and any site it retired). Code from before a release's migrations may not run on the migrated database: then restore the snapshot from before them too. A deploy run with `-NoBackup` kept none of these copies: then ship a release that undoes the change, since the deploy always ships the newest tag.

## Logs

- PHP and Apache errors: `/var/log/apache2/error.log` (`AH01071 … PHP message:` lines) and `/var/log/php8.5-fpm.log`, beside the droplet's other sites': filter by `<site folder>`. Requests: `/var/log/apache2/access.log`.
- The daily backup: `journalctl -u tarotgen-backup`.
- Retention: `journalctl -t tarotgen-retention` (a run, or a skip when that day's backup is missing).
- A deploy prints its own steps; the host keeps nothing of them beyond the release it recorded (`~/.shared-deploy/tarotgen-backend` and `-frontend` of the SSH user).

## Retention

`/etc/cron.d/tarotgen` (from `deploy/tarotgen.cron`) runs `scripts/retention.php` as www-data at 10:30 UTC, after the backup's window, and only when that day's backup exists. It deletes guest readings (`user_id IS NULL`) older than 365 days and those past the expiry a guest chose, deletes plugin draw-key codes that expired unredeemed, then runs `ANALYZE`. Preview a run without changing anything (the same DELETEs in a transaction that is rolled back), also under cron's bare environment:

```sh
cd '<site folder>' && runuser -u www-data -- env TAROT_DATA_DIR='<data folder>' php scripts/retention.php --dry-run
env -i TAROT_DATA_DIR='<data folder>' runuser -u www-data -- php '<site folder>/scripts/retention.php' --dry-run
```

## Retired files on the host

Removed on 2026-11-16, with the owner's approval, once the daily copies were confirmed (ten in `<data folder>/db/backups`): `<old backups>`, the folder the old nightly job wrote to, with the old nightly copies (14, from 2026-10-29 to 2026-11-06), the cron file from before the 2026-10-28 move of the data out of the web root, and the two copies of the old `.env`, vhost and `/etc/environment` that move kept. Nothing retired is left on the host but what the 6.0.0 deploy leaves in `<site folder>` (Upgrading to 6.0.0, step 8).

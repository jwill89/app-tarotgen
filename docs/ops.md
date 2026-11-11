# Operations

The steps a release needs beyond `task deploy`, in the order they run. The deploy itself is documented in `Get-Help .\scripts\deploy.ps1 -Full` and in AGENTS.md, section 6.

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

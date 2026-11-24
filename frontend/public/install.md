# Install & Upgrade OPBX

One command installs OPBX: a guided installer asks a few questions, generates every secret, starts the stack and checks it. Upgrades are one command too.

## Requirements

- **A Linux server or a Mac**: 4 GB RAM (8 GB recommended) and 20 GB of disk (50 GB recommended).
- **Docker 20.10+ with Compose 2.24.4+**: Or Podman with its Docker-compatible CLI. Nothing else: Node.js, PHP and Composer run inside containers.
- **Git**: To download OPBX and, later, its updates.
- **For production: a domain and your own HTTPS proxy**: OPBX does not terminate TLS. Your reverse proxy or HTTPS accelerator forwards one domain to OPBX; the installer prints the proxy configuration.
- **A Cloudonix account**: For calls. You connect it in the OPBX web app after installing.

## Install

1. **Download OPBX**

   ```bash
   git clone https://github.com/greenfieldtech-nirs/opbx.git
   cd opbx
   ```

2. **Run the installer** in a terminal (it needs to ask you questions):

   ```bash
   ./install.sh
   ```

   It asks, in plain language:

   - Production server or development machine, and the port OPBX listens on
   - Production: the public https:// address and, optionally, your proxy's IP (for real visitor IPs)
   - Development: other host names allowed to open it (a VPN or Tailscale name, the machine's IP, an ngrok domain)
   - Your organization, your name and email (you become the platform owner)
   - One company or many customer organizations on this server
   - What the site root shows: the sign-in page (default) or the OPBX homepage
   - The outgoing email service: Mailgun, Mailjet, MailerLite, Brevo, or none for now
   - Optional: Auth0 sign-in (Google, GitHub), reCAPTCHA, UserCheck, log collection in Grafana
   - Optional: the SafeLine web application firewall (monitor or block), its console port and who may open it

   If the firewall needs a command on your server (for example creating its data folder), the installer shows the exact command, waits for you to run it, and checks that it worked. You review every answer before anything is written.

3. **Save the summary.** When it finishes, the installer shows the addresses, your owner login with its generated password (shown once), the Grafana and firewall logins when those are on, and, for production, a ready-to-paste configuration for your HTTPS proxy. It is also saved to `opbx-install-summary.txt` (readable only by you). Store the password somewhere safe, then delete that file.

A shortened installer run:

```text
$ ./install.sh
◇  Is this a production server or a development machine?
│  Production
│
◇  What is your server's public address?
│  https://pbx.example.com
│
◇  Which port on this machine should OPBX listen on?
│  80
│
◇  What should people see when they open https://pbx.example.com?
│  The sign-in page
│
◇  Put a web application firewall in front of OPBX?
│  Yes, monitor only
│  ...
◇  Is this correct?
│  Yes, continue
│
│  ... building and starting (10-30 minutes the first time)
◇  OPBX is up
│
◇  Write this down now ───────────────────────╮
│  Owner login                                │
│    Email:    you@example.com                │
│    Password: XXXX-XXXX-XXXX-XXXX-XXXX       │
├─────────────────────────────────────────────╯
│
◇  All services are up
```

Interrupted? Run `./install.sh` again: it picks up where it stopped.

## Start & stop

Use `./opbx.sh` from the OPBX folder. It always starts and stops the services in the right order.

| Command | What it does |
|---|---|
| `./opbx.sh start` | Start everything in the right order and wait until every service is up. The WAF starts first when it is on. |
| `./opbx.sh stop` | Stop OPBX, then the WAF. Containers, network and data are kept. |
| `./opbx.sh restart` | Stop, then start. |
| `./opbx.sh status` | The state and health of every service, the WAF, and the URLs. |
| `./opbx.sh logs app` | Follow the logs of one service (or all, without a name). |
| `./opbx.sh start --no-waf` | Start once without the WAF. Your settings are not changed; the next start brings it back. |
| `./opbx.sh start --nofork` | Start, then stay in the foreground showing every service's log. Ctrl-C stops the stack. |

## Upgrade

From the OPBX folder:

```bash
# 1. Recommended: back up the database (to ./backups/)
./scripts/backup-database.sh
# 2. Get the new version
git pull
# 3. Update your docker-compose.yml (asks before each change)
./scripts/update-compose.sh
# 4. Rebuild and start the new version
./opbx.sh upgrade
```

`./opbx.sh upgrade` then:

1. Copies old MinIO recordings to SeaweedFS (once, older installations only)
2. Rebuilds every image with fresh base images, so security releases are picked up
3. Installs the PHP packages inside the app image (no PHP on your server)
4. Starts the WAF first when it is on, then the database, cache, storage and app, showing the app's log while the database is updated
5. Starts the rest and waits until every service is up

The first start after an upgrade can take several minutes while the database is updated; the app's log is shown meanwhile. Your settings, data and recordings are kept.

## Change settings or start over

```bash
# Change the address, the front page, the allowed host names,
# the firewall (on/off, monitor/block) or its console access
./install.sh reconfigure

# Delete all data and settings, then install again
./install.sh reset
```

**reset deletes everything**: the database, recordings, logs and settings. It lists what it will delete, offers a database backup first, and asks you to type the domain (or `RESET` on a development machine) to confirm.

## Troubleshooting

### A service is not up

See which one and why:

```bash
./opbx.sh status
./opbx.sh logs <service>
```

### The installer says a port is in use

Pick another port when asked, or stop the program that uses it. On a development machine OPBX also publishes these ports, on 127.0.0.1 only (this computer). If one is taken, the installer offers a free port instead and saves it in .env under the variable shown.

| Port | Service | Purpose | .env variable |
|---|---|---|---|
| 3306 | MySQL | The database, for a database client such as TablePlus or DBeaver | `DB_EXPOSE_PORT` |
| 6379 | Redis | Cache, queues and live call state, for redis-cli | `REDIS_EXPOSE_PORT` |
| 8080 | MCP server | Lets AI assistants (MCP clients) work with OPBX | `MCP_PORT` |
| 8181 | Dialer worker | Health endpoint of the auto-dialer service | `DIALER_WEBHOOK_HOST_PORT` |
| 8333 | SeaweedFS S3 API | Storage for recordings and archived call records, for any S3 client | `MINIO_EXPOSE_PORT` |
| 9333 | SeaweedFS master UI | Web page showing the storage cluster's state | `MINIO_CONSOLE_EXPOSE_PORT` |

### The WebSocket check times out

Live updates cannot reach the WebSocket server. On Docker hosts this is often a firewall rule Docker left behind for an earlier network. The installer prints the exact commands to find and delete it; for example:

```bash
sudo iptables-save -t raw | grep -- '-d 172.18.0.3/32'
```

### I lost the owner password

Set a new one:

```bash
docker compose exec app \
  php artisan user:reset-password <owner email>
```

### The development UI says "Blocked request" from another computer

Add the name you use (VPN, IP) to the allowed host names:

```bash
./install.sh reconfigure
```

More detail, including a manual setup without the installer, is in the [installation guide](https://developers.cloudonix.com/opbx/installation).

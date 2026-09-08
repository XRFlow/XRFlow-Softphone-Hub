# XRFlow Softphone Hub (FreePBX / PBXact)

Free GPLv3+ companion to **XRFlow Softphone**. Install on the customer PBX from Module Admin or apt.

- One-button enroll (SIP / WebRTC / WSS / AMI from *this* PBX)
- WebRTC 488 checklist + one-click repair
- Fleet hooks and Company Presence detect (does **not** fork `companypresence`)
- **No Hub license key.** Desktop seats remain the commercial product `xrflow_softphone`

Source: [github.com/XRFlow/XRFlow-Softphone-Hub](https://github.com/XRFlow/XRFlow-Softphone-Hub)

## Constraints

- Do **not** rewrite System Admin OpenVPN remotes, Easy-RSA, or `sysadmin_server1.conf`
- Do **not** put presence-sync write keys or OLA admin tokens in enroll JSON
- Do **not** commit signed zips, `module.sig` from an unsigned key, or private keys
- Do **not** add a paid Hub SKU or trial/402 gate — Sangoma will not sign a commercial module

## Install on FreePBX 17 / Debian 12 (apt)

Sangoma OS 7 and stock FreePBX 17 are Debian 12 (`bookworm`), amd64. Use the XRFlow apt repo (same key as the desktop Softphone):

```bash
sudo curl -fsSL https://xrflows.com/apt/xrflow.gpg -o /usr/share/keyrings/xrflow.gpg
echo "deb [arch=amd64 signed-by=/usr/share/keyrings/xrflow.gpg] https://xrflows.com/apt bookworm main" | sudo tee /etc/apt/sources.list.d/xrflow.list
sudo apt update
sudo apt install xrflow-softphone-hub
```

That drops the module in `/var/www/html/admin/modules/xrflowsoftphone`, runs `fwconsole ma install xrflowsoftphone`, and enables Apache `/xrflow-hub`. Then **Admin → XRFlow Softphone**.

`stable` also carries the same package if this PBX already uses the desktop Softphone source list.

Apt will pull **apache2**, **rsync**, and PHP 8.2 **cli/curl/xml/mysql/mbstring**. The package **refuses to install** (`preinst` exit 1) unless `fwconsole` is present and FreePBX framework is **17+**. PHP checks use `json_encode` / `curl_init` / PDO mysql (JSON is built into PHP 8 — there is no `php8.2-json` package on Debian 12).

## Install (lab, from git)

```bash
sudo ./scripts/install-to-freepbx.sh
fwconsole ma install xrflowsoftphone
fwconsole reload
```

Build a FreePBX module tarball (unsigned until your GPG key is signed by Sangoma):

```bash
./packaging/pack-module.sh
```

Build a .deb without publishing:

```bash
./packaging/build-deb.sh
```

Apache (lab installs without the .deb):

```
Alias /xrflow-hub /var/www/html/admin/modules/xrflowsoftphone/public
<Directory /var/www/html/admin/modules/xrflowsoftphone/public>
  Require all granted
</Directory>
```

## License

GPLv3+ (see `LICENSE`). This module is free: enroll and fleet REST do not require a key.

Desktop XRFlow Softphone seats are a separate commercial product. The GPL on this module does not grant desktop seats.

## Sangoma / FreePBX module signing

Sangoma will only sign a developer GPG key for **open-source, GPL-compatible** modules. They will not sign a commercial module. That is why the Hub is free.

See [docs/SANGOMA_SIGNING.md](docs/SANGOMA_SIGNING.md) for key generation, the Key Signing Agreement, and `sign.php`.

## Version

0.2.0 — free companion: dashboard, enroll tokens, WebRTC 488 scan + template, always-on REST. No trial, no Hub SKU.

## WebRTC template

Selected PJSIP extensions get: `webrtc=yes`, `avpf=yes`, `icesupport=yes`, `rtcp_mux=yes`, `media_encryption=dtls`, `dtls_auto_generate_cert=yes`, `direct_media=no`, `media_use_received_transport=yes`. Then **Apply Config** in FreePBX. OpenVPN remotes are never rewritten.

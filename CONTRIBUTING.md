# Contributing

This repository is the **free** FreePBX/PBXact companion to XRFlow Softphone.
Patches must keep it GPL-compatible and installable without a Hub license key.

## Rules

- License is GPLv3 or later. Add the SPDX header on new PHP files.
- Do not add a paid Hub SKU, trial clock, HTTP 402, or `<commercial>` in `module.xml`.
- Do not rewrite System Admin OpenVPN remotes, Easy-RSA, or `sysadmin_server1.conf`.
- Do not put presence-sync write keys or license-authority admin tokens in enroll JSON.
- Do not commit `module.sig`, private keys, or built `.deb` / `.tgz` files.
- Desktop seat licensing lives in the commercial client, not this module.

## Layout

| Path | Ships in the signed module? |
| --- | --- |
| `module.xml`, `*.php`, `views/`, `public/`, `LICENSE`, `README.md` | Yes |
| `docs/hub-enroll.schema.json` | Yes |
| `packaging/`, `scripts/`, `docs/SANGOMA_SIGNING.md` | No |

`packaging/stage-module.sh` is the allow-list used by the tarball and the `.deb`.

## Build

```bash
./packaging/pack-module.sh
./packaging/build-deb.sh
```

Signing: [docs/SANGOMA_SIGNING.md](docs/SANGOMA_SIGNING.md).

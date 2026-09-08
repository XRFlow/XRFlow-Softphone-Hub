# Sangoma / FreePBX module signing

The Hub is free GPLv3+ software so XRFlow can request that Sangoma sign the
developer GPG key. A signed key lets Module Admin install official Hub
tarballs without the **Unsigned module** banner, and lets FreePBX detect
tampering via `module.sig`.

Signing does **not** mean Sangoma certifies quality, merchantability, or
fitness. It only attests publisher identity and file integrity.

Official docs:

- [Module Signing (Integrity validation)](https://sangomakb.atlassian.net/wiki/spaces/FP/pages/10617484)
- [GPG Key Generation HowTo](https://sangomakb.atlassian.net/wiki/spaces/FP/pages/10093249)
- [Requesting a Key to be Signed](https://sangomakb.atlassian.net/wiki/spaces/FP/pages/10617501)
- [Signing your own modules](https://sangomakb.atlassian.net/wiki/spaces/FP/pages/10420868)
- [GPG Key Signing Agreement (PDF)](https://portal.sangoma.com/marketing/resources/2652/Sangoma%20Corporate/Terms%20of%20Service%20and%20User%20License%20Agreement/GPG-KEY-SIGNING.pdf)

## Why the Hub must stay free

Sangoma will **not** sign a commercial module. Their published rules:

- Modules must be open source and GPL-compatible
- Signing your own commercial module is not supported
- They may revoke a key if you generate revenue from a FreePBX module without
  a commercial agreement with Sangoma

Product split that matches those rules:

| Piece | License | Sold? |
| --- | --- | --- |
| This Hub (`xrflowsoftphone` FreePBX module) | GPLv3+ | No. Free companion. |
| XRFlow Softphone desktop client | Commercial | Yes. Seats (`xrflow_softphone`). |

Do **not** put a Hub license key, trial, HTTP 402, or shop SKU back into this
module. Do **not** add a `<commercial>` block to `module.xml`. Desktop seat
checks belong in the desktop app, not here.

The desktop client talking to this free module is the same pattern as any
GPL FreePBX module that provisions a separately licensed endpoint.

## 1. Generate a module-signing key

Use a dedicated key. Do not reuse the apt-repo key (`xrflow.gpg`).

```bash
./packaging/generate-signing-key.sh
```

Or by hand (Sangoma walkthrough: RSA, 4096 bits):

```bash
gpg --full-generate-key
# RSA and RSA, 4096, expiry you control
# Real name: XRFlow
# Email: code@xrflows.com   (or the identity you will prove to Sangoma)
# Comment: XRFlow FreePBX Module Signing Key
```

Publish the public key to a keyserver and note the 16-digit key id:

```bash
gpg --keyserver hkp://keyserver.ubuntu.com:80 --send-keys KEYID
gpg --fingerprint
```

Back up the **secret** key offline. If Sangoma ever believes the key is
compromised they will revoke the signature and every module that key signed
will be disabled on FreePBX systems.

**Never commit** the secret key, a passphrase, or a `.gpg` keyring.

## 2. Ask Sangoma to sign the key

1. Download and execute the [GPG Key Signing Agreement](https://portal.sangoma.com/marketing/resources/2652/Sangoma%20Corporate/Terms%20of%20Service%20and%20User%20License%20Agreement/GPG-KEY-SIGNING.pdf).
2. Prove you are the identity on the key (as Sangoma requests).
3. Identify the 8- or 16-digit key id.
4. Email the executed agreement to **code@sangoma.com**.

A draft cover note is in [sangoma-key-request-email.md](sangoma-key-request-email.md).

When they have signed it:

```bash
gpg --keyserver hkp://keyserver.ubuntu.com:80 --refresh-keys
```

You should see the FreePBX/Sangoma signature on your key (`Updated: 1` once
it has propagated).

## 3. Sign this module

Clone FreePBX [devtools](https://github.com/FreePBX/devtools) (typically
`/usr/src/devtools`). After the key is Sangoma-signed:

```bash
./packaging/pack-module.sh
# stages dist/module/xrflowsoftphone
sudo /usr/src/devtools/sign.php "$(pwd)/dist/module/xrflowsoftphone"
./packaging/pack-module.sh --tarball-only
```

`sign.php` writes `module.sig` (SHA-256 hashes of every file, clear-signed by
GPG). Ship that file **inside** the module tarball. Do not commit `module.sig`
until the key is Sangoma-signed — a locally signed `module.sig` still shows as
**Invalid Key** on customer PBXes.

If the key is not yet signed you can still produce a local signature
(`sign.php … --local KEYID`) for a lab PBX. That is not a distribution
signature.

## 4. What we ship

| Artifact | Path | Signed how |
| --- | --- | --- |
| Module tarball | `dist/xrflowsoftphone-<ver>.tgz` | `module.sig` via Sangoma-signed GPG key |
| Debian package | `dist/xrflow-softphone-hub_<ver>_all.deb` | XRFlow apt repo GPG (separate from Sangoma) |

The `.deb` is a convenience installer. Module Admin cares about the tarball
and `module.sig`. Keep both in sync (same `module.xml` version).

## 5. What must not be in the signed tree

`packaging/stage-module.sh` copies only the FreePBX module payload:

- PHP BMO class, page, views, public REST
- `module.xml`, `LICENSE`, `COPYRIGHT`, `README.md`
- `docs/hub-enroll.schema.json`

Excluded: `packaging/`, `scripts/`, `dist/`, `.git/`, this signing guide,
private keys, `.deb` files.

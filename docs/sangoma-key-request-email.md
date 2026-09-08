# Draft email to code@sangoma.com

Fill in the key id, identity proof, and attach the executed GPG Key Signing
Agreement. Do not send secret keys.

---

**To:** code@sangoma.com
**Subject:** Request to sign GPG key for XRFlow Softphone Hub (GPLv3+ FreePBX module)

Hello,

Please sign the following developer GPG key with the FreePBX master key so we
can ship a GPL-compatible third-party module with a valid `module.sig`.

- **Publisher:** XRFlow
- **Key id:** `<16-digit key id>`
- **Fingerprint:** `<gpg --fingerprint>`
- **Keyserver:** hkp://keyserver.ubuntu.com:80
- **Module:** XRFlow Softphone Hub (`rawname` `xrflowsoftphone`)
- **License:** GNU GPL v3 or later
- **Source:** https://github.com/XRFlow/XRFlow-Softphone-Hub
- **FreePBX:** 17 / PBXact 17 (Debian 12)

The Hub is free software. It is a companion that enrolls extensions on the
customer PBX for the separately licensed XRFlow Softphone desktop client. The
module itself is not sold: no Hub SKU, no trial, no activation key. We are not
requesting a commercial-module signature.

Attached:

1. Executed GPG Key Signing Agreement
2. Identity proof as requested
3. Public key export (`gpg --export --armor KEYID`)

Thank you,
`<name>`
XRFlow
code@xrflows.com
support@xrflows.com

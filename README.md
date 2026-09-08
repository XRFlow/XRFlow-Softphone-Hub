# XRFlow Softphone Hub (FreePBX / PBXact)

Enterprise companion to **XRFlow Softphone**. Install on the customer PBX from Module Admin.

- One-button enroll (SIP / WebRTC / WSS / AMI / OAuth from *this* PBX)
- Fleet monitoring and seat pool
- Company Presence proxy → Odoo board (does **not** fork `companypresence`)
- Paid SKU `xrflow_softphone_hub` (HUB-PERP / HUB-MO) — desktop seats remain `xrflow_softphone`

## Constraints

- Do **not** rewrite System Admin OpenVPN remotes, Easy-RSA, or `sysadmin_server1.conf`
- Do **not** put presence-sync write keys or OLA admin tokens in enroll JSON
- Do **not** commit signed zips or private keys

## Install (lab)

```bash
sudo ./scripts/install-to-freepbx.sh
fwconsole ma install xrflowsoftphone
fwconsole reload
```

Apache (for `/xrflow-hub/v1/*`):

```
Alias /xrflow-hub /var/www/html/admin/modules/xrflowsoftphone/public
<Directory /var/www/html/admin/modules/xrflowsoftphone/public>
  Require all granted
</Directory>
```

## License

14-day trial from install. After that, enroll/fleet REST returns **HTTP 402** until a Hub key is activated (Applications → XRFlow Softphone → License) against `https://xrflows.com/api/v1/licenses/activate`, product `xrflow_softphone_hub`, `instance_ref` = this PBX deployment UUID.

GPLv3+ (see `LICENSE`). The paid key is a commercial grant, not a replacement for the GPL.

## Version

0.1.0 — skeleton: dashboard, license activate, trial/402 gate, Company Presence detect, WebRTC 488 scan + template.

## WebRTC template

Selected PJSIP extensions get: `webrtc=yes`, `avpf=yes`, `icesupport=yes`, `rtcp_mux=yes`, `media_encryption=dtls`, `dtls_auto_generate_cert=yes`, `direct_media=no`, `media_use_received_transport=yes`. Then **Apply Config** in FreePBX. OpenVPN remotes are never rewritten.

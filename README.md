# XRFlow Softphone Hub

A free FreePBX / PBXact module that helps IT roll **XRFlow Softphone** out to many desks.

You install it on **your** phone system. You pick an extension, copy a one-time code, and the person at the desk pastes it into the app. The phone, calling settings, and office-network details fill in from this PBX — no twenty-field setup on each computer.

Hub is not a second phone system, not Sangoma Phone, and not a cloud PBX. One Hub covers one FreePBX or PBXact.

**This module is free** (GPLv3+). There is no Hub license key and no trial. You still buy [XRFlow Softphone](https://xrflows.com/softphone) seats for the people who use the desktop app.

Product page: [xrflows.com/softphone#hub](https://xrflows.com/softphone#hub)

## What you can do today

- **Enroll a desk.** Generate a 15-minute, one-use code or link. The person pastes it in XRFlow Softphone → Use Hub enroll code.
- **Check WebRTC.** See which extensions are ready for in-app calling. Repair is one click (then **Apply Config** in FreePBX).
- **See Company Presence.** Hub detects whether that module is installed and reachable. It does not replace Company Presence and does not call Odoo itself.

Coming later: fleet heartbeat (who is signed in, which app version) and assigning desktop seats from the PBX.

A single person at home can skip Hub and use the desktop wizard. Hub is the company option.

## Requirements

- FreePBX **17** or PBXact **17** (Debian 12 / Sangoma OS 7)
- 64-bit (`amd64`)
- Apache, so the enroll API can be served at `/xrflow-hub` (the desktop app opens `https://your-pbx/xrflow-hub/enroll/<code>`)
- Desktop XRFlow Softphone for each person who will call (sold separately)

The installer refuses to run if FreePBX is missing or older than 17.

## Install (recommended)

On the PBX, as root. This is the same signed apt repo used for the Ubuntu desktop app.

```bash
sudo curl -fsSL https://xrflows.com/apt/xrflow.gpg -o /usr/share/keyrings/xrflow.gpg
echo "deb [arch=amd64 signed-by=/usr/share/keyrings/xrflow.gpg] https://xrflows.com/apt bookworm main" | sudo tee /etc/apt/sources.list.d/xrflow.list
sudo apt update
sudo apt install xrflow-softphone-hub
```

If this PBX already uses the XRFlow `stable` source list for the desktop app, you can install from that list instead of adding `bookworm`.

Then open **Admin → XRFlow Softphone**.

To update later:

```bash
sudo apt update
sudo apt install --only-upgrade xrflow-softphone-hub
```

## After install

1. Open **Admin → XRFlow Softphone**.
2. On **Enroll**, find the person (type to filter — no drop-down). Check **This desk uses VPN** only if they connect through OpenVPN (usually home / off-site). Leave it unchecked for people on the office LAN.
3. If Softphone says **Needs setup**, click **Fix calling settings & enroll**. If it already says **Ready**, click **Generate enroll code**.
4. Click **Apply Config** in FreePBX (red button) after any setup.
5. Give them the code. It expires in 15 minutes and works once. They paste it in XRFlow Softphone → Use Hub enroll code.

SIP passwords and the desktop AMI login travel only when the app redeems the code over HTTPS. Do not paste enroll codes into email threads that sit around; they are short-lived on purpose.

Hub creates an Asterisk Manager user named `xrflow-hub` (not the FreePBX admin AMI login). The app uses it for call events. That user is allowed from localhost, the office LAN, and OpenVPN. It is not opened to the whole internet. After the first Hub install, click **Apply Config** so Asterisk loads the AMI user.

Hub also creates a PBX **API** application named **XRFlow Softphone Hub** (Admin → API). Enroll sends that Client ID and secret so the desktop can load **Contacts** (REST/GraphQL). FreePBX only stores a hash of the secret; Hub keeps the plaintext for enroll and does not send Company Presence or Odoo API keys.

If **Enroll** shows no people, confirm **Applications → Extensions** has users, then reload FreePBX.

### Desk phones stay working

Hub does **not** turn WebRTC on the desk-phone line. A Yealink, Poly, or other SIP phone on the same extension keeps its current settings.

Instead Hub adds a **separate softphone device** (same person, both ring). That extra device gets the calling settings the app needs (AVPF, ICE, DTLS-SRTP, and so on). You will see its device id on Enroll after setup (for example `971001` next to extension 1001).

The **WebRTC** tab is the bulk version of the same action: set up selected softphones, leave desk phones as-is, then Apply Config.

## What Hub will not change

Hub does **not** rewrite System Admin OpenVPN files, Easy-RSA, or `sysadmin_server1.conf`. Use the `.ovpn` System Admin already issued. Remotes stay as exported.

If a desk needs VPN, check **This desk uses VPN** when you enroll. Hub then tells the app to prefer the OpenVPN LAN. It still does not rewrite remotes. Permit AMI on the LAN plus the OpenVPN subnet (often `10.8.0.0/24`) for those users.

## License

This module is free software under the [GNU GPL v3 or later](LICENSE). You may install, copy, and change it.

The GPL on Hub does **not** include XRFlow Softphone desktop seats. Those are a separate commercial product.

## Support

- Email: [support@xrflows.com](mailto:support@xrflows.com)
- Company rollout questions: [xrflows.com/contactus](https://xrflows.com/contactus)
- Desktop app and seats: [xrflows.com/softphone](https://xrflows.com/softphone)

## Developers

Build, packaging, and contribution rules: [CONTRIBUTING.md](CONTRIBUTING.md).

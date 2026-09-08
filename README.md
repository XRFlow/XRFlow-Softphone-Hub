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
- Apache, so the enroll API can be served at `/xrflow-hub`
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
2. On **WebRTC**, review extensions. Select any that need repair and apply the XRFlow template, then **Apply Config**.
3. On **Enroll**, pick an extension and generate a code. Give that person the code (or the link). It expires in 15 minutes and works once.
4. They install XRFlow Softphone on Windows or Ubuntu, activate their **desktop seat** if you have not already, and choose **Use Hub enroll code**.

SIP passwords travel only when the app redeems the code over HTTPS. Do not paste enroll codes into email threads that sit around; they are short-lived on purpose.

## What Hub will not change

Hub does **not** rewrite System Admin OpenVPN files, Easy-RSA, or `sysadmin_server1.conf`. Use the `.ovpn` System Admin already issued. Remotes stay as exported.

If people work from home, permit AMI on the LAN plus the OpenVPN subnet (often `10.8.0.0/24`). Hub only *hints* at that subnet; it does not edit the VPN.

## License

This module is free software under the [GNU GPL v3 or later](LICENSE). You may install, copy, and change it.

The GPL on Hub does **not** include XRFlow Softphone desktop seats. Those are a separate commercial product.

## Support

- Email: [support@xrflows.com](mailto:support@xrflows.com)
- Company rollout questions: [xrflows.com/contactus](https://xrflows.com/contactus)
- Desktop app and seats: [xrflows.com/softphone](https://xrflows.com/softphone)

## Developers

Build, packaging, and contribution rules: [CONTRIBUTING.md](CONTRIBUTING.md).

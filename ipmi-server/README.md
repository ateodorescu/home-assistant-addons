# IPMItool server for Home Assistant

Home Assistant add-on that runs [`ipmitool`](https://linux.die.net/man/1/ipmitool) behind a small web server.
It exposes JSON endpoints for monitoring and controlling IPMI-capable servers, and includes a simple web UI.

## What is IPMI?

IPMI (Intelligent Platform Management Interface) is a standard for hardware-based server management.
It lets you monitor sensors and control power on supported machines, including when the host OS is offline.

## Features

- JSON API for device info, sensors, power actions, and custom `ipmitool` commands
- Web UI via Home Assistant Ingress (**Open Web UI**) for sensors, power commands, and custom commands
- Optional direct access on port `9595` (host networking; also available via Ingress)

## Installation

1. Add this repository in Home Assistant:  
   `https://github.com/ateodorescu/home-assistant-addons`
2. Install **IPMItool server** from the add-on store.
3. Start the add-on.

No extra configuration is required for basic use.

If you run Home Assistant in Linux/Docker **without HAOS**, deploy the standalone image instead — see [ipmi-server-standalone/README.md](../ipmi-server-standalone/README.md).

## Usage

### Home Assistant integration

Use this add-on with the companion [`ipmi` integration](https://github.com/ateodorescu/home-assistant-ipmi).

### Web UI

Open the add-on and choose **Open Web UI**. Enter BMC connection details to:

- fetch sensors
- run power on / off / cycle / reset / soft shutdown
- run a custom `ipmitool` command

### Custom commands (HTTP API)

You can call any `ipmitool` arguments through `/command`.

Example:

```text
http://YOUR_HASS_SERVER_IP:9595/command?params=-I%20lanplus%20-H%20YOUR_IPMI_SERVER_IP%20-U%20ADMIN%20-P%20YOUR_PASSWORD%20bmc%20info
```

That request runs:

```bash
ipmitool -I lanplus -H YOUR_IPMI_SERVER_IP -U ADMIN -P YOUR_PASSWORD bmc info
```

`%20` is a URL-encoded space. The response is JSON with `success` and `output` keys.

Spaces and special characters in `params` must be [URL-encoded](https://www.w3schools.com/tags/ref_urlencode.ASP).

Connection endpoints also accept the usual query params (`host`, `user`, `password`, `interface`, etc.), same as before.
Passwords in responses/debug output are anonymized as `####`.

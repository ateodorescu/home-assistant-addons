# IPMItool server

This add-on runs [`ipmitool`](https://linux.die.net/man/1/ipmitool) inside Home Assistant and exposes it as a small HTTP service. Use it to monitor sensors and control power on IPMI-capable machines (BMCs), including when the host OS is offline.

It is designed to work with the companion [**ipmi** integration](https://github.com/ateodorescu/home-assistant-ipmi), and also includes a built-in web UI.

## About IPMI

IPMI (Intelligent Platform Management Interface) is a hardware management standard found on many servers and NAS boards (Dell iDRAC, HP iLO, Super Micro, etc.). Traffic usually uses **UDP port 623**. This add-on uses **host networking** so those packets can reach BMCs on your LAN.

## Installation

1. Install the add-on from this repository.
2. Start it.
3. Optional: install the [ipmi integration](https://github.com/ateodorescu/home-assistant-ipmi) for entities and automations in Home Assistant.

There is no required add-on configuration for basic use. The only option is `log_level`.

## Web UI

Open the add-on page and choose **Open Web UI** (Ingress entry `/ui`).

From the UI you can:

- Save named BMC profiles (stored on the add-on in `/data/ipmi-servers.json`)
- Fetch sensors
- Run power on / off / cycle / reset / soft shutdown
- Run a custom `ipmitool` command (connection options are filled from the form)

### Connection tips

| Setting        | Recommendation                                                                                  |
| -------------- | ----------------------------------------------------------------------------------------------- |
| Interface      | Prefer **`lanplus`** (IPMI 2.0). Auto detect tries several interfaces and can be slower.        |
| Privilege      | Use **ADMINISTRATOR** if your BMC user requires it.                                             |
| Cipher suite   | Some BMCs need an explicit suite (common on Super Micro: try custom args `-C 3`).               |
| Firewall / ACL | Allow the **Home Assistant host IP** on the BMC’s IP access list. Your laptop IP is not enough. |

Passwords are only sent to this add-on. They are anonymized as `####` in logs and error/debug output.

## HTTP API

The service listens on container port `80` (host port **9595** by default) and via Ingress.

| Path                                                                    | Purpose                  |
| ----------------------------------------------------------------------- | ------------------------ |
| `/ui`                                                                   | Web UI                   |
| `/`                                                                     | Device info + sensors    |
| `/sensors`                                                              | Sensors only             |
| `/command?params=…`                                                     | Raw `ipmitool` arguments |
| `/power_on` `/power_off` `/power_cycle` `/power_reset` `/soft_shutdown` | Chassis power helpers    |

Common query parameters: `host`, `port` (default `623`), `user`, `password`, `interface`, `kg_key`, `privilege_level`, `extra`.

You can also pass secrets with headers: `X-Ipmi-Password`, `X-Ipmi-Kg-Key`.

### Example custom command

```text
http://HOME_ASSISTANT_IP:9595/command?params=-I%20lanplus%20-H%20BMC_IP%20-U%20ADMIN%20-P%20PASSWORD%20bmc%20info
```

That runs:

```bash
ipmitool -I lanplus -H BMC_IP -U ADMIN -P PASSWORD bmc info
```

URL-encode spaces and special characters in `params`. Responses are JSON (`success`, `output`, and related fields).

## Troubleshooting

- **504 Gateway Timeout** under Ingress: the BMC call took too long (wrong interface, unreachable host, or auto-detect trying many types). Prefer a fixed `lanplus` interface.
- **Unable to establish IPMI v2 / RMCP+ session**: check username/password, privilege, cipher suite (`-C 3` / `-C 17`), and that the BMC allows the Home Assistant host IP.
- **Works from a laptop but not the add-on**: almost always BMC IP access control or firewall — allow the HA host address.

## Support

- Add-on repository: [home-assistant-addons](https://github.com/ateodorescu/home-assistant-addons)
- Companion integration: [home-assistant-ipmi](https://github.com/ateodorescu/home-assistant-ipmi)

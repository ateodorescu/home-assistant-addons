# IPMI addon for Home Assistant

## What is IPMI?

IPMI (Intelligent Platform Management Interface) is a set of standardized specifications for
hardware-based platform management systems that makes it possible to control and monitor servers centrally.

## Home Assistant addon

This addon is a Docker container that has [`ipmitool`](https://linux.die.net/man/1/ipmitool) installed
and runs a webserver. The webserver allows us to execute `ipmitool` commands and returns a `json` object
with some results.

## Installation

Just copy the `ipmi-server` to your `addon` folder of your HASS installation.

## What should I do with it?

Have a look at this [`ipmi` integration](https://github.com/ateodorescu/home-assistant-ipmi) for HASS.

### Can I run custom commands?

Yes, you can run any custom command you want. For example
`http://YOUR_HASS_SERVER_IP:9595/command?params=-I%20lanplus%20-H%20YOUR_IPMI_SERVER_IP%20-U%20ADMIN%20-P%20YOUR_PASSWORD%20bmc%20info`
which translates to `ipmitool -I lanplus -H YOUR_IPMI_SERVER_IP -U YOUR_USER -P YOUR_PASSWORD bmc info`. `%20` stands
for `space` ([url encoding](https://www.w3schools.com/tags/ref_urlencode.ASP)). You get back a json with `success` and `output` keys.
Basically you can provide any params you want to `ipmitool`.

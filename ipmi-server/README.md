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
Have a look at this [`ipmitool` integration](https://github.com/ateodorescu/home-assistant-ipmitool) for HASS.
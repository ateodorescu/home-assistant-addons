# ipmi-server-standalone docker image

This is a docker image to run `ipmi-server` as a standalone image.

Published image: `ghcr.io/ateodorescu/ipmi-server-standalone` (`linux/amd64` and `linux/arm64`). Docker selects the matching architecture on pull.

```bash
docker pull ghcr.io/ateodorescu/ipmi-server-standalone:latest
```

## Run the container

```bash
# Map container port 80 to host port 9595
docker run -d -p 9595:80 --name ipmi-server-standalone ghcr.io/ateodorescu/ipmi-server-standalone:latest
```

## Web UI and API

- **Web UI**: open `http://HOST:9595/ui` (or `http://HOST:9595/` in a browser — it redirects to `/ui`).
- **JSON API**: `http://HOST:9595/?host=BMC_IP&user=...&password=...` (same routes as the Home Assistant add-on).

Use `http://`, not `https://`. The container serves plain HTTP on port 80.

## When do you need to use it?

Without using HAOS, there is a high probability that you will need to use it.

### Why do we need Docker image?

HAOS has a supervisor who is responsible for downloading plugins, synchronizing configuration, managing lifecycle (start/stop/update), and network isolation. The HA core program does not know how to manipulate the Docker container of the host machine, so the "Add ons" option does not appear in the menu at all.
So you need manual management. Otherwise, you can only use the IPMI protocol below v1.5 (home-assistant-ipmi by default uses a library that can handle v1.5).

# Home Assistant add-on repository

This repository has some custom add-ons for Home Assistant

- For Home Assistant add-on usage, see [ipmi-server/README.md](ipmi-server/README.md)
- For standalone Docker deployment, see [ipmi-server-standalone/README.md](ipmi-server-standalone/README.md)

> Tip: There are subtle differences between HAOS and other HA deployment methods, which may require you to use Docker for separate deployment.

# FAQ

- Q: What is this addon designed for?
- A: This addon was built to work with this Home Assistant integration https://github.com/ateodorescu/home-assistant-ipmi. In theory, you can deploy PHP yourself and configure nginx cgi to deploy it, with the code stored in `ipmi-server/rootfs/app`. Use `ipmi-server-standalone/nginx.conf` nginx config, which is not a recommended solution, but theoretically it can be done this way.

- Q: Why did I not use HAOS deployment and need a standalone image Docker?
- A: HAOS has a supervisor who is responsible for downloading plugins, synchronizing configuration, managing lifecycle (start/stop/update), and network isolation. The HA core program does not know how to manipulate the Docker container of the host machine, so the "Add ons" option does not appear in the menu at all. So you need manual management. Otherwise, you can only use the ipmi protocol below v1.5(home-assistant-ipmi By default, a library is used that can handle v1.5)

- Q: Is there an aarch64 standalone image (Raspberry Pi / HA Core)?
- A: Yes. `ghcr.io/ateodorescu/ipmi-server-standalone` is a multi-arch image (`linux/amd64` and `linux/arm64`). Docker selects the matching architecture on pull.

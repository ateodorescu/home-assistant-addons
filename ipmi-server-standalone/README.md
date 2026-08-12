# ipmi-server-standalone docker image

This is a docker image to run `ipmi-server` as a standalone image.
`bash
    docker pull ghcr.io/ateodorescu/ipmi-server-standalone:latest
   `

# Run the container

    ``` bash
    # run a docker container with the image, and map port 80 in the container to port 9595 on the host machine
    # name is ipmi-server-standalone
    docker run -d -p 9595:80 --name ipmi-server-standalone ghcr.io/ateodorescu/ipmi-server-standalone:latest
    ```

# When do you need to use it?

Without using haos, there is a high probability that you will need to use it.

## Why do we need Docker image?

HAOS has a supervisor who is responsible for downloading plugins, synchronizing configuration, managing lifecycle (start/stop/update), and network isolation. The HA core program does not know how to manipulate the Docker container of the host machine, so the "Add ons" option does not appear in the menu at all.
So you need manual management, Otherwise, you can only use the impl protocol below v1.5(home-assistant-ipmi By default, a library is used that can handle v1.5)

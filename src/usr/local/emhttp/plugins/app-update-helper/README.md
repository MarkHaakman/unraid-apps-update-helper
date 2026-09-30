**App Update Helper**

Shows detailed version information for your Docker containers and lets you update them straight from the Docker tab,
with an optional appdata backup that you can restore later.

A table compares each container's installed version with the newest one in its registry and classifies the update as
Major, Minor, Patch or Build. When an update is available, use Update to install it right away, or Backup & Update to
first stop the container and archive its appdata. The backup destination is configured under Settings > App Update Helper.
Restore brings a backup back: the appdata is replaced (the current folders are kept with a .pre-restore suffix) and
the container is recreated with the image it ran when the backup was made, pulled again by its registry digest.

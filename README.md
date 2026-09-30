# Unraid Apps Update Helper
This is an Unraid plugin showing detailed version information for your Docker containers and lets you update them,
with an optional appdata backup, straight from the Docker tab.

A table compares each container's installed version with the newest one in its registry and classifies the update as
Major, Minor, Patch or Build. When an update is available, use Update to install it right away, or Backup & Update to
first stop the container and archive its appdata. The backup destination is configured under Settings > App Update Helper.


## Installation
1. In the Unraid WebGUI, go to **Plugins → Install Plugin**.
2. Paste the following URL into the "Plugin URL" field:
   ```
   https://raw.githubusercontent.com/MarkHaakman/unraid-apps-update-helper/main/plugin/app-update-helper.plg
   ```
3. Click **Install**.

Unraid's plugin manager will periodically check this same URL for updates, so there's no need to reinstall for future releases.

## Plugin template
This plugin is created using [unraid-plugin-template](https://github.com/dkaser/unraid-plugin-template).
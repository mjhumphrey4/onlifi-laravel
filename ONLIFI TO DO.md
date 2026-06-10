Now, problem creating Installer accounts, the create user button is grayed out, i don't know if i need a new migration to enable this feature. But, the Installer account must work with the installer app in my ONLIFI-Installer directory and allow a user to add devices to the dashboard with precision, image storage, and google map links. 



Also, PPOE does not show any active PPOE clients, does not provide PPOE settings like the Pool and Profiles, we need to include these as tabs so that when creating a client, a profile can be selected with specific settings like speed limits. Also, we need options to set the user's expiry date so that they're auto removed from active and the secret deactivated when expired. In addition,  add a deactivate button that deactivates the user's secret and autoremoves them from the active interface table to disconnect them.



Remove the Placeholder on Routers and rename Routers to Accesspoints, in here, additions can only be made through the ONLIFI-Installer App. Within the dashboard, all active routers should be visible, with the Router, Image, Location and STATUS (ONLINE or Offline will come from uptime kuma later), Last Seen (ONLINE if active, last date if OFFLINE based on uptime Kuma result).



SPEED. We need to optimize the application more for speed, the loading loops are still present such as the dashboard taking some good seconds to load, need to make it feel faster. What do you recommend, should we offload some functionalities to the Mobile Money dashboard am creating, in that we simply make API calls to the other side of the app to ensure the dashboard remains with physical vouchers and live stats? JUst a recommendation is what i need, but we can optimize more for speed since we have Redis in gear.

DeployMint
==========

This branch is focused on getting DeployMint to work in single site (default) wordpress mode, as opposed to multi-site/network (MU) wordpress mode.

The main differences are:
* Each project data directory must be tied to a remote git repository that is accessible to all blogs/sites within the project.
* Uses XML-RPC to communicate with other blogs/sites
	* Note: It does not transmit your database and other files via XML-RPC, it only transmit small bits of information, such as the name of the snapshot to deploy, and which project to read from. It also transmits the name of the project and blog url's to each blog within the project anytime a blog is add/removed from the project.


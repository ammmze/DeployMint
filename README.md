DeployMint
==========

DeployMint allows you to create and deploy snapshots of your wordpress blog using GIT. This makes it extremely easy to create content, adjust themes, etc in a development environment, and then move it over to a staging environment, and then on to production. This works with both WordPress Multi-Site and Single-Site.


Installation
------------

* Place all files within wp-content/plugins/DeployMint
* Within WordPress Admin, go to Plugins and Activate the plugin 'DeployMint'
* Update DeployMint options
    * In multi-site mode, the options are in the network admin, and available only to network admins
    * In single-site mode, the options are located in the top-level wordpress admin and are available only to admins.


Managing Blogs
--------------

This is only applicable to single-site mode (when in multi-site mode, we use the blogs as defined by current wordpress network). In single-site mode, we need to define the blogs that should be available to us.

* Enter the name for the blog (currently doesn't really do anything for you, since we still just show the url)
* Optionaly ignore SSL certificate errors when making an XML-RPC request.
* Enter the Blog URL. You may optionally omit the 'http://' portion. If omitted, we will use 'https://'


Creating a Project
------------------

A Project is a group of blogs which will share snapshots. You will be able to create a snapshot of one blog within the project, and then deploy that snapshot to another blog within that same project.

* From the DeployMint admin, go to 'Manage Projects'
* Enter a name for the project...anything you like
* Enter the Git Origin location
    * This is where we will fetch/pull/push snapshots to. This should be accessible by all blogs within the project. If you are running in Multi-Site mode, you don't **need** to use this option.
* Add whichever blogs you want to the project.
    * Note: When in single-site mode, you will prompted for a username and password. This username and password should be valid for all blogs within the project. (For those who want to know what is happening here...we are making an XML-RPC request to all the blogs within the project with the updated project information, including the blogs that are associated with the project)


Creating a Snapshot
-------------------

Once a project is all setup, you may go to the project page, where you can select the blog to create snapshot. This basically makes a dump of the primary wordpress tables and copys all the themes, plugins and uploads/blogs.dir and stores it all in a branch within the git repo. If an origin is specified, it is also pushed to the origin. Note: themes and plugins are only copied in single-site mode, since in multi-site they are all shared between sites. When in single-site mode, you will be asked for a login, this is the login of the blog you are taking the snapshot of.


Deploying a Snapshot
--------------------

Once a snapshot has been created, you will see it in the list of available snapshots. You can select what to deploy (database tables, uploads, themes or plugins). Then specify which blog to deploy to. When in single-site mode, you will be asked for a login, this is the login of the blog you are deploying to.


Emergency Revert
----------------

This is currently only available in the multi-site mode. This will restore your entire wordpress network as it was prior to deploying a snapshot.


License
-------

This plugin uses the [GNU General Public License v3.0](http://www.gnu.org/licenses/gpl-3.0.html)
<?php
/**
 * @package DeployMint
 * @version 2.3.2
 */
/*
Plugin Name: DeployMint
Plugin URI: http://ammmze.github.com/DeployMint
Description: DeployMint: A staging and deployment system for Wordpress. Forked from Mark Maunder's version at <a href="http://markmaunder.com" target="_blank">markmaunder.com</a>
Author: Branden Cash <bcash@parchment.com>
Version: 2.3.2
Author URI: http://github.com/ammmze
License: GPLv3 or later
*/

require('deploymintClass.php');
require 'DeployMintInterface.php';
require 'DeployMintAbstract.php';
require 'DeployMintTools.php';
require 'DeployMintProjectTools.php';
if (is_multisite()) {
    require 'DeployMintMultiSite.php';
    $deployMintPlugin = new DeployMintMultiSite();
} else {
    require 'DeployMintSingleSite.php';
    $deployMintPlugin = new DeployMintSingleSite();
}

register_activation_hook(__FILE__, array($deployMintPlugin,'install'));
register_deactivation_hook(__FILE__, array($deployMintPlugin,'uninstall'));
add_action('plugins_loaded', array($deployMintPlugin,'checkUpdate'));

$deployMintPlugin->setup();

?>

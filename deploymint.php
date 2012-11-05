<?php
/**
 * @package DeployMint 
 * @version 1.0-dev
 */
/*
Plugin Name: DeployMint
Plugin URI: http://markmaunder.com/
Description: DeployMint: A staging and deployment system for Wordpress 
Author: Mark Maunder <mmaunder@gmail.com>
Version: 1.0-dev
Author URI: http://markmaunder.com/
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
        
$deployMintPlugin->setup();

?>

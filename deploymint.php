<?php
/**
 * @package DeployMint 
 * @version 0.1
 */
/*
Plugin Name: DeployMint
Plugin URI: http://markmaunder.com/
Description: DeployMint: A staging and deployment system for Wordpress 
Author: Mark Maunder <mmaunder@gmail.com>
Version: 0.1
Author URI: http://markmaunder.com/
*/



require('deploymintClass.php');
require 'DeployMintInterface.php';
require 'DeployMintAbstract.php';
if (is_multisite()) {
    require 'DeployMintMultiSite.php';
    $plugin = new DeployMintMultiSite();
} else {
    require 'DeployMintSingleSite.php';
    $plugin = new DeployMintSingleSite();
}
$plugin->setPdb($wpdb);

register_activation_hook(__FILE__, array($plugin,'install'));
$plugin->setup();

?>

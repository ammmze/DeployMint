<?php

class DeployMintSingleSite extends DeployMintAbstract
{
    public function adminMenu()
    {
        extract(self::getOptions(), EXTR_OVERWRITE);
        add_submenu_page("DeployMint", "Manage Projects", "Manage Projects", 'manage_options', "DeployMint", 'deploymint::deploymintMenu');
        add_menu_page("DeployMint", "DeployMint", 'manage_options', 'DeployMint', 'deploymint::deploymintMenu', WP_PLUGIN_URL . '/DeployMint/images/deployMintIcon.png');
        $projects = $this->pdb->get_results($this->pdb->prepare("SELECT id, name FROM dep_projects WHERE deleted=0"), ARRAY_A);
        for ($i = 0; $i < sizeof($projects); $i++) {
            add_submenu_page("DeployMint", "Proj: " . $projects[$i]['name'], "Proj: " . $projects[$i]['name'], 'manage_options', "DeployMintProj" . $projects[$i]['id'], 'deploymint::projectMenu' . $projects[$i]['id']);
        }
        if (!$backupDisabled) {
            add_submenu_page("DeployMint", "Emergency Revert", "Emergency Revert", 'manage_options', "DeployMintBackout", 'deploymint::undoLog');
        }
        add_submenu_page("DeployMint", "Options", "Options", 'manage_options', "DeployMintOptions", 'deploymint::myOptions');
        add_submenu_page("DeployMint", "Help", "Help", 'manage_options', "DeployMintHelp", 'deploymint::help');
    }
}
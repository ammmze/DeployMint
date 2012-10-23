<?php

class DeployMintSingleSite extends DeployMintAbstract
{
    public function adminMenu()
    {
        extract($this->getOptions(), EXTR_OVERWRITE);
        add_menu_page("DeployMint", "DeployMint", 'manage_options', self::PAGE_INDEX, array($this, 'actionIndex'), WP_PLUGIN_URL . '/DeployMint/images/deployMintIcon.png');
        add_submenu_page(self::PAGE_INDEX, "Manage Projects", "Manage Projects", 'manage_options', self::PAGE_PROJECTS, array($this, 'actionIndex'));
        $projects = $this->pdb->get_results($this->pdb->prepare("SELECT id, name FROM dep_projects WHERE deleted=0"), ARRAY_A);
        for ($i = 0; $i < sizeof($projects); $i++) {
            add_submenu_page(self::PAGE_INDEX, "Proj: " . $projects[$i]['name'], "Proj: " . $projects[$i]['name'], 'manage_options', self::PAGE_PROJECTS . '/' . $projects[$i]['id'], array($this, 'actionManageProject_' . $projects[$i]['id']));
        }
        if (!$backupDisabled) {
            add_submenu_page(self::PAGE_INDEX, "Emergency Revert", "Emergency Revert", 'manage_options', self::PAGE_REVERT, array($this, 'actionRevert'));
        }
        add_submenu_page(self::PAGE_INDEX, "Options", "Options", 'manage_options', self::PAGE_OPTIONS, array($this, 'actionOptions'));
        add_submenu_page(self::PAGE_INDEX, "Help", "Help", 'manage_options', self::PAGE_HELP, array($this, 'actionHelp'));
    }
}
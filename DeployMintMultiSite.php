<?php

class DeployMintMultiSite extends DeployMintAbstract
{

    public function setup()
    {
        parent::setup();

        if (is_network_admin()) {
            add_action('network_admin_menu', array($this,'adminMenu'));
        }
        if (!$this->allOptionsSet()) {
            add_action('network_admin_notices', array($this, 'showFillOptionsMessage'));
        }
    }

    public function adminMenu()
    {
        extract($this->getOptions(), EXTR_OVERWRITE);
        add_menu_page("DeployMint", "DeployMint", 'manage_network', self::PAGE_INDEX, array($this, 'actionIndex'), WP_PLUGIN_URL . '/DeployMint/images/deployMintIcon.png');
        add_submenu_page(self::PAGE_INDEX, "Manage Projects", "Manage Projects", 'manage_network', self::PAGE_PROJECTS, array($this, 'actionIndex'));
        $projects = $this->pdb->get_results($this->pdb->prepare("SELECT id, name FROM dep_projects WHERE deleted=0"), ARRAY_A);
        for ($i = 0; $i < sizeof($projects); $i++) {
            add_submenu_page(self::PAGE_INDEX, "Proj: " . $projects[$i]['name'], "Proj: " . $projects[$i]['name'], 'manage_network', self::PAGE_PROJECTS . '/' . $projects[$i]['id'], array($this, 'actionManageProject_' . $projects[$i]['id']));
        }
        if (!$backupDisabled) {
            add_submenu_page(self::PAGE_INDEX, "Emergency Revert", "Emergency Revert", 'manage_network', self::PAGE_REVERT, array($this, 'actionRevert'));
        }
        add_submenu_page(self::PAGE_INDEX, "Options", "Options", 'manage_network', self::PAGE_OPTIONS, array($this, 'actionOptions'));
        add_submenu_page(self::PAGE_INDEX, "Help", "Help", 'manage_network', self::PAGE_HELP, array($this, 'actionHelp'));
    }

    protected function createSnapshot($projectId, $blogId, $name, $desc)
    {
        $valid = parent::createSnapshot($projectId, $blogId, $name, $desc);
        if ($valid) {
            $this->doSnapshot($projectId, $blogId, $name, $desc);
        } else {
            throw new Exception("Could not create snapshot. Details could not be validated");
        }
    }

    protected function deploySnapshot($snapshot, $blogId, $projectId)
    {
        $valid = parent::deploySnapshot($snapshot, $blogId, $projectId);
        if ($valid) {
            $this->doDeploySnapshot($snapshot, $blogId, $projectId);
        } else {
            throw new Exception("Could not deploy snapshot. Details could not be validated");
        }
    }

    protected function getTablePrefix($blogId)
    {
        if ($blogId == 1) {
            $prefix = $this->pdb->base_prefix;
        } else {
            $prefix = $this->pdb->base_prefix . $blogId . '_';
        }
    }

    protected function doSnapshot($pid, $blogid, $name, $desc)
    {
        $opt = $this->getOptions();
        extract($opt, EXTR_OVERWRITE);
        
        $proj = $this->getProject($projectId);
        $dir = $datadir . $proj['dir'] . '/';
        $mexists = $this->pdb->get_results($this->pdb->prepare("SELECT blog_id FROM dep_members WHERE blog_id=%d AND project_id=%d AND deleted=0", $blogid, $pid), ARRAY_A);
        if (sizeof($mexists) < 1) {
            $this->ajaxError("That blog doesn't exist or is not a member of this project.");
        }
        
        parent::doSnapshot($pid, $blogid, $name, $desc);
    }

    protected function copyFilesToDataDir($blogId, $dest)
    {
        extract($this->getOptions(), EXTR_OVERWRITE);
        $this->mexec("$rsync -r -d " . WP_CONTENT_DIR . "/blogs.dir/$blogId/* $dest" . "blogs.dir/", './', null, 60);
    }

    protected function copyFilesFromDataDir($blogId, $src)
    {
        extract($this->getOptions(), EXTR_OVERWRITE);
        $files = $this->mexec("$rsync -r -d $src" . "blogs.dir/* " . WP_CONTENT_DIR . "/blogs.dir/$blogId/", './', null, 60);
    }
}
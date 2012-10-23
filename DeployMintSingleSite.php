<?php

class DeployMintSingleSite extends DeployMintAbstract
{

    public function setup()
    {
        parent::setup();

        add_action('wp_ajax_deploymint_reloadBlogs', array($this, 'actionReloadBlogs'));
        add_action('wp_ajax_deploymint_addBlog', array($this, 'actionAddBlog'));
        add_action('wp_ajax_deploymint_removeBlog', array($this, 'actionRemoveBlog'));
    }

    public function adminMenu()
    {
        extract($this->getOptions(), EXTR_OVERWRITE);
        add_menu_page("DeployMint", "DeployMint", 'manage_options', self::PAGE_INDEX, array($this, 'actionIndex'), WP_PLUGIN_URL . '/DeployMint/images/deployMintIcon.png');
        add_submenu_page(self::PAGE_INDEX, "Manage Blogs", "Manage Blogs", 'manage_options', self::PAGE_BLOGS, array($this, 'actionManageBlogs'));
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

    public function actionAddBlog()
    {
        $this->checkPerms();
        try {
            $this->addBlog($_POST['url']);
            die(json_encode(array(
                'ok' => 1
            )));
        } catch (Exception $e){
            die(json_encode(array('err' => $e->getMessage())));
        }
        
    }

    public function actionRemoveBlog()
    {
        $this->checkPerms();
        try {
            $this->removeBlog($_POST['id']);
            die(json_encode(array(
                'ok' => 1
            )));
        } catch (Exception $e){
            die(json_encode(array('err' => $e->getMessage())));
        }
        
    }

    public function actionReloadBlogs()
    {
        $this->checkPerms();
        try {
            $blogs = $this->getBlogs();
            die(json_encode(array(
                'blogs' => $blogs
            )));
        } catch (Exception $e){
            die(json_encode(array('err' => $e->getMessage())));
        }
        
    }

    protected function addBlog($url)
    {
        $this->pdb->insert('dep_blogs', array('blog_url'=>$url));
    }

    protected function removeBlog($id)
    {
        $this->pdb->update('dep_blogs', array('deleted'=>1), array('id'=>$id), array('%d'), array('%d'));
    }

    protected function getBlogs()
    {
        return $this->pdb->get_results($this->pdb->prepare("SELECT *, blog_url AS domain, id AS blog_id FROM dep_blogs WHERE deleted=0 ORDER BY blog_url ASC"), ARRAY_A);
    }

    protected function getProjectBlogs($project)
    {
        return $this->pdb->get_results($this->pdb->prepare("SELECT dep_blogs.id AS blog_id, dep_blogs.blog_url AS domain FROM dep_members, dep_blogs WHERE dep_members.deleted=0 AND dep_members.project_id=%d AND dep_members.blog_id = dep_blogs.id", $project), ARRAY_A);
    }
}
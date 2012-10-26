<?php

class DeployMintSingleSite extends DeployMintAbstract
{

    protected $isXmlrpcRequest = false;

    public function setup()
    {
        parent::setup();

        if (is_admin()) {
            add_action('admin_menu', array($this,'adminMenu'));
        }
        if (!$this->allOptionsSet()) {
            add_action('admin_notices', array($this, 'showFillOptionsMessage'));
        }

        add_action('wp_ajax_deploymint_reloadBlogs', array($this, 'actionReloadBlogs'));
        add_action('wp_ajax_deploymint_addBlog', array($this, 'actionAddBlog'));
        add_action('wp_ajax_deploymint_removeBlog', array($this, 'actionRemoveBlog'));

        add_filter('xmlrpc_methods', array($this, 'xmlrpcMethods'));
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
            //add_submenu_page(self::PAGE_INDEX, "Emergency Revert", "Emergency Revert", 'manage_options', self::PAGE_REVERT, array($this, 'actionRevert'));
        }
        add_submenu_page(self::PAGE_INDEX, "Options", "Options", 'manage_options', self::PAGE_OPTIONS, array($this, 'actionOptions'));
        add_submenu_page(self::PAGE_INDEX, "Help", "Help", 'manage_options', self::PAGE_HELP, array($this, 'actionHelp'));
    }

    public function initHandler()
    {
        parent::initHandler();
        if (is_admin()) {
            wp_enqueue_script('deploymint-ss-js', plugin_dir_url(__FILE__) . 'js/deploymint.ss.js', array('jquery'));
        }
    }

    public function actionAddBlog()
    {
        $this->checkPerms();
        try {
            $this->addBlog($_POST['url'], $_POST['name'], $_POST['ignoreCert']);
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

    public function xmlrpcMethods($methods)
    {
        $methods['deploymint.createSnapshot'] = array($this, 'xmlrpcCreateSnapshot');
        $methods['deploymint.deploySnapshot'] = array($this, 'xmlrpcDeploySnapshot');
        $methods['deploymint.addUpdateProject'] = array($this, 'xmlrpcAddUpdateProject');
        return $methods;
    }

    protected function xmlrpcAuth($args)
    {
        $username   = $args[0];
        $password   = $args[1];

        global $wp_xmlrpc_server;

        // Let's run a check to see if credentials are okay
        if ( !$user = $wp_xmlrpc_server->login($username, $password) ) {
            return $wp_xmlrpc_server->error;
        } else {
            return true;
        }
    }

    public function xmlrpcCreateSnapshot($args)
    {
        $this->isXmlrpcRequest = true;
        $data = $args[2];

        try {
            $auth = $this->xmlrpcAuth($args);
            if ($auth === true) {
                $this->doSnapshot($data['projectId'], $data['blogId'], $data['name'], $data['desc']);
            } else {
                throw new Exception(print_r($auth,true));
            }
        } catch (Exception $e) {
            return array(
                "success"=>false,
                "error"=>$e->getMessage(),
            );
        }
        
        return array("success"=>true);
    }

    public function xmlrpcDeploySnapshot($args)
    {
        $this->isXmlrpcRequest = true;
        $data = $args[2];

        try {
            $auth = $this->xmlrpcAuth($args);
            if ($auth === true) {
                $this->doDeploySnapshot($data['snapshot'], $data['blogId'], $data['projectId']);
            } else {
                throw new Exception(print_r($auth,true));
            }
        } catch (Exception $e) {
            return array(
                "success"=>false,
                "error"=>$e->getMessage()
            );
        }
        
        return array("success"=>true);
    }

    public function xmlrpcAddUpdateProject($args)
    {
        $this->isXmlrpcRequest = true;
        $data = $args[2];

        try {
            $auth = $this->xmlrpcAuth($args);
            if ($auth === true) {
                //throw new Exception(print_r($data,true));
                $this->doAddUpdateProject($data['project'], $data['blogs']);
            } else {
                throw new Exception(print_r($auth,true));
            }
        } catch (Exception $e) {
            return array(
                "success"=>false,
                "error"=>$e->getMessage()
            );
        }
        
        return array("success"=>true);
    }

    protected function doAddUpdateProject($project, $blogs)
    {
        // Check if project exists
        if (!$this->projectExists($project['name'])) {
            // Create project
            $this->createProject($project['name'], $project['origin']);
        }

        $myProject = $this->getProjectByName($project['name']);

        $blogsToKeep = array();
        foreach($blogs as $b) {
            // Add the blog, if it already exists, it just updates the record
            $this->addBlog($b['blog_url'], $b['blog_name'], $b['ignore_cert']);
            $currentBlog = $this->getBlogByUrl($b['blog_url']);

            // Add blog to project
            $this->addBlogToProject($currentBlog['id'], $myProject['id']);

            $blogsToKeep[] = $currentBlog['id'];
        }

        // Remove other blogs
        $blogsToRemove = array_diff($this->getBlogsIds(), $blogsToKeep);
        foreach($blogsToRemove as $bid) {
            $this->removeBlogFromProject($bid, $myProject['id']);
        }
    }

    protected function addBlog($url, $name, $ignoreCert)
    {
        if (!$this->blogExistsByUrl($url)){
            $this->pdb->insert('dep_blogs', array('blog_url'=>$url, 'blog_name'=>$name, 'ignore_cert'=>$ignoreCert), array('%s','%s','%d'));
        } else {
            $this->pdb->update('dep_blogs', array('blog_url'=>$url, 'blog_name'=>$name, 'ignore_cert'=>$ignoreCert), array('blog_url'=>$url,'deleted'=>0), array('%s','%s','%d'));
        }
    }

    protected function removeBlog($id)
    {
        $this->pdb->update('dep_blogs', array('deleted'=>1), array('id'=>$id), array('%d'), array('%d'));
        $this->pdb->update('dep_members', array('deleted'=>1), array('blog_id'=>$id), array('%d'), array('%d'));
    }

    protected function blogExistsByUrl($url)
    {
        $result = $this->pdb->get_results($this->pdb->prepare("SELECT id FROM dep_blogs WHERE blog_url=%s AND deleted=0", $url), ARRAY_A);
        return sizeof($result) > 0;
    }

    protected function getBlog($id)
    {
        return $this->pdb->get_row($this->pdb->prepare("SELECT *, blog_url AS domain, id AS blog_id FROM dep_blogs WHERE id = %d ORDER BY domain ASC", array($id)), ARRAY_A);
    }

    protected function getBlogByUrl($url)
    {
        return $this->pdb->get_row($this->pdb->prepare("SELECT *, blog_url AS domain, id AS blog_id FROM dep_blogs WHERE blog_url = %s AND deleted=0 ORDER BY domain ASC", array($url)), ARRAY_A);
    }

    protected function getBlogs()
    {
        return $this->pdb->get_results($this->pdb->prepare("SELECT *, blog_url AS domain, id AS blog_id FROM dep_blogs WHERE deleted=0 ORDER BY blog_url ASC"), ARRAY_A);
    }

    protected function getProjectBlogs($projectId)
    {
        return $this->pdb->get_results($this->pdb->prepare("SELECT *, dep_blogs.id AS blog_id, dep_blogs.blog_url AS domain FROM dep_members, dep_blogs WHERE dep_members.deleted=0 AND dep_blogs.deleted=0 AND dep_members.project_id=%d AND dep_members.blog_id = dep_blogs.id", $projectId), ARRAY_A);
    }

    protected function removeBlogFromProject($blogId, $projectId, $username=null, $password=null)
    {
        // Get blog id's, prior to removing it, so that we can notify the removed blog, itself from the project
        $blogs = $this->getProjectBlogsIds($projectId);
        
        // Remove the blog from the project
        parent::removeBlogFromProject($blogId, $projectId);

        // Notify blogs that the project has changed
        $this->updateBlogsWithProject($blogs, $projectId, $username, $password);
        return true;
    }

    protected function addBlogToProject($blogId, $projectId, $username=null, $password=null)
    {
        // Add blog to the project
        parent::addBlogToProject($blogId, $projectId);

        // Get blog id's
        $blogs = $this->getProjectBlogsIds($projectId);

        // Notify blogs that the project has changed
        $this->updateBlogsWithProject($blogs, $projectId, $username, $password);
        return true;
    }

    protected function updateBlogsWithProject($blogIds, $projectId, $username, $password)
    {
        // If 
        if ($this->isXmlrpcRequest){
            return;
        }
        $data = array(
            'project'   => $this->getProject($projectId),
            'blogs'     => $this->getProjectBlogs($projectId),
        );
        //$data = array('projectData'=>json_encode($data));

        foreach($blogIds as $id) {
            
            try {
                $blog = $this->getBlog($id);
                if ($blog['ignore_cert']==1){
                    add_filter( 'https_local_ssl_verify', '__return_false' );
                } else {
                    remove_filter( 'https_local_ssl_verify', '__return_false' );
                }
                $this->doXmlrpcRequest($data, 'deploymint.addUpdateProject', $blog['blog_url'] . '/xmlrpc.php', $username, $password);
            } catch (Exception $e) {
                //echo $e->getMessage();
            }
            
        }

    }

    protected function createSnapshot($projectId, $blogId, $name, $desc, $username=null, $password=null)
    {
        $blog = $this->getBlog($blogId);
        if ($blog['ignore_cert']==1){
            add_filter( 'https_local_ssl_verify', '__return_false' );
        }
        $valid = parent::createSnapshot($projectId, $blogId, $name, $desc);
        if ($valid) {
            $data = array(
                'projectId' => $projectId,
                'blogId'    => $blogId,
                'name'      => $name,
                'desc'      => $desc,
            );
            return $this->doXmlrpcRequest($data, 'deploymint.createSnapshot', $blog['blog_url'] . '/xmlrpc.php', $username, $password);
        } else {
            throw new Exception("Could not create snapshot. Details could not be validated");
        }
    }

    protected function doSnapshot($projectId, $blogId, $name, $desc)
    {
        parent::doSnapshot($projectId, $blogId, $name, $desc);
        //throw new Exception('Snapshot creation is not fully implemented yet');
    }

    protected function copyFilesToDataDir($blogId, $dest)
    {
        extract($this->getOptions(), EXTR_OVERWRITE);
        $this->mexec("$rsync -rd --exclude '.git' " . WP_CONTENT_DIR . "/uploads/* $dest" . "uploads/", './', null, 60);
        $this->mexec("$rsync -rd --exclude '.git' " . WP_CONTENT_DIR . "/plugins/* $dest" . "plugins/", './', null, 60);
        $this->mexec("$rsync -rd --exclude '.git'" . WP_CONTENT_DIR . "/themes/* $dest"  . "themes/" , './', null, 60);
    }

    protected function copyFilesFromDataDir($blogId, $src)
    {
        extract($this->getOptions(), EXTR_OVERWRITE);
        $files = $this->mexec("$rsync -rd --exclude '.git' $src" . "uploads/* " . WP_CONTENT_DIR . "/uploads/", './', null, 60);
        $files = $this->mexec("$rsync -rd --exclude '.git' $src" . "plugins/* " . WP_CONTENT_DIR . "/plugins/", './', null, 60);
        $files = $this->mexec("$rsync -rd --exclude '.git' $src" . "themes/* "  . WP_CONTENT_DIR . "/themes/" , './', null, 60);
    }

    protected function deploySnapshot($snapshot, $blogId, $projectId, $username=null, $password=null)
    {
        $blog = $this->getBlog($blogId);
        if ($blog['ignore_cert']==1){
            add_filter( 'https_local_ssl_verify', '__return_false' );
        }
        $valid = parent::deploySnapshot($snapshot, $blogId, $projectId);
        if ($valid) {
            $data = array(
                'snapshot'  => $snapshot,
                'blogId'    => $blogId,
                'projectId' => $projectId,
            );
            return $this->doXmlrpcRequest($data, 'deploymint.deploySnapshot', $blog['blog_url'] . '/xmlrpc.php', $username, $password);
        } else {
            throw new Exception("Could not deploy snapshot. Details could not be validated");
        }
    }

    protected function doXmlrpcRequest($data, $method, $url=null, $username, $password)
    {
        // TODO: Prompt for login details
        $params = array($username, $password, $data);
        $params = xmlrpc_encode_request($method, $params);

        if ($url==null){
            $url = get_bloginfo('pingback_url');
            $url = preg_replace('/^http:\/\//i', 'https://', $url);
        }
        if (preg_match('/^http(s)?:\/\//i', $url) === 0) {
            $url = 'https://' . $url;
        }

        // TODO: Real request URL
        $result = wp_remote_post($url, array(
            'body'      => $params,
            'sslverify' => apply_filters('https_local_ssl_verify', true),
            'timeout'   => 60,
        ));
        if (is_wp_error($result)){
            throw new Exception($result->get_error_message());
        }
        
        if ($result['response']['code'] != 200) {
            throw new Exception("XML-RPC request to create snapshot failed.");
        }
        $response = xmlrpc_decode($result['body']);
        
        if (!is_array($response)) {
            throw new Exception(print_r($result, true));
        }
        if (xmlrpc_is_fault($response)) {
            throw new Exception($response['faultString'], $response['faultCode']);
        }
        if (!$response['success']) {
            throw new Exception('Request failed. Remote responded with: ' . $response['error']);
        }
        return true;
    }
}
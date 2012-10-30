<?php

abstract class DeployMintAbstract implements DeployMintInterface
{

    const PAGE_INDEX    = 'deploymint';
    const PAGE_BLOGS    = 'deploymint/blogs';
    const PAGE_PROJECTS = 'deploymint/projects';
    const PAGE_REVERT   = 'deploymint/revert';
    const PAGE_OPTIONS  = 'deploymint/options';
    const PAGE_HELP     = 'deploymint/help';

    protected $wpTables = array('commentmeta', 'comments', 'links', 'options', 'postmeta', 'posts', 'term_relationships', 'term_taxonomy', 'terms');
    protected $pdb;
    protected $defaultOptions = array(
        'git'               => '',
        'mysql'             => '',
        'mysqldump'         => '',
        'rsync'             => '',
        'numBackups'        => 5,
        'datadir'           => '',
        'preserveBlogName'  => 1,
        'backupDisabled'    => 0,
        'temporaryDatabase' => '',
        'backupDatabase'    => '',
    );

    public function __construct()
    {
        global $wpdb;
        $this->pdb = $wpdb;

    }

    public function install()
    {
        $this->createSchema();
        $this->updateOptions($this->detectOptions());
    }

    public function uninstall()
    {
        
    }

    public function setup()
    {
        add_action('init', array($this, 'initHandler'));
        add_action('wp_enqueue_scripts', array($this, 'enqueueScripts'));
        
        add_action('wp_ajax_deploymint_createProject', array($this, 'actionCreateProject'));
        add_action('wp_ajax_deploymint_deleteProject', array($this, 'actionRemoveProject'));
        add_action('wp_ajax_deploymint_reloadProjects', array($this, 'actionReloadProjects'));

        add_action('wp_ajax_deploymint_addBlogToProject', array($this, 'actionAddBlogToProject'));
        add_action('wp_ajax_deploymint_removeBlogFromProject', array($this, 'actionRemoveBlogFromProject'));
        add_action('wp_ajax_deploymint_updateOrigin', array($this, 'actionUpdateOrigin'));

        add_action('wp_ajax_deploymint_updateCreateSnapshot', array($this, 'actionGetProjectBlogs'));
        add_action('wp_ajax_deploymint_createSnapshot', array($this, 'actionCreateSnapshot'));

        add_action('wp_ajax_deploymint_updateDeploySnapshot', array($this, 'actionGetDeployOptions'));
        add_action('wp_ajax_deploymint_deploySnapshot', array($this, 'actionDeploySnapshot'));
        
        add_action('wp_ajax_deploymint_deploy', array($this, 'ajax_deploy_callback'));

        add_action('wp_ajax_deploymint_updateSnapDesc', array($this, 'ajax_updateSnapDesc_callback'));
        
        add_action('wp_ajax_deploymint_undoDeploy', array($this, 'ajax_undoDeploy_callback'));
        
        add_action('wp_ajax_deploymint_deleteBackups', array($this, 'ajax_deleteBackups_callback'));
        add_action('wp_ajax_deploymint_updateOptions', array($this, 'ajax_updateOptions_callback'));

        $opt = $this->getOptions();
        DeployMintProjectTools::setGit($opt['git']);
        
    }

    protected function createSchema()
    {
        $success = $this->pdb->query("CREATE TABLE IF NOT EXISTS dep_options (
            name varchar(100) NOT NULL PRIMARY KEY,
            val varchar(255) default ''
            ) default charset=utf8");
        if (!$success) {
            die($this->pdb->print_error());
        }
        $success = $this->pdb->query("CREATE TABLE IF NOT EXISTS dep_projects (
            id int UNSIGNED NOT NULL auto_increment PRIMARY KEY,
            ctime int UNSIGNED NOT NULL,
            name varchar(100) NOT NULL,
            dir varchar(120) NOT NULL,
            origin varchar(255) NOT NULL,
            project_uuid varchar(36) NOT NULL,
            deleted tinyint UNSIGNED default 0
        ) default charset=utf8");
        if (!$success) {
            die($this->pdb->print_error());
        }
        $success = $this->pdb->query("CREATE TABLE IF NOT EXISTS dep_members (
            blog_id int UNSIGNED NOT NULL,
            project_id int UNSIGNED NOT NULL,
            deleted tinyint UNSIGNED default 0,
            KEY k1(blog_id, project_id)
        ) default charset=utf8");
        if (!$success) {
            die($this->pdb->print_error());
        }
        $success = $this->pdb->query("CREATE TABLE IF NOT EXISTS dep_blogs (
            id int UNSIGNED NOT NULL auto_increment PRIMARY KEY,
            blog_url varchar(255) NOT NULL,
            blog_name varchar(255) NOT NULL,
            blog_uuid varchar(36) NOT NULL,
            ignore_cert tinyint UNSIGNED default 0,
            deleted tinyint UNSIGNED default 0
        ) default charset=utf8");
        if (!$success) {
            die($this->pdb->print_error());
        }
    }

    protected function detectOptions()
    {
        $options = $this->getOptions();
        foreach (array('git', 'mysql', 'mysqldump', 'rsync') as $n) {
            $options[$n] = $options[$n] ? $options[$n] : trim(DeployMintTools::mexec("which $n"));
        }
        return $options;
    }

    protected function updateOptions($o)
    {
        foreach ($o as $n => $v) {
            $this->setOption($n, $v);
        }
    }

    protected function setOption($name, $val)
    {
        $this->pdb->query($this->pdb->prepare("INSERT INTO dep_options (name, val) VALUES (%s, %s) ON DUPLICATE KEY UPDATE val=%s", $name, $val, $val));
    }

    protected function getOptions($createTemporaryDatabase = false, $createBackupDatabase = false)
    {
        $res = $this->pdb->get_results($this->pdb->prepare("SELECT name, val FROM dep_options"), ARRAY_A);
        $options = $this->getDefaultOptions();
        for ($i = 0; $i < sizeof($res); $i++) {
            $options[$res[$i]['name']] = $res[$i]['val'];
        }
        $options['backupDisabled'] = ($options['backupDisabled'] == '1');
        $options['temporaryDatabaseCreated'] = false;
        if ($options['temporaryDatabase'] == '' && $createTemporaryDatabase) {
            for ($i = 1; $i < 10; $i++) {
                $options['temporaryDatabase'] = 'dep_tmpdb' . preg_replace('/\./', '', microtime(true));
                $res = $this->pdb->get_results($this->pdb->prepare("SHOW TABLES FROM " . $options['temporaryDatabase']), ARRAY_A);
                if (sizeof($res) < 1) {
                    break;
                }
                if ($i > 4) {
                    $this->ajaxError("We could not create a temporary database name after 5 tries. You may not have the create DB privelege.");
                }
            }
            $this->pdb->query($this->pdb->prepare("CREATE DATABASE " . $options['temporaryDatabase']));
            $options['temporaryDatabaseCreated'] = true;
        }
        $options['backupDatabaseCreated'] = false;
        if ($createBackupDatabase && !$options['backupDisabled'] && $options['numBackups'] != 1) {
            $dbPrefix = ($options['backupDatabase'] == '') ? 'depbak' : $options['backupDatabase'];
            $options['backupDatabase'] = $dbPrefix . '__' . preg_replace('/\./', '', microtime(true));
            $this->pdb->query('CREATE DATABASE ' . $options['backupDatabase']) || $this->ajaxError('Could not create backup database. ' . mysql_error($dbh));
            $options['backupDatabaseCreated'] = true;
        }
        return $options;
    }

    protected function allOptionsSet()
    {
        $options = $this->getOptions();
        foreach (array('git', 'mysql', 'mysqldump', 'rsync', 'datadir') as $v) {
            if (!$options[$v]) {
                return false;
            }
        }
        if (!preg_match('/^\d+$/', $options['numBackups'])) {
            return false;
        }
        return true;
    }

    protected function getDefaultOptions()
    {
        return $this->defaultOptions;
    }

    protected function checkPerms()
    {
        if (!is_user_logged_in()) {
            die("<h2>You are not logged in.</h2>");
        }
        if ( (is_multisite() && !current_user_can('manage_network')) 
            || !is_multisite() && !current_user_can('manage_options')) {
            die("<h2>You don't have permission to access this page.</h2><p>You need the 'manage_network' Super Admin capability to use DeployMint.</p>");
        }
    }

    protected function ajaxError($msg)
    {
        die(json_encode(array('err' => $msg)));
    }

    public function setPdb($pdb)
    {
        $this->pdb = $pdb;
    }

    public function getPdb()
    {
        return $this->pdb;
    }

    public function enqueueScripts()
    {
        //wp_deregister_script( 'jquery' );
        //wp_enqueue_script('jquery', plugin_dir_url( __FILE__ ) . 'js/jquery-1.6.2.js', array( ) );
    }

    public function initHandler()
    {
        if (is_admin()) {
            wp_enqueue_script('jquery-templates', plugin_dir_url(__FILE__) . 'js/jquery.tmpl.min.js', array('jquery'));
            wp_enqueue_script('jquery-leadmodal-js', plugin_dir_url(__FILE__) . 'js/jquery.easyModal.min.js', array('jquery'));
            wp_enqueue_script('deploymint-js', plugin_dir_url(__FILE__) . 'js/deploymint.js', array('jquery'));
            wp_localize_script('deploymint-js', 'DeployMintVars', array(
                'ajaxURL' => admin_url('admin-ajax.php')
            ));
            wp_register_style('DeployMintCSS',  plugins_url('css/admin.css', __FILE__));
            wp_enqueue_style('DeployMintCSS');
        }
    }

    public function actionIndex()
    {
        if (!$this->allOptionsSet()) {
            return $this->actionOptions();
        }
        include 'views/index.php';
    }

    public function actionManageProjects()
    {
        return $this->actionIndex();
    }

    public function actionManageBlogs()
    {
        if (!$this->allOptionsSet()) {
            return $this->actionOptions();
        }
        include 'views/manageBlogs.php';
    }

    public function actionManageProject($id)
    {
        extract($this->getOptions(), EXTR_OVERWRITE);
        if (!$this->allOptionsSet()) {
            return $this->actionOptions();
        }
        $this->checkPerms();
        if (!$this->allOptionsSet()) {
            echo '<div class="wrap"><h2 class="depmintHead">Please visit the options page and configure all options</h2></div>';
            return;
        }
        $proj = $this->getProject($id);
        $dir = $datadir . $proj['dir'] . '/';
        DeployMintProjectTools::setRemote($dir, $proj['origin']);
        if (strlen($proj['origin']) > 0 && !DeployMintProjectTools::connectedToRemote($dir)) {
            $this->showMessage("Please ensure that your project data directory ($dir) has access to its remote repository at {$proj['origin']}");
            return;
        }
        include 'views/manageProject.php';
    }

    public function actionRevert()
    {
        $this->checkPerms();
        if (!$this->allOptionsSet()) {
            return $this->actionOptions();
        }
        extract($this->getOptions(), EXTR_OVERWRITE);
        $dbuser = DB_USER;
        $dbpass = DB_PASSWORD;
        $dbhost = DB_HOST;
        $dbname = DB_NAME;
        $dbh = mysql_connect($dbhost, $dbuser, $dbpass, true);
        mysql_select_db($dbname, $dbh);
        $res1 = mysql_query("SHOW DATABASES", $dbh);
        if (mysql_error($dbh)) {
            $this->ajaxError("A database error occured: " . substr(mysql_error($dbh), 0, 200));
        }
        
        function readBackupData($dbname, $dbh)
        {
            $res2 = mysql_query("SELECT * FROM $dbname.dep_backupdata", $dbh);
            if (mysql_error($dbh)) {
                $this->ajaxError("A database error occured: " . substr(mysql_error($dbh), 0, 200));
            }
            $dbData = array();
            while ($row2 = mysql_fetch_array($res2, MYSQL_ASSOC)) {
                $dbData[$row2['name']] = $row2['val'];
            }
            $dbData['dbname'] = $dbname;
            $dbData['deployTimeH'] = date('l jS \of F Y h:i:s A', sprintf('%d', $dbData['deployTime']));
            return $dbData;
        }

        $dbs = array();
        if ($backupDatabase == '' || ($numBackups != 1)) {
            while ($row1 = mysql_fetch_array($res1, MYSQL_NUM)) {
                $dbPrefix = ($backupDatabase == '') ? 'depbak' : $backupDatabase;
                if (preg_match('/^' . $dbPrefix . '__/', $row1[0])) {
                    array_push($dbs, readBackupData($row1[0], $dbh));
                }
            }

            function deployTimeSort($b, $a)
            {
                if ($a['deployTime'] == $b['deployTime']) {
                    return 0;
                } return ($a['deployTime'] < $b['deployTime']) ? -1 : 1;
            }

            usort($dbs, 'deployTimeSort');
        } else {
            if (!$backupDisabled) {
                array_push($dbs, readBackupData($backupDatabase, $dbh));
            }
        }

        include 'views/revert.php';
    }

    public function actionOptions()
    {
        $opt = $this->getOptions();
        include 'views/options.php';
    }

    public function actionHelp()
    {
        include 'views/help.php';
    }

    public function __call($name, $args)
    {
        $matches = array();
        if (preg_match('/^actionManageProject_(\d+)$/', $name, &$matches)) {
            $this->actionManageProject($matches[1]);
        } else {
            die("Method $name doesn't exist!");
        }
    }

    public function actionCreateProject()
    {
        $this->checkPerms();
        $name = $_POST['name'];
        $origin = $_POST['origin'];
        try {
            if ($this->createProject($name, $origin)) {
                die(json_encode(array('ok' => 1)));
            }
        } catch (Exception $e) {
            die(json_encode(array('err' => $e->getMessage())));
        }
        
    }

    public function actionRemoveProject()
    {
        $this->checkPerms();
        $id = $_POST['id'];
        try {
            if ($this->removeProject($id)) {
                die(json_encode(array('ok' => 1)));
            }
        } catch (Exception $e) {
            die(json_encode(array('err' => $e->getMessage())));
        }
    }

    public function actionReloadProjects()
    {
        $this->checkPerms();
        extract($this->getOptions(), EXTR_OVERWRITE);
        try {
            $projects = $this->getProjects();
            for ($i = 0; $i < sizeof($projects); $i++) {
                $dir = $datadir . $projects[$i]['dir'] . '/';
                DeployMintProjectTools::setRemote($dir, $projects[$i]['origin']);
                $projects[$i]['originAvailable'] = DeployMintProjectTools::connectedToRemote($dir);
                $projects[$i]['memberBlogs'] = $this->getProjectBlogs($projects[$i]['id']);
                $projects[$i]['nonmemberBlogs'] = $this->getBlogsNotInProject($projects[$i]['id']);
                $projects[$i]['numNonmembers'] = sizeof($projects[$i]['nonmemberBlogs']);
            }
            die(json_encode(array(
                'projects' => $projects
            )));
        } catch (Exception $e){
            die(json_encode(array('err' => $e->getMessage())));
        }
        
    }

    public function actionAddBlogToProject()
    {
        $this->checkPerms();
        try {
            if ($this->addBlogToProject($_POST['blogID'], $_POST['projectID'], $_POST['username'], $_POST['password'])) {
                die(json_encode(array('ok' => 1)));
            }
        } catch (Exception $e){
            die(json_encode(array('err' => $e->getMessage())));
        }
        
    }

    public function actionRemoveBlogFromProject()
    {
        $this->checkPerms();
        try {
            if ($this->removeBlogFromProject($_POST['blogID'], $_POST['projectID'], $_POST['username'], $_POST['password'])) {
                die(json_encode(array('ok' => 1)));
            }
        } catch (Exception $e){
            die(json_encode(array('err' => $e->getMessage())));
        }
        
    }

    public function actionUpdateOrigin()
    {
        $this->checkPerms();
        try {
            if ($this->updateOrigin($_POST['projectId'], $_POST['origin'])) {
                die(json_encode(array('ok' => 1)));
            }
        } catch (Exception $e){
            die(json_encode(array('err' => $e->getMessage())));
        }
        
    }

    protected function updateOrigin($projectId, $origin)
    {
        $opt = $this->getOptions();
        $proj = $this->getProject($projectId);
        $dir = $opt['datadir'] . $proj['dir'] . '/';
        $this->pdb->update('dep_projects', array('origin'=>$origin), array('id'=>$projectId));
        DeployMintProjectTools::setRemote($dir, $origin);
        return true;
    }

    public function actionGetProjectBlogs()
    {
        $this->checkPerms();
        try {
            $blogs = $this->getProjectBlogs($_REQUEST['projectid']);
            die(json_encode(array('blogs'=>$blogs)));
        } catch (Exception $e){
            die(json_encode(array('err' => $e->getMessage())));
        }
    }

    protected function projectExists($name)
    {
        $result = $this->pdb->get_results($this->pdb->prepare("SELECT name FROM dep_projects WHERE name=%s AND deleted=0", $name), ARRAY_A);
        return sizeof($result) > 0;
    }

    protected function createProject($name, $origin, $uuid=null)
    {
        $this->checkPerms();
        $opt = $this->getOptions();
        extract($opt, EXTR_OVERWRITE);
        if ($this->projectExists($name)) {
            throw new Exception('A project with that name already exists');
        }
        if ($uuid == null) {
            $uuid = DeployMintTools::generateUUID();
        }
        $dir = $name;
        $dir = preg_replace('/[^a-zA-Z0-9]+/', '_', $dir);
        $fulldir = $dir . '-1';
        $counter = 2;
        while (is_dir($datadir . $fulldir)) {
            $fulldir = preg_replace('/\-\d+$/', '', $fulldir);
            $fulldir .= '-' . $counter;
            $counter++;
            if ($counter > 1000) {
                throw new Exception('Too many directories already exist starting with "'.$dir.'"');
            }
        }

        $finaldir = $datadir . $fulldir;
        if (!@mkdir($finaldir, 0755)) {
            throw new Exception('Could not create directory ' . $finaldir);
        }
        $git1 = DeployMintTools::mexec("$git init ; $git add . ", $finaldir);
        if (strlen($origin) > 0) {
            DeployMintProjectTools::setRemote($finaldir, $origin);
            DeployMintProjectTools::fetch($finaldir);
        }
        $insertToDb = $this->pdb->query($this->pdb->prepare("INSERT INTO dep_projects (ctime, name, dir, origin, project_uuid) VALUES (unix_timestamp(), %s, %s, %s, %s)", $name, $fulldir, $origin, $uuid));
        if ($insertToDb) {
            return true;
        } else {
           rmdir($finaldir);
           throw new Exception($this->pdb->last_error);
           
         }
        
    }

    protected function removeProject($id)
    {
        $this->pdb->query($this->pdb->prepare("UPDATE dep_members SET deleted=1 WHERE project_id=%d", $_POST['blogID'], $_POST['projectID']));
        $this->pdb->query($this->pdb->prepare("UPDATE dep_projects SET deleted=1 WHERE id=%d", $_POST['projectID']));
        return true;
    }

    protected function getProject($id)
    {
        return $this->pdb->get_row($this->pdb->prepare("SELECT * FROM dep_projects WHERE id=%d AND deleted=0", array($id)), ARRAY_A);
    }

    protected function getProjectByName($name)
    {
        return $this->pdb->get_row($this->pdb->prepare("SELECT * FROM dep_projects WHERE name=%s AND deleted=0", array($name)), ARRAY_A);
    }

    protected function getProjectByUUID($uuid)
    {
        return $this->pdb->get_row($this->pdb->prepare("SELECT * FROM dep_projects WHERE project_uuid=%s AND deleted=0", array($uuid)), ARRAY_A);
    }

    protected function getProjects()
    {
        return $this->pdb->get_results($this->pdb->prepare("SELECT * FROM dep_projects WHERE deleted=0"), ARRAY_A);
    }

    protected function getProjectBlogs($project)
    {
        $blogsTable = $this->pdb->base_prefix . 'blogs';
        return $this->pdb->get_results($this->pdb->prepare("SELECT $blogsTable.blog_id AS blog_id, $blogsTable.domain AS domain, $blogsTable.path AS path FROM dep_members, $blogsTable WHERE dep_members.deleted=0 AND dep_members.project_id=%d AND dep_members.blog_id = $blogsTable.blog_id", $project), ARRAY_A);
    }

    protected function getBlogsIds()
    {
        $blogs = $this->getBlogs();
        $ids = array();
        foreach($blogs as $b) {
            $ids[] = $b['blog_id'];
        }
        return $ids;
    }

    protected function getProjectBlogsIds($projectId)
    {
        $blogs = $this->getProjectBlogs($projectId);
        $ids = array();
        foreach($blogs as $b) {
            $ids[] = $b['blog_id'];
        }
        return $ids;
    }

    protected function getBlogsNotInProject($project)
    {
        $availableBlogs = array();
        $allBlogs = $this->getBlogs();
        $projectBlogs = $this->getProjectBlogs($project);
        
        if (sizeof($projectBlogs) == 0) {
            return $allBlogs;
        }

        $pBlogIds = array_map(function($e){
            return $e['blog_id'];
        }, $projectBlogs);
        
        foreach($allBlogs as $b) {
            if (!in_array($b['blog_id'], $pBlogIds)) {
                $availableBlogs[] = $b;
            }
        }
        return $availableBlogs;
    }

    protected function getBlog($id)
    {
        $blogsTable = $this->pdb->base_prefix . 'blogs';
        return $this->pdb->get_row($this->pdb->prepare("SELECT * FROM $blogsTable WHERE blog_id = %d ORDER BY domain ASC", array($id)), ARRAY_A);
    }

    protected function getBlogs()
    {
        $blogsTable = $this->pdb->base_prefix . 'blogs';
        return $this->pdb->get_results($this->pdb->prepare("SELECT blog_id, domain, path FROM $blogsTable ORDER BY domain ASC"), ARRAY_A);
    }

    protected function removeBlogFromProject($blogId, $projectId, $username=null, $password=null)
    {
        $this->pdb->query($this->pdb->prepare("UPDATE dep_members SET deleted=1 WHERE blog_id=%d and project_id=%d", $blogId, $projectId));
        return true;
    }

    protected function addBlogToProject($blogId, $projectId, $username=null, $password=null)
    {
        if (!$this->projectHasBlog($blogId, $projectId)) {
            $this->pdb->query($this->pdb->prepare("INSERT INTO dep_members (blog_id, project_id) VALUES (%d, %d)", $blogId, $projectId));
        }
        return true;
    }

    protected function projectHasBlog($blogId, $projectId)
    {
        $result = $this->pdb->get_results($this->pdb->prepare("SELECT project_id FROM dep_members WHERE blog_id=%d AND project_id=%d AND deleted=0", array($blogId, $projectId)), ARRAY_A);
        return sizeof($result) > 0;
    }

    public function actionCreateSnapshot()
    {
        $this->checkPerms();
        try {
            if ($this->createSnapshot($_REQUEST['projectid'], $_REQUEST['blogid'], $_REQUEST['name'], $_REQUEST['desc'], $_REQUEST['username'], $_REQUEST['password'])) {
                die(json_encode(array('ok' => 1)));
            }
        } catch (Exception $e){
            //$this->ajaxError($e->getMessage());
            die(json_encode(array('err' => $e->getMessage())));
        }
        
    }

    protected function createSnapshot($projectId, $blogId, $name, $desc, $username=null, $password=null)
    {
        // Validate name and description
        if (!preg_match('/\w+/', $name)) {
            throw new Exception("Please enter a name for this snapshot");
        }
        if (strlen($name) > 20) {
            throw new Exception("Your snapshot name must be 20 characters or less.");
        }
        if (preg_match('/[^a-zA-Z0-9\_\-\.]/', $name)) {
            throw new Exception("Your snapshot name can only contain characters a-z A-Z 0-9 and dashes, underscores and dots.");
        }
        if (!$desc) {
            throw new Exception("Please enter a description for this snapshot.");
        }
        $prec = $this->getProject($projectId);
        if (sizeof($prec) < 1) {
            throw new Exception("That project doesn't exist.");
        }
        return true;
    }

    protected function getTablePrefix($blogId)
    {
        return $this->pdb->base_prefix;
    }

    protected function copyFilesToDataDir($blogId, $dest){}

    protected function copyFilesFromDataDir($blogId, $src){}

    protected function doSnapshot($projectId, $blogId, $name, $desc)
    {
        $this->checkPerms();
        $opt = $this->getOptions();
        extract($opt, EXTR_OVERWRITE);
        $proj = $this->getProject($projectId);
        $dir = $datadir . $proj['dir'] . '/';

        // Check that project directory exists
        if (!is_dir($dir)) {
            throw new Exception("The directory " . $dir . " for this project doesn't exist for some reason. Did you delete it?");
        }

        // Make sure we are current from the remote
        DeployMintProjectTools::fetch($dir);

        // Make sure project directory is a working git directory
        if (!DeployMintProjectTools::isGitRepo($dir)) {
            throw new Exception("The directory $dir is not a valid git repository. The output we received is: $branchOut");
        }

        // Check if branch exists
        if (DeployMintProjectTools::branchExists($dir, $name)) {
            throw new Exception("A snapshot with the name $name already exists. Please choose another.");
        }
        $cout1 = DeployMintTools::mexec("$git checkout master 2>&1", $dir);
        //Before we do our initial commit we will get an error trying to checkout master because it doesn't exist.
        if (!preg_match("/(?:Switched to branch|Already on|error: pathspec 'master' did not match)/", $cout1)) {
            throw new Exception("We could not switch the git repository in $dir to 'master'. The output was: $cout1");
        }

        $prefix = $this->getTablePrefix($blogId);
        $prefixFile = $dir . 'deployData.txt';
        $fh2 = fopen($prefixFile, 'w');
        if (!fwrite($fh2, $prefix . ':' . microtime(true))) {
            throw new Exception("We could not write to deployData.txt in the directory $dir");
        }
        fclose($fh2);

        // Add the Media locations
        $this->copyFilesToDataDir($blogId, $dir);
        $add = DeployMintProjectTools::git('add .', $dir);

        $siteURLRes = $this->pdb->get_row($this->pdb->prepare("SELECT option_name, option_value FROM $prefix" . "options WHERE option_name = 'siteurl'"), ARRAY_A);
        $siteURL = $siteURLRes['option_value'];
        $desc = "Snapshot of: $siteURL\n" . $desc;

        $dumpErrs = array();
        foreach ($this->wpTables as $t) {
            $tableFile = $t . '.sql';
            $tableName = $prefix . $t;
            $path = $dir . $tableFile;
            $dbuser = DB_USER;
            $dbpass = DB_PASSWORD;
            $dbhost = DB_HOST;
            $dbname = DB_NAME;
            $o1 = DeployMintTools::mexec("$mysqldump --skip-comments --extended-insert --complete-insert --skip-comments -u $dbuser -p$dbpass -h $dbhost $dbname $tableName > $path 2>&1", $dir);
            if (preg_match('/\w+/', $o1)) {
                array_push($dumpErrs, $o1);
            } else {

                $grepOut = DeployMintTools::mexec("grep CREATE $path 2>&1");
                if (!preg_match('/CREATE/', $grepOut)) {
                    array_push($dumpErrs, "We could not create a valid table dump file for $tableName");
                } else {
                    $gitAddOut = DeployMintTools::mexec("$git add $tableFile 2>&1", $dir);
                    if (preg_match('/\w+/', $gitAddOut)) {
                        throw new Exception("We encountered an error running '$git add $tableFile' the error was: $gitAddOut");
                    }
                }
            }
        }
        if (sizeof($dumpErrs) > 0) {
            $resetOut = DeployMintProjectTools::git('reset --hard HEAD', $dir);
            if (!preg_match('/HEAD is now at/', $resetOut)) {
                throw new Exception("Errors occured during mysqldump and we could not revert the git repository in $dir back to it's original state using '$git reset --hard HEAD'. The output we got was: " . $resetOut);
            }

            throw new Exception("Errors occured during mysqldump: " . implode(', ', $dumpErrs));
        }
        $tmpfile = $datadir . microtime(true) . '.tmp';
        $fh = fopen($tmpfile, 'w');
        fwrite($fh, $desc);
        fclose($fh);
        global $current_user;
        get_currentuserinfo();
        
        $user_name = trim($current_user->user_firstname . ' ' . $current_user->user_lastname);
        if ($user_name == '') {
            $user_name = $current_user->display_name;
        }
        $commitUser = $user_name . ' <' . $current_user->user_email . '>';
        $commitOut2 = DeployMintProjectTools::git("commit --author=\"$commitUser\" -a -F \"$tmpfile\"", $dir);
        unlink($tmpfile);
        if (!preg_match('/files changed/', $commitOut2)) {
            throw new Exception("git commit failed. The output we got was: $commitOut2");
        }
        $brOut2 = DeployMintProjectTools::git('branch ' . $name, $dir);
        if (preg_match('/\w+/', $brOut2)) {
            throw new Exception("We encountered an error running '$git branch $name' the output was: $brOut2");
        }
        DeployMintProjectTools::git('push origin ' . $name, $dir);
        return true;
    }

    public function actionGetDeployOptions()
    {
        $this->checkPerms();
        try {
            $blogs = $this->getProjectBlogs($_REQUEST['projectid']);
            $snapshots = $this->getProjectSnapshots($_REQUEST['projectid']);
            die(json_encode(array('blogs'=>$blogs,'snapshots'=>$snapshots)));
        } catch (Exception $e){
            die(json_encode(array('err' => $e->getMessage())));
        }
    }


    protected function getProjectSnapshots($projectId)
    {
        $opt = $this->getOptions();
        extract($opt, EXTR_OVERWRITE);
        
        $project = $this->getProject($projectId);
        $dir = $datadir . $project['dir'];
        if (!is_dir($dir)) {
            throw new Exception("The directory $dir for this project does not exist.");
        }
        $fetch = DeployMintProjectTools::fetch($dir);
        $branches = DeployMintProjectTools::getAllBranches($dir);
        $bprefix = DeployMintProjectTools::remoteExists($dir) ? 'remotes/origin/' : '';
        $snapshots = array();
        for ($i = 0; $i < sizeof($branches); $i++) {
            if (preg_match('/\w+/', $branches[$i])) {
                $bname = $branches[$i];
                if ($bname == 'master') {
                    continue;
                }
                
                $dateOut = DeployMintTools::mexec("$git log -n 1 {$bprefix}{$bname} | grep Date 2>&1", $dir);
                $m = '';
                if (preg_match('/Date:\s+(.+)$/', $dateOut, &$m)) {
                    $ctime = strtotime($m[1]);
                    $date = $m[1];
                    array_push($snapshots, array('name' => $branches[$i], 'created' => $date, 'ctime' => $ctime));
                }
            } else {
                unset($branches[$i]);
            }
        }
        if (sizeof($snapshots) > 0) {

            function ctimeSort($b, $a)
            {
                if ($a['ctime'] == $b['ctime']) {
                    return 0;
                } return ($a['ctime'] < $b['ctime']) ? -1 : 1;
            }

            usort($snapshots, 'ctimeSort');
        }
        return $snapshots;
    }

    public function actionDeploySnapshot()
    {
        $this->checkPerms();
        try {
            if ($this->deploySnapshot($_REQUEST['name'], $_REQUEST['blogid'], $_REQUEST['projectid'], $_REQUEST['username'], $_REQUEST['password'])) {
                die(json_encode(array('ok' => 1)));
            }
        } catch (Exception $e){
            //$this->ajaxError($e->getMessage());
            die(json_encode(array('err' => $e->getMessage())));
        }
    }

    protected function deploySnapshot($snapshot, $blogId, $projectId, $username=null, $password=null)
    {
        return true;
    }

    protected function doDeploySnapshot($name, $blogid, $pid)
    {
        $this->checkPerms();
        $opt = $this->getOptions(true, true);
        extract($opt, EXTR_OVERWRITE);
        $leaveComments = true; //$_POST['leaveComments'];

        if (!preg_match('/\w+/', $name)) {
            throw new Exception("Please select a snapshot to deploy.");
        }
        $prec = $this->pdb->get_results($this->pdb->prepare("SELECT * FROM dep_projects WHERE id=%d AND deleted=0", $pid), ARRAY_A);
        if (sizeof($prec) < 1) {
            throw new Exception("That project doesn't exist.");
        }
        $proj = $prec[0];
        $dir = $datadir . $proj['dir'] . '/';
        $mexists = $this->pdb->get_results($this->pdb->prepare("SELECT blog_id FROM dep_members WHERE blog_id=%d AND project_id=%d AND deleted=0", $blogid, $pid), ARRAY_A);
        if (sizeof($mexists) < 1) {
            throw new Exception("That blog doesn't exist or is not a member of this project. Please select a valid blog to deploy to.");
        }
        if (!is_dir($dir)) {
            throw new Exception("The directory " . $dir . " for this project doesn't exist for some reason. Did you delete it?");
        }
        $co0 = DeployMintTools::mexec("$git fetch origin ; $git checkout -t origin/$name 2>&1", $dir);
        $co1 = DeployMintTools::mexec("$git checkout $name 2>&1", $dir);
        if (!preg_match('/(?:Switched|Already)/', $co1)) {
            throw new Exception("We could not find snapshot $name in the git repository. The error was: $co1");
        }
        $destTablePrefix = $this->getTablePrefix($blogid);
        $optionsToRestore = array('siteurl', 'home', 'upload_path');
        if ($opt['preserveBlogName']) {
            $optionsToRestore[] = 'blogname';
        }
        $res3 = $this->pdb->get_results($this->pdb->prepare("SELECT option_name, option_value FROM {$destTablePrefix}options WHERE option_name IN ('" . implode("','", $optionsToRestore) . "')"), ARRAY_A);
        if (sizeof($res3) < 1) {
            throw new Exception("We could not find the data we need for the blog you're trying to deploy to.");
        }
        $options = array();
        for ($i = 0; $i < sizeof($res3); $i++) {
            $options[$res3[$i]['option_name']] = $res3[$i]['option_value'];
        }

        // Update the Media folder
        $this->copyFilesFromDataDir($blogid, $dir);
        
        $fh = fopen($dir . 'deployData.txt', 'r');
        $deployData = fread($fh, 100);
        $depDat = explode(':', $deployData);
        $sourceTablePrefix = $depDat[0];
        if (!$sourceTablePrefix) {
            throw new Exception("We could not read the table prefix from $dir/deployData.txt");
        }
        $dbuser = DB_USER;
        $dbpass = DB_PASSWORD;
        $dbhost = DB_HOST;
        $dbname = DB_NAME;
        $slurp1 = DeployMintTools::mexec("cat *.sql | $mysql -u $dbuser -p$dbpass -h $dbhost $temporaryDatabase ", $dir);
        if (preg_match('/\w+/', $slurp1)) {
            throw new Exception("We encountered an error importing the data files from snapshot $name into database $temporaryDatabase $dbuser:$dbpass@$dbhost. The error was: " . substr($slurp1, 0, 1000));
        }
        $dbh = mysql_connect($dbhost, $dbuser, $dbpass, true);
        if (mysql_error($dbh)) {
            throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
        }
        if (!mysql_select_db($temporaryDatabase, $dbh)) {
            throw new Exception("Could not select temporary database $temporaryDatabase : " . mysql_error($dbh));
        }
        $curdbres = mysql_query("SELECT DATABASE()", $dbh);
        $curdbrow = mysql_fetch_array($curdbres, MYSQL_NUM);
        if (mysql_error($dbh)) {
            throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
        }
        $destSiteURL = $options['siteurl'];
        $res4 = mysql_query("SELECT option_value FROM {$sourceTablePrefix}options WHERE option_name='siteurl'", $dbh);
        if (mysql_error($dbh)) {
            throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
        }
        if (!$res4) {
            throw new Exception("We could not get the siteurl from the database we're about to deploy. That could mean that we could not create the DB or the import failed.");
        }
        $row = mysql_fetch_array($res4, MYSQL_ASSOC);
        if (mysql_error($dbh)) {
            throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
        }
        if (!$row) {
            throw new Exception("We could not get the siteurl from the database we're about to deploy. That could mean that we could not create the DB or the import failed. (2)");
        }
        $sourceSiteURL = $row['option_value'];
        if (!$sourceSiteURL) {
            throw new Exception("We could not get the siteurl from the database we're about to deploy. That could mean that we could not create the DB or the import failed. (3)");
        }
        $destHost = preg_replace('/^https?:\/\/([^\/]+.*)$/i', '$1', $destSiteURL);
        $sourceHost = preg_replace('/^https?:\/\/([^\/]+.*)$/i', '$1', $sourceSiteURL);
        foreach ($options as $oname => $val) {
            mysql_query("UPDATE {$sourceTablePrefix}options set option_value='" . mysql_real_escape_string($val) . "' WHERE option_name='" . mysql_real_escape_string($oname) . "'", $dbh);
            if (mysql_error($dbh)) {
                throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
            }
        }
        $res5 = mysql_query("SELECT ID, post_content, guid FROM {$sourceTablePrefix}posts", $dbh);
        if (mysql_error($dbh)) {
            throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
        }
        
        mysql_query("UPDATE {$sourceTablePrefix}posts SET post_content=REPLACE(post_content, '$sourceHost', '$destHost'), guid=REPLACE(guid, '$sourceHost', '$destHost')", $dbh);
        if (mysql_error($dbh)) {
            throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
        }

        mysql_query("UPDATE {$temporaryDatabase}.{$sourceTablePrefix}options SET option_name='{$destTablePrefix}user_roles' WHERE option_name='{$sourceTablePrefix}user_roles'", $dbh);
        if (mysql_error($dbh)) {
            throw new Exception("A database error occured while updating the user_roles option in the destination database: " . substr(mysql_error($dbh), 0, 200));
        }

        if ($leaveComments) {
            //Delete comments from DB we're deploying
            mysql_query("DELETE FROM {$sourceTablePrefix}comments", $dbh);
            if (mysql_error($dbh)) {
                throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
            }
            mysql_query("DELETE FROM {$sourceTablePrefix}commentmeta", $dbh);
            if (mysql_error($dbh)) {
                throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
            }
            //Bring comments across from live (destination) DB
            mysql_query("INSERT INTO $temporaryDatabase.{$sourceTablePrefix}comments SELECT * FROM $dbname.{$destTablePrefix}comments", $dbh);
            if (mysql_error($dbh)) {
                throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
            }
            mysql_query("INSERT INTO $temporaryDatabase.{$sourceTablePrefix}commentmeta SELECT * FROM $dbname.{$destTablePrefix}commentmeta", $dbh);
            if (mysql_error($dbh)) {
                throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
            }

            //Then remap comments to posts based on the "slug" which is the post_name
            $res6 = mysql_query("SELECT dp.post_name AS destPostName, dp.ID AS destID, sp.post_name AS sourcePostName, sp.ID AS sourceID FROM $dbname.{$destTablePrefix}posts AS dp, $temporaryDatabase.{$sourceTablePrefix}posts AS sp WHERE dp.post_name = sp.post_name", $dbh);
            if (mysql_error($dbh)) {
                throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
            }
            if (!$res6) {
                throw new Exception("DB error creating maps betweeb post slugs: " . mysql_error($dbh));
            }
            $pNameMap = array();
            while ($row = mysql_fetch_array($res6, MYSQL_ASSOC)) {
                $pNameMap[$row['destID']] = $row['sourceID'];
            }

            $res10 = mysql_query("SELECT comment_ID, comment_post_ID FROM {$sourceTablePrefix}comments", $dbh);
            if (mysql_error($dbh)) {
                throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
            }
            while ($row = mysql_fetch_array($res10, MYSQL_ASSOC)) {
                //If a post exists in the source with the same slug as the destination, then associate the destination's comments with that post.
                if (array_key_exists($row['comment_post_ID'], $pNameMap)) {
                    mysql_query("UPDATE $sourceTablePrefix" . "comments set comment_post_ID=" . $pNameMap[$row['comment_post_ID']] . " WHERE comment_ID=" . $row['comment_ID'], $dbh);
                    if (mysql_error($dbh)) {
                        throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
                    }
                } else { //Otherwise delete the comment because it is associated with a post on the destination which does not exist in the source we're about to deploy
                    mysql_query("DELETE FROM {$sourceTablePrefix}comments WHERE comment_ID=" . $row['comment_ID'], $dbh);
                    if (mysql_error($dbh)) {
                        throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
                    }
                }
            }
            $res11 = mysql_query("SELECT ID FROM {$sourceTablePrefix}posts", $dbh);
            if (mysql_error($dbh)) {
                throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
            }
            while ($row = mysql_fetch_array($res11, MYSQL_ASSOC)) {
                $res12 = mysql_query("SELECT count(*) AS cnt FROM {$sourceTablePrefix}comments WHERE comment_post_ID=" . $row['ID'], $dbh);
                if (mysql_error($dbh)) {
                    throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
                }
                $row5 = mysql_fetch_array($res12, MYSQL_ASSOC);
                mysql_query("UPDATE {$sourceTablePrefix}posts set comment_count=" . $row5['cnt'] . " WHERE ID=" . $row['ID'], $dbh);
                if (mysql_error($dbh)) {
                    throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
                }
            }
        }
        if (!$backupDisabled) {
            if (!mysql_select_db($dbname, $dbh)) {
                throw new Exception("Could not select database $dbname : " . mysql_error($dbh));
            }
            $curdbres = mysql_query("SELECT DATABASE()", $dbh);
            $curdbrow = mysql_fetch_array($curdbres, MYSQL_NUM);
            if (mysql_error($dbh)) {
                throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
            }
            $res14 = mysql_query("SHOW TABLES", $dbh);
            if (mysql_error($dbh)) {
                throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
            }
            $allTables = array();
            while ($row = mysql_fetch_array($res14, MYSQL_NUM)) {
                array_push($allTables, $row[0]);
            }
            if (mysql_error($dbh)) {
                throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
            }
            if (!mysql_select_db($backupDatabase, $dbh)) {
                throw new Exception("Could not select backup database $backupDatabase : " . mysql_error($dbh));
            }
            error_log("BACKUPDB: $backupDatabase");
            $curdbres = mysql_query("select DATABASE()", $dbh);
            $curdbrow = mysql_fetch_array($curdbres, MYSQL_NUM);
            ;

            $this->emptyDatabase($backupDatabase, $dbh);
            foreach ($allTables as $t) {
                #We're taking across all tables including dep_ tables just so we have a backup. We won't deploy dep_ tables though
                mysql_query("CREATE TABLE $backupDatabase.$t LIKE $dbname.$t", $dbh);
                if (mysql_error($dbh)) {
                    throw new Exception("Could not create table $t in backup DB: " . mysql_error($dbh));
                }
                mysql_query("INSERT INTO $t SELECT * FROM $dbname.$t", $dbh);
                if (mysql_error($dbh)) {
                    throw new Exception("Could not copy table $t from $dbname database: " . mysql_error($dbh));
                }
            }
            mysql_query("CREATE TABLE dep_backupdata (name varchar(20) NOT NULL, val varchar(255) default '')", $dbh);
            if (mysql_error($dbh)) {
                throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
            }
            mysql_query("INSERT INTO dep_backupdata VALUES ('blogid', '" . $blogid . "')", $dbh);
            if (mysql_error($dbh)) {
                throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
            }
            mysql_query("INSERT INTO dep_backupdata VALUES ('prefix', '" . $destTablePrefix . "')", $dbh);
            if (mysql_error($dbh)) {
                throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
            }
            mysql_query("INSERT INTO dep_backupdata VALUES ('deployTime', '" . microtime(true) . "')", $dbh);
            if (mysql_error($dbh)) {
                throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
            }
            mysql_query("INSERT INTO dep_backupdata VALUES ('deployFrom', '" . $sourceHost . "')", $dbh);
            if (mysql_error($dbh)) {
                throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
            }
            mysql_query("INSERT INTO dep_backupdata VALUES ('deployTo', '" . $destHost . "')", $dbh);
            if (mysql_error($dbh)) {
                throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
            }
            mysql_query("INSERT INTO dep_backupdata VALUES ('snapshotName', '" . $name . "')", $dbh);
            if (mysql_error($dbh)) {
                throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
            }
            mysql_query("INSERT INTO dep_backupdata VALUES ('projectID', '" . $pid . "')", $dbh);
            if (mysql_error($dbh)) {
                throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
            }
            mysql_query("INSERT INTO dep_backupdata VALUES ('projectName', '" . $proj['name'] . "')", $dbh);
            if (mysql_error($dbh)) {
                throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
            }
        }

        if (!mysql_select_db($temporaryDatabase, $dbh)) {
            throw new Exception("Could not select temporary database $temporaryDatabase : " . mysql_error($dbh));
        }
        $curdbres = mysql_query("SELECT DATABASE()", $dbh);
        $curdbrow = mysql_fetch_array($curdbres, MYSQL_NUM);

        $renames = array();
        foreach ($this->wpTables as $t) {
            array_push($renames, "$dbname.{$destTablePrefix}$t TO $temporaryDatabase.old_$t, $temporaryDatabase.{$sourceTablePrefix}$t TO $dbname.$destTablePrefix" . $t);
        }
        $stime = microtime(true);
        mysql_query("RENAME TABLE " . implode(", ", $renames), $dbh);
        $lockTime = sprintf('%.4f', microtime(true) - $stime);
        if (mysql_error($dbh)) {
            throw new Exception("A database error occured: " . substr(mysql_error($dbh), 0, 200));
        }
        if ($temporaryDatabaseCreated) {
            mysql_query("DROP DATABASE $temporaryDatabase", $dbh);
        } else {
            $this->emptyDatabase($temporaryDatabase, $dbh);
        }
        if (mysql_error($dbh)) {
            throw new Exception("A database error occured trying to drop an old temporary database, but the deployment completed. Error was: " . substr(mysql_error($dbh), 0, 200));
        }
        if (!$backupDisabled) {
            $this->deleteOldBackupDatabases();
        }
        return true;
        //die(json_encode(array('ok' => 1, 'lockTime' => $lockTime)));
    }

    protected function emptyDatabase($database, $connection)
    {
        if ($result = mysql_query("SHOW TABLES IN $database", $connection)) {
            while ($row = mysql_fetch_array($result, MYSQL_NUM)) {
                mysql_query('DROP TABLE IF EXISTS ' . $row[0], $connection);
            }
        }
    }



    public function ajax_deploy_callback()
    {
        $this->checkPerms();
        $fromid = $_POST['deployFrom'];
        $toid = $_POST['deployTo'];
        $msgs = array();
        $fromBlog = $this->pdb->get_results($this->pdb->prepare("SELECT blog_id, domain, path FROM wp_blogs WHERE blog_id=%d", $fromid), ARRAY_A);
        $toBlog = $this->pdb->get_results($this->pdb->prepare("SELECT blog_id, domain, path FROM wp_blogs WHERE blog_id=%d", $toid), ARRAY_A);
        if (sizeof($fromBlog) != 1) {
            die("We could not find the blog you're deploying from.");
        }
        if (sizeof($toBlog) != 1) {
            die("We could not find the blog you're deploying to.");
        }
        $fromPrefix = '';
        $toPrefix = '';

        if ($fromid == 1) {
            $fromPrefix = 'wp_';
        } else {
            $fromPrefix = 'wp_' . $fromid . '_';
        }
        if ($toid == 1) {
            $toPrefix = 'wp_';
        } else {
            $toPrefix = 'wp_' . $toid . '_';
        }
        $t_fromPosts = $fromPrefix . 'posts';
        $t_toPosts = $toPrefix . 'posts';
        $fromPostTotal = $this->pdb->get_results($this->pdb->prepare("SELECT count(*) AS cnt FROM $t_fromPosts WHERE post_status='publish'", $fromid), ARRAY_A);
        $toPostTotal = $this->pdb->get_results($this->pdb->prepare("SELECT count(*) AS cnt FROM $t_toPosts WHERE post_status='publish'", $toid), ARRAY_A);
        $fromNewestPost = $this->pdb->get_results($this->pdb->prepare("SELECT post_title FROM $t_fromPosts WHERE post_status='publish' ORDER BY post_modified DESC LIMIT 1", $fromid), ARRAY_A);
        $toNewestPost = $this->pdb->get_results($this->pdb->prepare("SELECT post_title FROM $t_toPosts WHERE post_status='publish' ORDER BY post_modified DESC LIMIT 1", $toid), ARRAY_A);
        die(json_encode(array(
                    'fromid' => $fromid,
                    'toid' => $toid,
                    'fromDomain' => $fromBlog[0]['domain'],
                    'fromPostTotal' => $fromPostTotal[0]['cnt'],
                    'fromNewestPostTitle' => $fromNewestPost[0]['post_title'],
                    'toDomain' => $toBlog[0]['domain'],
                    'toPostTotal' => $toPostTotal[0]['cnt'],
                    'toNewestPostTitle' => $toNewestPost[0]['post_title']
                )));
    }

    public function ajax_updateSnapDesc_callback()
    {
        $this->checkPerms();
        $opt = $this->getOptions();
        extract($opt, EXTR_OVERWRITE);
        $pid = $_POST['projectid'];
        $snapname = $_POST['snapname'];
        $res = $this->getProject($pid);
        $dir = $res['dir'];
        $fulldir = $datadir . $dir;
        $bprefix = DeployMintProjectTools::remoteExists($dir) ? 'remotes/origin/' : '';
        $logOut = DeployMintTools::mexec("$git log -n 1 ${bprefix}${snapname} 2>&1", $fulldir);
        $logOut = preg_replace('/^commit [0-9a-fA-F]+[\r\n]+/', '', $logOut);
        if (preg_match('/fatal: bad default revision/', $logOut)) {
            die(json_encode(array('desc' => '')));
        }
        die(json_encode(array('desc' => $logOut)));
    }

    public static function ajax_undoDeploy_callback()
    {
        $this->checkPerms();
        $opt = $this->getOptions(true);
        extract($opt, EXTR_OVERWRITE);
        $sourceDBName = $_POST['dbname'];
        $dbuser = DB_USER;
        $dbpass = DB_PASSWORD;
        $dbhost = DB_HOST;
        $dbname = DB_NAME;
        $dbh = mysql_connect($dbhost, $dbuser, $dbpass, true);
        if (mysql_error($dbh)) {
            $this->ajaxError("A database error occured: " . substr(mysql_error($dbh), 0, 200));
        }
        mysql_select_db($sourceDBName, $dbh);
        if (mysql_error($dbh)) {
            $this->ajaxError("A database error occured: " . substr(mysql_error($dbh), 0, 200));
        }
        $res1 = mysql_query("show tables", $dbh);
        if (mysql_error($dbh)) {
            $this->ajaxError("A database error occured: " . substr(mysql_error($dbh), 0, 200));
        }
        $allTables = array();
        while ($row1 = mysql_fetch_array($res1, MYSQL_NUM)) {
            if (!preg_match('/^dep_/', $row1[0])) {
                array_push($allTables, $row1[0]);
            }
        }
        $renames = array();
        foreach ($allTables as $t) {
            array_push($renames, "$dbname.$t TO $temporaryDatabase.$t, $sourceDBName.$t TO $dbname.$t");
        }
        $stime = microtime(true);
        mysql_query("RENAME TABLE " . implode(', ', $renames), $dbh);
        if (mysql_error($dbh)) {
            $this->ajaxError("A database error occured: " . substr(mysql_error($dbh), 0, 200));
        }
        $lockTime = sprintf('%.4f', microtime(true) - $stime);
        if ($temporaryDatabaseCreated) {
            mysql_query("DROP DATABASE $temporaryDatabase", $dbh);
        } else {
            $this->emptyDatabase($temporaryDatabase, $dbh);
        }
        foreach ($allTables as $t) {
            mysql_query("CREATE TABLE $sourceDBName.$t LIKE $dbname.$t", $dbh);
            if (mysql_error($dbh)) {
                $this->ajaxError("A database error occured trying to recreate the backup database, but the deployment completed. Error: " . substr(mysql_error($dbh), 0, 200));
            }
            mysql_query("INSERT INTO $sourceDBName.$t SELECT * FROM $dbname.$t", $dbh);
            if (mysql_error($dbh)) {
                $this->ajaxError("A database error occured trying to recreate the backup database, but the deployment completed. Error: " . substr(mysql_error($dbh), 0, 200));
            }
        }
        if (mysql_error($dbh)) {
            $this->ajaxError("A database error occured (but the revert was completed!): " . substr(mysql_error($dbh), 0, 200));
        }
        die(json_encode(array('ok' => 1, 'lockTime' => $lockTime)));
    }

    public function ajax_deleteBackups_callback()
    {
        $this->checkPerms();
        $dbuser = DB_USER;
        $dbpass = DB_PASSWORD;
        $dbhost = DB_HOST;
        $dbname = DB_NAME;
        $dbh = mysql_connect($dbhost, $dbuser, $dbpass, true);
        if (mysql_error($dbh)) {
            $this->ajaxError("A database error occured: " . substr(mysql_error($dbh), 0, 200));
        }
        $toDel = $_POST['toDel'];
        for ($i = 0; $i < sizeof($toDel); $i++) {
            mysql_query("drop database " . $toDel[$i], $dbh);
            if (mysql_error($dbh)) {
                $this->ajaxError("Could not drop database " . $toDel[$i] . ". Error: " . mysql_error($dbh));
            }
        }
        die(json_encode(array('ok' => 1)));
    }

    public function ajax_updateOptions_callback()
    {
        $this->checkPerms();
        $defaultOptions = $this->getDefaultOptions();
        $opt = array_merge($this->getOptions(), $_POST);
        foreach($opt as $key=>$value) {
            $opt[$key] = trim($value);
        }
        extract($opt, EXTR_OVERWRITE);
        if (!preg_match('/\/$/', $datadir)) {
            $datadir .= '/';
        }
        $errs = array();
        if (!($git && $mysql && $mysqldump && $rsync && $datadir)) {
            $errs[] = "You must specify a value for all options.";
        }
        if (!preg_match('/^\d+$/', $numBackups)) {
            if ($backupDisabled) {
                $numBackups = $defaultOptions['numBackups'];
            } else {
                $errs[] = "The number of backups you specify must be a number or 0 to keep all bacukps.";
            }
        }
        $preserveBlogName = trim($_POST['preserveBlogName']);
        if ($preserveBlogName != 0 && $preserveBlogName != 1) {
            $errs[] = "Invalid value for preserveBlogName. Expected 1 or 0. Received $preserveBlogName";
        }
        if (sizeof($errs) > 0) {
            die(json_encode(array('errs' => $errs)));
        }
        if (!file_exists($mysql)) {
            $errs[] = "The file '$mysql' specified for mysql doesn't exist.";
        }
        if (!file_exists($mysqldump)) {
            $errs[] = "The file '$mysqldump' specified for mysqldump doesn't exist.";
        }
        if (!file_exists($rsync)) {
            $errs[] = "The file '$rsync' specified for rsync doesn't exist.";
        }        
        if (!file_exists($git)) {
            $errs[] = "The file '$git' specified for git doesn't exist.";
        }
        if (!is_dir($datadir)) {
            $errs[] = "The directory '$datadir' specified as the data directory doesn't exist.";
        } else {
            $fh = fopen($datadir . '/test.tmp', 'w');
            if (!fwrite($fh, 't')) {
                $errs[] = "The directory $datadir is not writeable.";
            }
            fclose($fh);
            unlink($datadir . '/test.tmp');
        }
        if (sizeof($errs) > 0) {
            die(json_encode(array('errs' => $errs)));
        } else {
            $options = array(
                'git' => $git,
                'mysql' => $mysql,
                'mysqldump' => $mysqldump,
                'rsync' => $rsync,
                'datadir' => $datadir,
                'numBackups' => $numBackups,
                'temporaryDatabase' => $temporaryDatabase,
                'backupDisabled' => $backupDisabled,
                'backupDatabase' => $backupDatabase,
                'preserveBlogName' => $preserveBlogName
            );
            $this->updateOptions($options);
            die(json_encode(array('ok' => 1)));
        }
    }

    

    private function deleteOldBackupDatabases()
    {
        $this->checkPerms();
        $opt = $this->getOptions();
        extract($opt, EXTR_OVERWRITE);
        if ($numBackups < 1) {
            return;
        }
        $dbuser = DB_USER;
        $dbpass = DB_PASSWORD;
        $dbhost = DB_HOST;
        $dbname = DB_NAME;
        $dbh = mysql_connect($dbhost, $dbuser, $dbpass, true);
        mysql_select_db($dbname, $dbh);
        $res1 = mysql_query("show databases", $dbh);
        if (mysql_error($dbh)) {
            error_log("A database error occured: " . mysql_error($dbh));
            return;
        }
        $dbs = array();
        while ($row1 = mysql_fetch_array($res1, MYSQL_NUM)) {
            $dbPrefix = ($backupDatabase == '') ? 'depbak' : $backupDatabase;
            if (preg_match('/^' . $dbPrefix . '__/', $row1[0])) {
                $dbname = $row1[0];
                $res2 = mysql_query("SELECT val FROM $dbname.dep_backupdata WHERE name='deployTime'", $dbh);
                if (mysql_error($dbh)) {
                    error_log("Could not get deployment time for $dbname database");
                    return;
                }
                $row2 = mysql_fetch_array($res2, MYSQL_ASSOC);
                if ($row2 && $row2['val']) {
                    array_push($dbs, array('dbname' => $dbname, 'deployTime' => $row2['val']));
                } else {
                    error_log("Could not get deployment time for backup database $dbname");
                    return;
                }
            }
        }
        if (sizeof($dbs) > $numBackups) {

            function deployTimeSort($a, $b)
            {
                if ($a['deployTime'] == $b['deployTime']) {
                    return 0;
                } return ($a['deployTime'] < $b['deployTime']) ? -1 : 1;
            }

            usort($snapshots, 'deployTimeSort');
            for ($i = 0; $i < sizeof($dbs) - $numBackups; $i++) {
                $db = $dbs[$i];
                $dbToDrop = $db['dbname'];
                mysql_query("drop database $dbToDrop", $dbh);
                if (mysql_error($dbh)) {
                    error_log("Could not drop backup database $dbToDrop when deleting old backup databases:" . mysql_error($dbh));
                    return;
                }
            }
        }
    }

    
    private function showMessage($message, $errormsg = false)
    {
        if ($errormsg) {
            echo '<div id="message" class="error">';
        } else {
            echo '<div id="message" class="updated fade">';
        }

        echo "<p><strong>$message</strong></p></div>";
    }

    public function showFillOptionsMessage()
    {
        $this->showMessage("You need to visit the options page for \"DeployMint\" and configure all options including a data directory that is writable by your web server.", true);
    }
}
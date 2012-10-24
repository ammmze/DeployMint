<?php

class DeployMintMultiSite extends DeployMintAbstract
{

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

    protected function doSnapshot($pid, $blogid, $name, $desc)
    {
        $opt = $this->getOptions();
        extract($opt, EXTR_OVERWRITE);
        
        $prec = $this->getProject($projectId);
        $proj = $prec[0];
        $dir = $datadir . $proj['dir'] . '/';
        $mexists = $this->pdb->get_results($this->pdb->prepare("SELECT blog_id FROM dep_members WHERE blog_id=%d AND project_id=%d AND deleted=0", $blogid, $pid), ARRAY_A);
        if (sizeof($mexists) < 1) {
            $this->ajaxError("That blog doesn't exist or is not a member of this project.");
        }
        if (!is_dir($dir)) {
            $this->ajaxError("The directory " . $dir . " for this project doesn't exist for some reason. Did you delete it?");
        }
        $branchOut = $this->mexec("$git branch 2>&1", $dir);
        if (preg_match('/fatal/', $branchOut)) {
            $this->ajaxError("The directory $dir is not a valid git repository. The output we received is: $branchOut");
        }
        $branches = preg_split('/[\r\n\s\t\*]+/', $branchOut);
        $bdup = array();
        for ($i = 0; $i < sizeof($branches); $i++) {
            $bdup[$branches[$i]] = 1;
        }
        if (array_key_exists($name, $bdup)) {
            $this->ajaxError("A snapshot with the name $name already exists. Please choose another.");
        }
        $cout1 = $this->mexec("$git checkout master 2>&1", $dir);
        //Before we do our initial commit we will get an error trying to checkout master because it doesn't exist.
        if (!preg_match("/(?:Switched to branch|Already on|error: pathspec 'master' did not match)/", $cout1)) {
            $this->ajaxError("We could not switch the git repository in $dir to 'master'. The output was: $cout1");
        }
        $prefix = "";
        if ($blogid == 1) {
            $prefix = $this->pdb->base_prefix;
        } else {
            $prefix = $this->pdb->base_prefix . $blogid . '_';
        }
        $prefixFile = $dir . 'deployData.txt';
        $fh2 = fopen($prefixFile, 'w');
        if (!fwrite($fh2, $prefix . ':' . microtime(true))) {
            $this->ajaxError("We could not write to deployData.txt in the directory $dir");
        }
        fclose($fh2);
        $prefixOut = $this->mexec("$git add deployData.txt 2>&1", $dir);

        // Add the Media locations
        $files = $this->mexec("$rsync -r -d " . WP_CONTENT_DIR . "/blogs.dir/$blogid/* $dir" . "blogs.dir/");
        $filesOut = $this->mexec("$git add blogs.dir/ 2>&1", $dir);

        $siteURLRes = $this->pdb->get_results($this->pdb->prepare("SELECT option_name, option_value FROM $prefix" . "options WHERE option_name = 'siteurl'"), ARRAY_A);
        $siteURL = $siteURLRes[0]['option_value'];
        $desc = "Snapshot of: $siteURL\n" . $desc;

        $dumpErrs = array();
        foreach ($this->$wpTables as $t) {
            $tableFile = $t . '.sql';
            $tableName = $prefix . $t;
            $path = $dir . $tableFile;
            $dbuser = DB_USER;
            $dbpass = DB_PASSWORD;
            $dbhost = DB_HOST;
            $dbname = DB_NAME;
            $o1 = $this->mexec("$mysqldump --skip-comments --extended-insert --complete-insert --skip-comments -u $dbuser -p$dbpass -h $dbhost $dbname $tableName > $path 2>&1", $dir);
            if (preg_match('/\w+/', $o1)) {
                array_push($dumpErrs, $o1);
            } else {

                $grepOut = $this->mexec("grep CREATE $path 2>&1");
                if (!preg_match('/CREATE/', $grepOut)) {
                    array_push($dumpErrs, "We could not create a valid table dump file for $tableName");
                } else {
                    $gitAddOut = $this->mexec("$git add $tableFile 2>&1", $dir);
                    if (preg_match('/\w+/', $gitAddOut)) {
                        $this->ajaxError("We encountered an error running '$git add $tableFile' the error was: $gitAddOut");
                    }
                }
            }
        }
        if (sizeof($dumpErrs) > 0) {
            $resetOut = $this->mexec("$git reset --hard HEAD 2>&1", $dir);
            if (!preg_match('/HEAD is now at/', $resetOut)) {
                $this->ajaxError("Errors occured during mysqldump and we could not revert the git repository in $dir back to it's original state using '$git reset --hard HEAD'. The output we got was: " . $resetOut);
            }

            $this->ajaxError("Errors occured during mysqldump: " . implode(', ', $dumpErrs));
        }
        $tmpfile = $datadir . microtime(true) . '.tmp';
        $fh = fopen($tmpfile, 'w');
        fwrite($fh, $desc);
        fclose($fh);
        global $current_user;
        get_currentuserinfo();
        $commitUser = $current_user->user_firstname . ' ' . $current_user->user_lastname . ' <' . $current_user->user_email . '>';
        $commitOut2 = $this->mexec("$git commit --author=\"$commitUser\" -a -F \"$tmpfile\" 2>&1", $dir);
        unlink($tmpfile);
        if (!preg_match('/files changed/', $commitOut2)) {
            $this->ajaxError("git commit failed. The output we got was: $commitOut2");
        }
        $brOut2 = $this->mexec("$git branch $name 2>&1 ", $dir);
        if (preg_match('/\w+/', $brOut2)) {
            $this->ajaxError("We encountered an error running '$git branch $name' the output was: $brOut2");
        }
        die(json_encode(array('ok' => 1)));
    }
}